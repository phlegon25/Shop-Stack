<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Revenue intelligence: MRR / ARR, by-plan mix, churn — all server-computed. */
class RevenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $monthlyExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', reviewed_at)"
            : "DATE_FORMAT(reviewed_at, '%Y-%m')";

        // MRR: prorated monthly value of every live paid subscription.
        $mrr = (float) Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('COALESCE(SUM(plans.price / CASE WHEN plans.duration_days > 0 THEN plans.duration_days ELSE 1 END * 30), 0) as v')
            ->value('v');

        $byPlan = Subscription::whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->where('ends_at', '>', now())
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->groupBy('plans.id', 'plans.name', 'plans.price')
            ->selectRaw('plans.name, plans.price, COUNT(*) as subscribers,
                COALESCE(SUM(plans.price / CASE WHEN plans.duration_days > 0 THEN plans.duration_days ELSE 1 END * 30), 0) as mrr')
            ->get();

        $activeNow = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->distinct('tenant_id')->count('tenant_id');

        $monthStart = now()->startOfMonth();
        $churnedThisMonth = Subscription::where('status', Subscription::STATUS_EXPIRED)
            ->where('ends_at', '>=', $monthStart)
            ->distinct('tenant_id')->count('tenant_id');
        $base = max(1, $activeNow + $churnedThisMonth);

        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $series[$key] = ['month' => $key, 'collected' => 0.0];
        }
        SubscriptionPayment::where('status', 'approved')
            ->where('reviewed_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw("{$monthlyExpr} as ym, SUM(amount) as total")
            ->groupBy('ym')->pluck('total', 'ym')
            ->each(fn ($total, $ym) => isset($series[$ym]) ? $series[$ym]['collected'] = round((float) $total, 2) : null);

        return response()->json([
            'currency' => config('pos.currency'),
            'mrr'   => round($mrr, 2),
            'arr'   => round($mrr * 12, 2),
            'arpu'  => $activeNow > 0 ? round($mrr / $activeNow, 2) : null,
            'active_paying_tenants' => $activeNow,
            'churn' => [
                'this_month'  => $churnedThisMonth,
                'rate_percent' => round($churnedThisMonth / $base * 100, 1),
            ],
            'by_plan' => $byPlan,
            'sums' => [
                'last_30_days' => round((float) SubscriptionPayment::where('status', 'approved')
                    ->where('reviewed_at', '>=', now()->subDays(30))->sum('amount'), 2),
                'lifetime' => round((float) SubscriptionPayment::where('status', 'approved')->sum('amount'), 2),
            ],
            'monthly_series' => array_values($series),
        ]);
    }
}
