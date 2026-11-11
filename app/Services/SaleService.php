<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sales are financial documents. Creation, voiding and returns each run in a
 * single DB transaction together with their stock movements and customer
 * balances, so a partial write can never leave money, receivables and
 * inventory out of sync. Completed sales are never edited — voids and
 * returns are separate, auditable records.
 */
class SaleService
{
    public function __construct(
        private StockService $stock,
        private AuditLogger $audit,
        private NotificationService $notify,
    ) {}

    public function create(User $cashier, array $data): Sale
    {
        return DB::transaction(function () use ($cashier, $data) {
            $productIds = collect($data['items'])->pluck('product_id')->unique()->values();

            // Tenant scope guarantees only THIS tenant's products can be loaded.
            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                abort(422, 'One or more products are invalid.');
            }

            $priceType = $data['price_type'] ?? 'retail';

            $lines = collect($data['items'])->map(function (array $item) use ($products, $priceType) {
                /** @var Product $product */
                $product = $products->get($item['product_id']);

                if (! $product->is_active) {
                    abort(422, "Product [{$product->name}] is not sellable.");
                }

                // Prices are ALWAYS re-read from the database server-side.
                $unitPrice = (float) ($priceType === 'wholesale' && $product->wholesale_price
                    ? $product->wholesale_price
                    : $product->selling_price);

                return [
                    'product'    => $product,
                    'quantity'   => (int) $item['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_cost'  => (float) $product->cost_price, // COGS snapshot
                    'line_total' => round($unitPrice * (int) $item['quantity'], 2),
                ];
            });

            $subtotal = round($lines->sum('line_total'), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);

            if ($discount < 0 || $discount > $subtotal) {
                abort(422, 'Discount must be between 0 and the sale subtotal.');
            }

            // Tax rate comes from the tenant's own settings — never the client.
            $tax = 0.0;
            if (! empty($data['taxable'])) {
                $rate = (float) (BusinessSetting::query()->first()?->tax_rate ?? 0);
                $tax  = round(($subtotal - $discount) * $rate / 100, 2);
            }

            $total      = round($subtotal - $discount + $tax, 2);
            $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);
            $owed       = round($total - $amountPaid, 2);

            // ---- Customer / credit handling --------------------------------
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::whereKey((int) $data['customer_id'])->lockForUpdate()->first();
                if (! $customer) {
                    abort(422, 'Invalid customer.');
                }
            }

            if ($owed > 0.0001) {
                // Under-payment is a CREDIT sale: a customer is mandatory and
                // the credit limit (when set) may not be exceeded.
                if (! $customer) {
                    abort(422, 'A customer is required for a credit sale.');
                }
                // A limit of 0 / null means "no limit configured".
                if ((float) ($customer->credit_limit ?? 0) > 0
                    && (float) $customer->balance + $owed > (float) $customer->credit_limit + 0.0001) {
                    abort(422, "This sale exceeds {$customer->name}'s credit limit.");
                }
                if ($customer->status === 'blocked') {
                    abort(422, "{$customer->name} is blocked from credit purchases.");
                }
            } elseif ($amountPaid <= 0) {
                abort(422, 'Amount paid cannot be zero for a cash sale.');
            }

            // Tenant-scoped sequential receipt number with row locks (race-safe).
            $receiptNo = ((int) Sale::where('tenant_id', $cashier->tenant_id)
                ->lockForUpdate()
                ->max('receipt_no')) + 1;

            /** @var Sale $sale */
            $sale = new Sale();
            $sale->fill([
                'receipt_no'     => $receiptNo,
                'customer_name'  => $data['customer_name'] ?? $customer?->name,
                'payment_method' => $owed > 0.0001 && $amountPaid <= 0.0001
                    ? 'credit'
                    : ($data['payment_method'] ?? 'cash'),
                'amount_paid'    => $amountPaid,
                'change_given'   => $owed > 0 ? 0 : round(-$owed, 2),
            ]);
            $sale->forceFill([
                'user_id'   => $cashier->id,
                'subtotal'  => $subtotal,
                'discount'  => $discount,
                'tax'       => $tax,
                'total'     => $total,
                'status'    => Sale::STATUS_COMPLETED,
                'created_by_impersonation' => app(\App\Support\TenantContext::class)->impersonation()['session_id'] ?? null,
            ]);
            if ($customer) {
                $sale->forceFill(['customer_id' => $customer->id]);
            }
            if ($owed > 0.0001) {
                $sale->forceFill(['due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString()]);
            }
            $sale->save();

            foreach ($lines as $line) {
                $sale->items()->create([
                    'product_id'   => $line['product']->id,
                    'product_name' => $line['product']->name, // snapshot
                    'unit_price'   => $line['unit_price'],
                    'unit_cost'    => $line['unit_cost'],
                    'quantity'     => $line['quantity'],
                    'line_total'   => $line['line_total'],
                ]);

                $this->stock->apply($line['product'], -$line['quantity'], 'sale', $sale);

                // Low-stock / out-of-stock alerts — at most once per product per day.
                $fresh = $line['product']->refresh();
                if ($fresh->stock_quantity <= 0) {
                    if ($this->notify->firstTime($cashier->tenant_id, "out_of_stock:{$fresh->id}:" . now()->toDateString())) {
                        $this->notify->toTenant($cashier->tenant_id, 'out_of_stock',
                            "Out of stock: {$fresh->name}",
                            "{$fresh->name} (SKU {$fresh->sku}) has run out of stock.",
                            ['product_id' => $fresh->id]);
                    }
                } elseif ($fresh->stock_quantity <= (int) $fresh->low_stock_threshold) {
                    if ($this->notify->firstTime($cashier->tenant_id, "low_stock:{$fresh->id}:" . now()->toDateString())) {
                        $this->notify->toTenant($cashier->tenant_id, 'low_stock',
                            "Low stock: {$fresh->name}",
                            "{$fresh->name} is down to {$fresh->stock_quantity} unit(s) (threshold {$fresh->low_stock_threshold}).",
                            ['product_id' => $fresh->id]);
                    }
                }
            }

            if ($owed > 0.0001) {
                $customer->forceFill(['balance' => round((float) $customer->balance + $owed, 2)])->save();
            }

            // An invoice is the formal document for every credit sale, and for
            // any sale where the cashier explicitly issues one.
            if ($owed > 0.0001 || ! empty($data['issue_invoice'])) {
                $this->issueInvoice($sale);
            }

            $this->audit->log('sale.created', [
                'actor'   => $cashier,
                'subject' => $sale,
                'context' => [
                    'receipt_no' => $receiptNo, 'total' => $total,
                    'items' => $lines->count(),
                    'owed' => max(0, $owed),
                    'customer_id' => $customer?->id,
                ],
            ]);

            return $sale->load('items');
        });
    }

    /** INV-YYYY-000001, sequential per tenant, race-safe. */
    public function issueInvoice(Sale $sale): Invoice
    {
        $year = now()->year;

        $seq = ((int) Invoice::where('tenant_id', $sale->tenant_id)
            ->where('issue_date', '>=', "{$year}-01-01")
            ->lockForUpdate()
            ->count()) + 1;

        return Invoice::create([
            'invoice_no'  => sprintf('INV-%d-%06d', $year, $seq),
            'sale_id'     => $sale->id,
            'customer_id' => $sale->customer_id,
            'issue_date'  => now()->toDateString(),
            'due_date'    => $sale->due_date,
        ]);
    }

    /** Void = reversal record. The original sale lines are never modified. */
    public function void(Sale $sale, string $reason, User $actor): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $actor) {
            /** @var Sale $locked */
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                abort(422, 'Only a completed sale can be voided.');
            }

            foreach ($locked->items as $item) {
                $this->stock->apply($item->product, $item->quantity, 'sale_void', $locked);
            }

            // Release whatever the customer still owed on this sale.
            $outstanding = $locked->balanceDue();
            if ($outstanding > 0 && $locked->customer_id) {
                $customer = Customer::whereKey($locked->customer_id)->lockForUpdate()->first();
                if ($customer) {
                    $customer->forceFill([
                        'balance' => round(max(0, (float) $customer->balance - $outstanding), 2),
                    ])->save();
                }
            }

            $locked->forceFill([
                'status'      => Sale::STATUS_VOIDED,
                'voided_at'   => now(),
                'voided_by'   => $actor->id,
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('sale.voided', [
                'actor'   => $actor,
                'subject' => $locked,
                'context' => ['receipt_no' => $locked->receipt_no, 'total' => (float) $locked->total, 'reason' => $reason],
            ]);

            return $locked->refresh();
        });
    }

    public function processReturn(Sale $sale, array $items, string $reason, User $actor): SaleReturn
    {
        return DB::transaction(function () use ($sale, $items, $reason, $actor) {
            /** @var Sale $lockedSale */
            $lockedSale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! $lockedSale->isCompleted()) {
                abort(422, 'Returns can only be processed against a completed sale.');
            }

            $owedBefore = $lockedSale->balanceDue();

            $saleItems   = $lockedSale->items->keyBy('id');
            $alreadyBack = DB::table('sale_return_items')
                ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                ->where('sale_returns.sale_id', $lockedSale->id)
                ->select('sale_return_items.sale_item_id', DB::raw('SUM(sale_return_items.quantity) as qty'))
                ->groupBy('sale_return_items.sale_item_id')
                ->pluck('qty', 'sale_return_items.sale_item_id');

            $lines = collect($items)->map(function (array $line) use ($saleItems, $alreadyBack, $lockedSale) {
                /** @var SaleItem|null $saleItem */
                $saleItem = $saleItems->get((int) $line['sale_item_id']);

                if ($saleItem === null) {
                    abort(422, 'Invalid sale item.');
                }

                $remaining = $saleItem->quantity - (int) ($alreadyBack[$saleItem->id] ?? 0);

                if ($line['quantity'] < 1 || $line['quantity'] > $remaining) {
                    abort(422, "Invalid return quantity for [{$saleItem->product_name}].");
                }

                return [
                    'sale_item' => $saleItem,
                    'quantity'  => (int) $line['quantity'],
                    'total'     => round((float) $saleItem->unit_price * (int) $line['quantity'], 2),
                ];
            });

            /** @var SaleReturn $return */
            $return = new SaleReturn();
            $return->fill([
                'sale_id' => $lockedSale->id,
                'reason'  => $reason,
                'total'   => round($lines->sum('total'), 2),
            ]);
            $return->forceFill(['user_id' => $actor->id])->save();

            foreach ($lines as $line) {
                $return->items()->create([
                    'sale_item_id' => $line['sale_item']->id,
                    'product_id'   => $line['sale_item']->product_id,
                    'quantity'     => $line['quantity'],
                    'unit_price'   => $line['sale_item']->unit_price,
                    'line_total'   => $line['total'],
                ]);

                $this->stock->apply($line['sale_item']->product, $line['quantity'], 'sale_return', $return);
            }

            // A return on a credit sale clears debt first; cash refunds are a
            // shop-floor matter and are not double-booked here.
            $owedAfter = $lockedSale->refresh()->balanceDue();
            $debtCleared = round(max(0, $owedBefore - $owedAfter), 2);
            if ($debtCleared > 0 && $lockedSale->customer_id) {
                $customer = Customer::whereKey($lockedSale->customer_id)->lockForUpdate()->first();
                if ($customer) {
                    $customer->forceFill([
                        'balance' => round(max(0, (float) $customer->balance - $debtCleared), 2),
                    ])->save();
                }
            }

            $this->audit->log('sale.returned', [
                'actor'   => $actor,
                'subject' => $return,
                'context' => ['sale_id' => $lockedSale->id, 'receipt_no' => $lockedSale->receipt_no, 'total' => (float) $return->total],
            ]);

            return $return->load('items');
        });
    }

    /**
     * Record a payment against a sale's outstanding balance (receivable
     * collection). Partial payments are first-class.
     */
    public function recordPayment(Sale $sale, float $amount, string $method, ?string $note, User $actor): \App\Models\SalePayment
    {
        return DB::transaction(function () use ($sale, $amount, $method, $note, $actor) {
            /** @var Sale $locked */
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted()) {
                abort(422, 'Payments can only be recorded on a completed sale.');
            }

            $due = $locked->balanceDue();
            if ($due <= 0) {
                abort(422, 'This sale is already fully paid.');
            }
            if ($amount <= 0 || $amount > $due + 0.0001) {
                abort(422, "Amount must be between 0.01 and the outstanding balance ({$due}).");
            }

            $payment = $locked->payments()->create([
                'customer_id' => $locked->customer_id,
                'amount'      => round($amount, 2),
                'method'      => $method,
                'note'        => $note,
                'user_id' => $actor->id,
            ]);

            if ($locked->customer_id) {
                $customer = Customer::whereKey($locked->customer_id)->lockForUpdate()->first();
                if ($customer) {
                    $customer->forceFill([
                        'balance' => round(max(0, (float) $customer->balance - $amount), 2),
                    ])->save();
                }
            }

            $this->audit->log('sale.payment_recorded', [
                'actor'   => $actor,
                'subject' => $locked,
                'context' => [
                    'receipt_no' => $locked->receipt_no,
                    'amount' => round($amount, 2),
                    'method' => $method,
                    'remaining' => $locked->refresh()->balanceDue(),
                ],
            ]);

            return $payment;
        });
    }
}
