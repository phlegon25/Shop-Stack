<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ExpenseCategory::orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $tenantId = $this->context->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('expense_categories', 'name')->where('tenant_id', $tenantId)],
        ]);

        $category = ExpenseCategory::create($data);
        $this->audit->log('expense_category.created', ['subject' => $category]);

        return response()->json(['data' => $category], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Expense::query()->with('category:id,name', 'recordedBy:id,name');

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->date('to'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$s}%");
        }

        ListQuery::applySort($query, $request, 'expenses', 'expense_date', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        $expense = new Expense();
        $expense->fill($data);
        $expense->forceFill(['user_id' => $this->context->user()->id])->save();

        $this->audit->log('expense.created', [
            'subject' => $expense,
            'context' => ['name' => $expense->name, 'amount' => (float) $expense->amount],
        ]);

        return response()->json(['data' => $expense->load('category:id,name')], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $this->payload($request);

        // Corrections only — expenses stay auditable and are never deleted.
        $expense->update($data);

        $this->audit->log('expense.updated', [
            'subject' => $expense,
            'context' => ['fields' => array_keys($data)],
        ]);

        return response()->json(['data' => $expense->fresh()->load('category:id,name')]);
    }

    private function payload(Request $request): array
    {
        $tenantId = $this->context->tenantId();

        return $request->validate([
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('expense_categories', 'id')->where('tenant_id', $tenantId),
            ],
            'name'           => ['required', 'string', 'max:160'],
            'amount'         => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'expense_date'   => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['sometimes', Rule::in(['cash', 'momo', 'bank', 'card'])],
            'description'    => ['nullable', 'string', 'max:500'],
        ]);
    }
}
