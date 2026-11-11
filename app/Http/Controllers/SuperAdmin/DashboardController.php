<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Support\Lifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->get(['id', 'name', 'status', 'created_at']);
        $byStatus = $tenants->groupBy('status')->map->count();

        // Lifecycle stage counts (server-computed — the console just renders).
        $stageCounts = collect(Lifecycle::STAGES)->mapWithKeys(fn ($s) => [$s => 0]);
        $expiringSoon = [];
        foreach ($tenants as $tenant) {
            $badge = Lifecycle::badge($tenant);
            $stageCounts[$badge['stage']] = ($stageCounts[$badge['stage']] ?? 0) + 1;

            if (in_array($badge['stage'], ['EXPIRING', 'TRIAL'], true) && $badge['days_left'] !== null && $badge['days_left'] <= 7) {
                if (count($expiringSoon) < 8) {
                    $expiringSoon[] = [
                        'tenant_id' => $tenant->id,
                        'name'      => $tenant->name,
                        'stage'     => $badge['stage'],
                        'days_left' => $badge['days_left'],
                        'plan'      => $badge['plan'],
                    ];
                }
            }
        }

        // MRR from live subscriptions: price prorated to a 30-day month.
        $mrr = Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('COALESCE(SUM(plans.price / CASE WHEN plans.duration_days > 0 THEN plans.duration_days ELSE 1 END * 30), 0) as mrr')
            ->value('mrr');

        $pending = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_PENDING);
        $thisMonth = now()->startOfMonth();

        return response()->json([
            'tenants' => [
                'total'     => $tenants->count(),
                'active'    => (int) ($byStatus['active'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'by_stage'  => $stageCounts,
                'new_this_month' => $tenants->where('created_at', '>=', $thisMonth)->count(),
            ],
            'money' => [
                'mrr'                => round((float) $mrr, 2),
                'arr'                => round((float) $mrr * 12, 2),
                'collected_this_month' => round((float) SubscriptionPayment::where('status', 'approved')
                    ->where('reviewed_at', '>=', $thisMonth)->sum('amount'), 2),
                'pending_count'    => (clone $pending)->count(),
                'pending_amount'   => round((float) (clone $pending)->sum('amount'), 2),
                // Expected near-term revenue: renewals due in the next 30 days.
                'expected_30d'     => round((float) Subscription::whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                    ->whereBetween('ends_at', [now(), now()->addDays(30)])
                    ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                    ->sum('plans.price'), 2),
            ],
            'support' => [
                'open_tickets' => SupportTicket::whereIn('status', ['open', 'in_progress'])->count(),
            ],
            'expiring_soon'       => $expiringSoon,
            'recent_registration' => Tenant::latest('created_at')->limit(6)->get(['id', 'name', 'status', 'created_at']),
            'revenue_series'      => $this->revenueSeries(),
            'currency'            => config('pos.currency'),
        ]);
    }

    /** Approved revenue per month for the last 6 months (chart series). */
    private function revenueSeries(): array
    {
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', reviewed_at)"
            : "DATE_FORMAT(reviewed_at, '%Y-%m')";

        $rows = SubscriptionPayment::where('status', 'approved')
            ->where('reviewed_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("{$monthExpr} as ym, SUM(amount) as total")
            ->groupBy('ym')->orderBy('ym')->pluck('total', 'ym');

        $series = [];
        for ($i = 5; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $series[] = ['month' => $key, 'total' => round((float) ($rows[$key] ?? 0), 2)];
        }

        return $series;
    }
}
