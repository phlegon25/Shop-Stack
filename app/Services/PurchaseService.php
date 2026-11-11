<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private AuditLogger $audit,
    ) {}

    public function create(User $actor, array $data): Purchase
    {
        return DB::transaction(function () use ($actor, $data) {
            $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
            $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                abort(422, 'One or more products are invalid.');
            }

            // Compute lines and totals up-front so the header row is
            // inserted once, complete (no partial financial state).
            $lines = collect($data['items'])->map(function (array $item) use ($products) {
                $product   = $products->get($item['product_id']);
                $unitCost  = round((float) $item['unit_cost'], 2);

                return [
                    'product'    => $product,
                    'quantity'   => (int) $item['quantity'],
                    'unit_cost'  => $unitCost,
                    'line_total' => round($unitCost * (int) $item['quantity'], 2),
                ];
            });

            /** @var Purchase $purchase */
            $purchase = new Purchase();
            $purchase->fill([
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference'   => $data['reference'],
                'note'        => $data['note'] ?? null,
                'received_at' => $data['received_at'] ?? now(),
            ]);

            $total      = round($lines->sum('line_total'), 2);
            $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);
            if ($amountPaid < 0 || $amountPaid > $total + 0.0001) {
                abort(422, 'Amount paid must be between 0 and the purchase total.');
            }

            $purchase->forceFill([
                'user_id'     => $actor->id,
                'status'      => Purchase::STATUS_RECEIVED,
                'total'       => $total,
                'amount_paid' => $amountPaid,
                'due_date'    => ($data['due_date'] ?? null) ?: ($total - $amountPaid > 0.0001 ? now()->addDays(14)->toDateString() : null),
            ])->save();

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $purchase->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $line['quantity'],
                    'unit_cost'  => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                ]);

                $this->stock->apply($product, $line['quantity'], 'purchase', $purchase);

                // Latest cost becomes the product's recorded cost.
                $product->forceFill(['cost_price' => $line['unit_cost']])->save();
            }

            $this->audit->log('purchase.received', [
                'actor'   => $actor,
                'subject' => $purchase,
                'context' => ['reference' => $purchase->reference, 'total' => (float) $purchase->total],
            ]);

            return $purchase->load('items');
        });
    }

    /**
     * Cancellation never deletes history: it flips status and writes
     * reversing stock movements.
     */
    public function cancel(Purchase $purchase, User $actor): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isReceived()) {
                abort(422, 'Only a received purchase can be cancelled.');
            }

            // A purchase with money already attached can never silently vanish:
            // settle/refund arrangements are handled explicitly off-platform.
            if ((float) $locked->amount_paid > 0 || $locked->payments()->exists()) {
                abort(422, 'This purchase has recorded payments and cannot be cancelled.');
            }

            foreach ($locked->items as $item) {
                // Throws (and rolls back) if reversing would drive stock negative.
                $this->stock->apply($item->product, -$item->quantity, 'purchase_cancel', $locked);
            }

            $locked->forceFill(['status' => Purchase::STATUS_CANCELLED])->save();

            $this->audit->log('purchase.cancelled', [
                'actor'   => $actor,
                'subject' => $locked,
                'context' => ['reference' => $locked->reference],
            ]);

            return $locked->refresh();
        });
    }

    /** Record a (partial) payment to a supplier against a received purchase. */
    public function recordPayment(Purchase $purchase, float $amount, string $method, ?string $note, User $actor): \App\Models\SupplierPayment
    {
        return DB::transaction(function () use ($purchase, $amount, $method, $note, $actor) {
            /** @var Purchase $locked */
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isReceived()) {
                abort(422, 'Payments can only be recorded on a received purchase.');
            }

            $due = $locked->balanceDue();
            if ($due <= 0) {
                abort(422, 'This purchase is already fully paid.');
            }
            if ($amount <= 0 || $amount > $due + 0.0001) {
                abort(422, "Amount must be between 0.01 and the outstanding balance ({$due}).");
            }

            $payment = $locked->payments()->create([
                'supplier_id' => $locked->supplier_id,
                'user_id'     => $actor->id,
                'amount'      => round($amount, 2),
                'method'      => $method,
                'note'        => $note,
            ]);

            $locked->forceFill([
                'amount_paid' => round((float) $locked->amount_paid + $amount, 2),
            ])->save();

            $this->audit->log('purchase.payment_recorded', [
                'actor'   => $actor,
                'subject' => $locked,
                'context' => [
                    'reference' => $locked->reference,
                    'amount'    => round($amount, 2),
                    'method'    => $method,
                    'remaining' => $locked->refresh()->balanceDue(),
                ],
            ]);

            return $payment;
        });
    }
}
