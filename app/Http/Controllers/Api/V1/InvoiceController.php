<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Invoice;
use App\Models\Sale;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()->with(['customer:id,name', 'sale:id,receipt_no,status,total,amount_paid,due_date']);

        if ($request->filled('search')) {
            $s = trim((string) $request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->where('invoice_no', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }

        // Status is derived from the linked sale (single source of truth).
        $status = $request->query('status');
        if ($status === 'cancelled') {
            $query->whereHas('sale', fn ($q) => $q->where('status', Sale::STATUS_VOIDED));
        } elseif (in_array($status, ['paid', 'unpaid', 'partial', 'overdue'], true)) {
            $today = now()->toDateString();
            $query->whereHas('sale', function ($q) use ($status, $today) {
                $q->where('status', Sale::STATUS_COMPLETED);
                if ($status === 'overdue') {
                    $q->where('due_date', '<', $today);
                }
            });
            // paid/unpaid/partial are further resolved client-side per row;
            // rows arrive with everything needed to compute them.
        }

        ListQuery::applySort($query, $request, 'invoices', 'created_at', 'desc');

        $paginator = $query->paginate(ListQuery::perPage($request));
        $paginator->through(fn (Invoice $inv) => $this->summary($inv));

        return response()->json($paginator);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'sale.items', 'sale.payments', 'sale.returns',
            'sale.cashier:id,name', 'customer',
        ]);

        $settings = BusinessSetting::query()->first();
        $tenant   = $this->tenant();

        return response()->json([
            'data'        => $this->summary($invoice) + [
                'sale'     => $invoice->sale,
                'notes'    => $invoice->notes,
            ],
            'branding' => [
                'business_name' => $tenant->name,
                'phone'      => $tenant->phone,
                'email'      => $tenant->email,
                'address'    => $tenant->address,
                'city'       => $tenant->city,
                'region'     => $tenant->region,
                'logo_url'   => $settings?->logoUrl(),
                'footer'     => $settings?->invoice_footer,
            ],
            'currency' => $settings?->currency ?? config('pos.currency'),
        ]);
    }

    private function summary(Invoice $invoice): array
    {
        $sale = $invoice->sale;

        return [
            'id'            => $invoice->id,
            'invoice_no'    => $invoice->invoice_no,
            'issue_date'    => $invoice->issue_date,
            'due_date'      => $invoice->due_date,
            'customer'      => $invoice->customer ? ['id' => $invoice->customer->id, 'name' => $invoice->customer->name] : null,
            'receipt_no'    => $sale?->receipt_no,
            'total'         => $sale ? (float) $sale->total : 0.0,
            'paid'          => $sale?->amountPaidTotal() ?? 0.0,
            'balance'       => $sale?->balanceDue() ?? 0.0,
            'status'        => $sale
                ? ($sale->status === Sale::STATUS_VOIDED
                    ? 'cancelled'
                    : (($sale->due_date && now()->startOfDay()->greaterThan($sale->due_date) && $sale->balanceDue() > 0.0001)
                        ? 'overdue'
                        : $sale->paymentStatus()))
                : 'draft',
        ];
    }

    private function tenant()
    {
        return \App\Models\Tenant::findOrFail(app(\App\Support\TenantContext::class)->tenantId());
    }
}
