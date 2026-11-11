<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleReturnRequest;
use App\Http\Requests\SaleStoreRequest;
use App\Http\Requests\SaleVoidRequest;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private SaleService $sales,
        private TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Sale::query()->with(['items', 'cashier:id,name']);

        if ($request->filled('status') && in_array($request->query('status'), ['completed', 'voided'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        ListQuery::applySort($query, $request, 'sales', 'created_at', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function store(SaleStoreRequest $request): JsonResponse
    {
        $sale = $this->sales->create($this->context->user(), $request->validatedPayload());

        return response()->json(['data' => $sale], 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        $sale->load(['items.product:id,sku', 'cashier:id,name', 'returns.items', 'customer', 'payments', 'invoice:id,sale_id,invoice_no,issue_date,due_date']);

        return response()->json(['data' => $sale->toArray() + [
            'balance_due'    => $sale->balanceDue(),
            'payment_status' => $sale->paymentStatus(),
        ]]);
    }

    /** Record a (partial) payment against a credit sale's balance. */
    public function recordPayment(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'method' => ['required', \Illuminate\Validation\Rule::in(['cash', 'momo', 'bank', 'card'])],
            'note'   => ['nullable', 'string', 'max:300'],
        ]);

        $payment = $this->sales->recordPayment(
            $sale, (float) $data['amount'], $data['method'], $data['note'] ?? null, $this->context->user(),
        );

        return response()->json([
            'data'        => $payment,
            'balance_due' => $sale->refresh()->balanceDue(),
            'message'     => 'Payment recorded.',
        ], 201);
    }

    /** Reversal — the original record stays intact forever. */
    public function void(SaleVoidRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->sales->void($sale, $request->validated('reason'), $this->context->user());

        return response()->json(['data' => $sale, 'message' => 'Sale voided. Stock has been restored.']);
    }

    public function processReturn(SaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $return = $this->sales->processReturn(
            $sale,
            $request->validated('items'),
            $request->validated('reason'),
            $this->context->user(),
        );

        return response()->json(['data' => $return, 'message' => 'Return processed.'], 201);
    }
}
