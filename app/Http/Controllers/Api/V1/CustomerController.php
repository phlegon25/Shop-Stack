<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $s = trim((string) $request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            });
        }

        if ($request->query('owing') === '1' || $request->query('owing') === 'true') {
            $query->where('balance', '>', 0);
        }

        if ($request->filled('type') && in_array($request->query('type'), Customer::TYPES, true)) {
            $query->where('type', $request->query('type'));
        }

        ListQuery::applySort($query, $request, 'customers', 'name', 'asc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->payload($request);

        $customer = Customer::create($data);

        $this->audit->log('customer.created', ['subject' => $customer, 'context' => ['name' => $customer->name]]);

        return response()->json(['data' => $customer], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $salesBase = Sale::where('customer_id', $customer->id)->where('status', Sale::STATUS_COMPLETED);

        return response()->json([
            'data' => $customer,
            'stats' => [
                'total_purchases'  => (clone $salesBase)->count(),
                'total_spent'      => round((float) (clone $salesBase)->sum('total'), 2),
                'outstanding'      => round((float) $customer->balance, 2),
                'available_credit' => (float) ($customer->credit_limit ?? 0) > 0
                    ? round(max(0, (float) $customer->credit_limit - (float) $customer->balance), 2)
                    : null,
            ],
            'recent_sales' => (clone $salesBase)->with('items')->latest('created_at')->limit(10)->get(),
            'recent_payments' => $customer->payments()->with('sale:id,receipt_no')->latest('created_at')->limit(10)->get(),
        ]);
    }

    /** Full account statement: every sale and every payment in a range. */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $from = $request->date('from') ?? now()->subDays(90);
        $to   = $request->date('to') ?? now()->endOfDay();

        $sales = Sale::where('customer_id', $customer->id)
            ->where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Sale $s) => [
                'kind'      => 'sale',
                'date'      => $s->created_at,
                'reference' => 'RCPT-' . $s->receipt_no,
                'description' => 'Sale',
                'debit'     => round((float) $s->total - (float) $s->amount_paid, 2),
                'credit'    => 0.0,
                'sale_id'   => $s->id,
            ])
            ->filter(fn ($r) => $r['debit'] > 0.0001);

        $payments = $customer->payments()
            ->whereBetween('created_at', [$from, $to])
            ->with('sale:id,receipt_no')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($p) => [
                'kind'      => 'payment',
                'date'      => $p->created_at,
                'reference' => $p->sale ? 'RCPT-' . $p->sale->receipt_no : null,
                'description' => 'Payment received (' . $p->method . ')',
                'debit'     => 0.0,
                'credit'    => round((float) $p->amount, 2),
                'sale_id'   => $p->sale_id,
            ]);

        $lines = $sales->concat($payments)->sortBy('date')->values();

        $running = 0.0;
        $lines = $lines->map(function ($line) use (&$running) {
            $running = round($running + $line['debit'] - $line['credit'], 2);
            $line['balance'] = $running;

            return $line;
        });

        return response()->json([
            'customer' => $customer,
            'from'     => $from->toDateString(),
            'to'       => $to->toDateString(),
            'lines'    => $lines,
            'balance'  => round((float) $customer->balance, 2),
            'currency' => config('pos.currency'),
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->payload($request, $customer->id));

        $this->audit->log('customer.updated', ['subject' => $customer, 'context' => ['fields' => array_keys($this->payload($request, $customer->id))]]);

        return response()->json(['data' => $customer->fresh()]);
    }

    /** Receivables: every completed sale with money still owed on it. */
    public function receivables(Request $request): JsonResponse
    {
        // Gather completed sales that can hold a balance, then compute the
        // authoritative balance per sale in PHP (payments + returns aware).
        $sales = Sale::where('status', Sale::STATUS_COMPLETED)
            ->whereNotNull('customer_id')
            ->with(['customer:id,name,phone', 'payments'])
            ->latest('created_at')
            ->limit(500)
            ->get()
            ->filter(fn (Sale $s) => $s->balanceDue() > 0.0001)
            ->values()
            ->map(function (Sale $s) {
                $daysOverdue = $s->due_date && now()->startOfDay()->greaterThan($s->due_date)
                    ? (int) $s->due_date->startOfDay()->diffInDays(now()->startOfDay())
                    : 0;

                return [
                    'sale_id'      => $s->id,
                    'receipt_no'   => $s->receipt_no,
                    'date'         => $s->created_at,
                    'due_date'     => $s->due_date,
                    'total'        => (float) $s->total,
                    'paid'         => $s->amountPaidTotal(),
                    'balance'      => $s->balanceDue(),
                    'days_overdue' => $daysOverdue,
                    'customer'     => $s->customer,
                ];
            })
            ->sortByDesc(function ($r) {
                return $r['due_date'] ? (int) $r['due_date']->format('U') : 0;
            })
            ->values();

        return response()->json([
            'data'     => $sales,
            'total_outstanding' => round($sales->sum('balance'), 2),
            'currency' => config('pos.currency'),
        ]);
    }

    private function payload(Request $request, ?int $ignoreId = null): array
    {
        $tenantId = $this->context->tenantId();

        return $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'phone'        => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s]{7,20}$/'],
            'email'        => [
                'nullable', 'email', 'max:190',
                Rule::unique('customers', 'email')->where('tenant_id', $tenantId)->ignore($ignoreId),
            ],
            'address'      => ['nullable', 'string', 'max:300'],
            'type'         => ['sometimes', Rule::in(Customer::TYPES)],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'notes'        => ['nullable', 'string', 'max:500'],
            // balance and tenant_id are structural — NEVER client writable.
        ]);
    }
}
