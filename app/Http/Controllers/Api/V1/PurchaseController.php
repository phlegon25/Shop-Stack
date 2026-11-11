<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseStoreRequest;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseService $purchases,
        private TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query()->with(['supplier:id,name', 'recordedBy:id,name']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }
        if ($request->filled('status') && in_array($request->query('status'), ['received', 'cancelled'], true)) {
            $query->where('status', $request->query('status'));
        }

        ListQuery::applySort($query, $request, 'purchases', 'created_at', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function store(PurchaseStoreRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create($this->context->user(), $request->validated());

        return response()->json(['data' => $purchase], 201);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load(['items.product:id,name,sku', 'supplier:id,name', 'recordedBy:id,name', 'payments']);

        return response()->json(['data' => $purchase->toArray() + [
            'balance_due'    => $purchase->balanceDue(),
            'payment_status' => $purchase->paymentStatus(),
        ]]);
    }

    /** Record a supplier payment against a received purchase (partial OK). */
    public function recordPayment(Request $request, Purchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'momo', 'bank', 'card'])],
            'note'   => ['nullable', 'string', 'max:300'],
        ]);

        $payment = $this->purchases->recordPayment(
            $purchase, (float) $data['amount'], $data['method'], $data['note'] ?? null, $this->context->user(),
        );

        return response()->json([
            'data'         => $payment,
            'balance_due'  => $purchase->refresh()->balanceDue(),
            'message'      => 'Supplier payment recorded.',
        ], 201);
    }

    /** Payables: every received purchase with money still owed. */
    public function payables(): JsonResponse
    {
        $rows = Purchase::where('status', Purchase::STATUS_RECEIVED)
            ->with('supplier:id,name,phone')
            ->latest('created_at')
            ->limit(500)
            ->get()
            ->filter(fn (Purchase $p) => $p->balanceDue() > 0.0001)
            ->map(function (Purchase $p) {
                return [
                    'purchase_id' => $p->id,
                    'reference'   => $p->reference,
                    'date'        => $p->created_at,
                    'due_date'    => $p->due_date,
                    'total'       => (float) $p->total,
                    'paid'        => (float) $p->amount_paid,
                    'balance'     => $p->balanceDue(),
                    'supplier'    => $p->supplier,
                ];
            })
            ->values();

        return response()->json([
            'data'              => $rows,
            'total_outstanding' => round($rows->sum('balance'), 2),
            'currency'          => config('pos.currency'),
        ]);
    }

    public function cancel(Purchase $purchase): JsonResponse
    {
        $purchase = $this->purchases->cancel($purchase, $this->context->user());

        return response()->json(['data' => $purchase, 'message' => 'Purchase cancelled. Stock has been reversed.']);
    }
}
