<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /*
    |------------------------------------------------------------------
    | Dashboard — every KPI from real ledger tables, nothing fabricated.
    |------------------------------------------------------------------
    */
    public function dashboard(): JsonResponse
    {
        $tenantId = $this->context->tenantId();
        $today    = today();
        $month    = now()->startOfMonth();

        [$todaySales, $todayProfit, $todayProfitOk] = $this->aggregate($tenantId, $today, null);
        [$monthSales, $monthProfit, $monthProfitOk] = $this->aggregate($tenantId, $month, null);

        $inventory = Product::where('is_active', true)->selectRaw(
            'COALESCE(SUM(stock_quantity * cost_price),0) as cost_value,
             COALESCE(SUM(stock_quantity * selling_price),0) as retail_value,
             COALESCE(SUM(stock_quantity),0) as units'
        )->first();

        $lowStock = Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count();
        $outOfStock = Product::where('is_active', true)->where('stock_quantity', '<=', 0)->count();

        $receivableRows = Sale::where('status', Sale::STATUS_COMPLETED)
            ->whereNotNull('customer_id')->with('payments')->limit(500)->get();
        $receivables = round($receivableRows->sum(fn (Sale $s) => $s->balanceDue()), 2);
        $receivablesOverdue = round($receivableRows->filter(
            fn (Sale $s) => $s->due_date && $s->due_date->lt(now()) && $s->balanceDue() > 0.0001
        )->sum(fn (Sale $s) => $s->balanceDue()), 2);

        $payables = round(Purchase::where('status', Purchase::STATUS_RECEIVED)
            ->whereColumn('amount_paid', '<', 'total')
            ->selectRaw('COALESCE(SUM(total - amount_paid),0) as owed')->value('owed'), 2);

        $expensesToday = round((float) Expense::whereDate('expense_date', $today)->sum('amount'), 2);
        $expensesMonth = round((float) Expense::where('expense_date', '>=', $month)->sum('amount'), 2);

        $unpaidSales = Sale::where('status', Sale::STATUS_COMPLETED)
            ->whereNotNull('customer_id')->with('payments')->limit(500)->get();
        $outstandingSales = $unpaidSales->filter(fn (Sale $s) => $s->balanceDue() > 0.0001)->count();

        // 14-day revenue sparkline.
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $series[$day->toDateString()] = 0.0;
        }
        Sale::where('status', Sale::STATUS_COMPLETED)
            ->where('created_at', '>=', today()->subDays(13))
            ->selectRaw('DATE(created_at) as d, SUM(total - discount) as net')
            ->groupBy('d')->pluck('net', 'd')
            ->each(function ($net, $d) use (&$series) {
                if (array_key_exists($d, $series)) {
                    $series[$d] = round((float) $net, 2);
                }
            });

        $topProducts = $this->topProductsQuery(30);
        $recentSales = Sale::with('customer:id,name')->latest('created_at')->limit(8)
            ->get(['id', 'receipt_no', 'customer_id', 'customer_name', 'total', 'status', 'payment_method', 'created_at']);

        $unread = \App\Models\Notification::where('tenant_id', $tenantId)->whereNull('read_at')->count();

        return response()->json([
            'currency' => config('pos.currency'),
            'today'    => [
                'sales'      => $todaySales,
                // Profit is only surfaced when cost data is complete.
                'profit'          => $todayProfitOk ? $todayProfit : null,
                'profit_available' => $todayProfitOk,
            ],
            'month'    => [
                'sales'      => $monthSales,
                'profit'          => $monthProfitOk ? $monthProfit : null,
                'profit_available' => $monthProfitOk,
            ],
            'inventory' => [
                'units'        => (int) $inventory->units,
                'cost_value'   => round((float) $inventory->cost_value, 2),
                'retail_value' => round((float) $inventory->retail_value, 2),
            ],
            'stock' => ['low' => $lowStock, 'out' => $outOfStock],
            'money' => [
                'receivables'         => $receivables,
                'receivables_overdue' => $receivablesOverdue,
                'payables'            => $payables,
                'outstanding_sales'   => $outstandingSales,
            ],
            'expenses' => ['today' => $expensesToday, 'month' => $expensesMonth],
            'series'   => collect($series)->map(fn ($v, $k) => ['date' => $k, 'total' => $v])->values(),
            'top_products' => $topProducts,
            'recent_sales' => $recentSales,
            'unread_notifications' => $unread,
        ]);
    }

    /*
    |------------------------------------------------------------------
    | Profit & Loss — REAL arithmetic, zero fabricated numbers.
    |   Revenue = completed sales (net of discount & returns)
    |   Gross   = Revenue − COGS        (only when ALL costs known)
    |   Net     = Gross − Expenses
    |------------------------------------------------------------------
    */
    public function profitLoss(Request $request): JsonResponse
    {
        [$from, $to, $label] = $this->range($request);
        $tenantId = $this->context->tenantId();

        [$revenue, , , $extra] = $this->aggregate($tenantId, $from, $to, true);

        $grossKnown = $extra['cost_complete'];
        $gross = $grossKnown ? round($revenue - $extra['cogs'], 2) : null;

        $expenses = Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category_id, COALESCE(SUM(amount),0) as total')
            ->groupBy('category_id')->pluck('total', 'category_id');

        $categories = \App\Models\ExpenseCategory::whereIn('id', $expenses->keys()->filter())->pluck('name', 'id');
        $byCategory = $expenses->map(fn ($total, $catId) => [
            'category' => $catId ? ($categories[$catId] ?? 'Uncategorised') : 'Uncategorised',
            'total'    => round((float) $total, 2),
        ])->sortByDesc('total')->values();

        $expenseTotal = round($byCategory->sum('total'), 2);
        $net = $grossKnown ? round($gross - $expenseTotal, 2) : null;

        return response()->json([
            'currency' => config('pos.currency'),
            'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label],
            'revenue'  => $revenue,
            'tax_collected' => $extra['tax'],
            'returns'  => $extra['returns'],
            'cogs'     => $grossKnown ? $extra['cogs'] : null,
            'gross_profit' => $gross,
            'gross_margin' => $grossKnown && $revenue > 0 ? round($gross / $revenue * 100, 1) : null,
            'expenses' => ['total' => $expenseTotal, 'by_category' => $byCategory],
            'net_profit' => $net,
            // The UI MUST consult this flag before displaying any profit figure.
            'profit_available' => $grossKnown,
            'sales_count' => $extra['count'],
        ]);
    }

    public function salesReport(Request $request): JsonResponse
    {
        [$from, $to, $label] = $this->range($request);
        $tenantId = $this->context->tenantId();

        [$revenue, , , $extra] = $this->aggregate($tenantId, $from, $to, true);

        $byMethod = Sale::where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total')
            ->groupBy('payment_method')->get()
            ->map(fn ($r) => ['method' => $r->payment_method, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)]);

        $byCashier = Sale::where('sales.status', Sale::STATUS_COMPLETED)
            ->whereBetween('sales.created_at', [$from, $to])
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('users.name, COUNT(*) as count, SUM(sales.total) as total')
            ->groupBy('users.id', 'users.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['cashier' => $r->name, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)]);

        // Daily series across the range.
        $daily = Sale::where('status', Sale::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as count, SUM(total) as total')
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => $r->d, 'count' => (int) $r->count, 'total' => round((float) $r->total, 2)]);

        return response()->json([
            'currency'   => config('pos.currency'),
            'range'      => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label],
            'revenue'    => $revenue,
            'sales_count' => $extra['count'],
            'average_basket' => $extra['count'] > 0 ? round($revenue / $extra['count'], 2) : 0,
            'by_method'  => $byMethod,
            'by_cashier' => $byCashier,
            'by_product' => $this->topProductsQuery(null, $from, $to),
            'daily'      => $daily,
            'tax_collected' => $extra['tax'],
        ]);
    }

    /**
     * Aggregate completed sales in a range.
     * Returns [revenueNetOfDiscount, grossProfit, profitIsComplete, extra].
     */
    private function aggregate(int $tenantId, CarbonImmutable|\Carbon\Carbon $from, $to = null, bool $detail = false): array
    {
        $base = Sale::where('sales.tenant_id', $tenantId)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sales.created_at', '>=', $from);
        if ($to) {
            $base->where('sales.created_at', '<=', $to);
        }

        $count   = (int) (clone $base)->count();
        $gross   = (float) (clone $base)->sum('total'); // includes tax
        $disc    = (float) (clone $base)->sum('discount');
        $tax     = (float) (clone $base)->sum('tax');

        $returnsQ = DB::table('sale_returns')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.created_at', '>=', $from);
        if ($to) {
            $returnsQ->where('sales.created_at', '<=', $to);
        }
        $returns = (float) $returnsQ->sum('sale_returns.total');

        // COGS via the snapshot captured at sale time (selling ÷ quantity × cost).
        $itemsQ = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sales.created_at', '>=', $from);
        if ($to) {
            $itemsQ->where('sales.created_at', '<=', $to);
        }

        $cogs            = (float) (clone $itemsQ)->whereNotNull('sale_items.unit_cost')
            ->selectRaw('SUM(sale_items.unit_cost * sale_items.quantity) as c')->value('c');
        $unknownCostRows = (int) (clone $itemsQ)->whereNull('sale_items.unit_cost')->count();

        $revenueNetOfTax = round($gross - $tax - $returns, 2);
        // Cost completeness: no line without a cost snapshot.
        $costComplete    = $count === 0 || $unknownCostRows === 0;
        $profit          = $costComplete ? round($gross - $tax - $returns - $cogs, 2) : null;

        return [
            $revenueNetOfTax,
            $costComplete ? $profit : 0.0,
            $costComplete,
            [
                'count'          => $count,
                'gross'          => round($gross, 2),
                'discount'       => round($disc, 2),
                'tax'            => round($tax, 2),
                'returns'        => round($returns, 2),
                'cogs'           => round($cogs, 2),
                'cost_complete'  => $costComplete,
                'unknown_lines'  => $unknownCostRows,
            ],
        ];
    }

    private function topProductsQuery(?int $daysBack, $from = null, $to = null)
    {
        $q = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $this->context->tenantId())
            ->where('sales.status', Sale::STATUS_COMPLETED);

        if ($from) {
            $q->whereBetween('sales.created_at', [$from, $to]);
        } elseif ($daysBack) {
            $q->where('sales.created_at', '>=', now()->subDays($daysBack));
        }

        return $q->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->select('sale_items.product_id', 'sale_items.product_name',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.line_total) as total_revenue'))
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'product_id'    => $r->product_id,
                'name'          => $r->product_name,
                'quantity'      => (int) $r->total_quantity,
                'revenue'       => round((float) $r->total_revenue, 2),
            ])->values();
    }

    /** day | week | month | year | custom(from,to) — all inclusive. */
    private function range(Request $request): array
    {
        $period = $request->query('period', 'day');

        return match ($period) {
            'week'   => [now()->startOfWeek(), now()->endOfDay(), 'This week'],
            'month'  => [now()->startOfMonth(), now()->endOfDay(), 'This month'],
            'year'   => [now()->startOfYear(), now()->endOfDay(), 'This year'],
            'custom' => [
                $request->date('from') ? CarbonImmutable::parse($request->date('from'))->startOfDay() : now()->subDays(29)->startOfDay(),
                $request->date('to')   ? CarbonImmutable::parse($request->date('to'))->endOfDay()     : now()->endOfDay(),
                'Custom range',
            ],
            default  => [now()->startOfDay(), now()->endOfDay(), 'Today'],
        };
    }

    public function salesSummary(): JsonResponse
    {
        $completed = Sale::where('status', Sale::STATUS_COMPLETED);

        $today = (clone $completed)->whereDate('created_at', today());
        $week  = (clone $completed)->where('created_at', '>=', now()->startOfWeek());
        $month = (clone $completed)->where('created_at', '>=', now()->startOfMonth());

        $lowStock = Product::where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        return response()->json([
            'currency' => config('pos.currency'),
            'today'    => ['count' => (clone $today)->count(),   'total' => round((float) (clone $today)->sum('total'), 2)],
            'week'     => ['count' => (clone $week)->count(),    'total' => round((float) (clone $week)->sum('total'), 2)],
            'month'    => ['count' => (clone $month)->count(),   'total' => round((float) (clone $month)->sum('total'), 2)],
            'low_stock_products' => $lowStock,
        ]);
    }

    public function topProducts(): JsonResponse
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', app(\App\Support\TenantContext::class)->tenantId())
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sales.created_at', '>=', now()->subDays(30))
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->select(
                'sale_items.product_id',
                'sale_items.product_name',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.line_total) as total_revenue'),
            )
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        return response()->json(['data' => $rows, 'currency' => config('pos.currency')]);
    }
}
