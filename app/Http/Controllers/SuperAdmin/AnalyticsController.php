<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Platform growth analytics: signups, activation, cohort-style conversion. */
class AnalyticsController extends Controller
{
    public function index(): JsonResponse
    {
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $signups = [];
        for ($i = 11; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $signups[$key] = ['month' => $key, 'signups' => 0];
        }
        Tenant::where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw("{$monthExpr} as ym, COUNT(*) as c")->groupBy('ym')->pluck('c', 'ym')
            ->each(fn ($c, $ym) => isset($signups[$ym]) ? $signups[$ym]['signups'] = (int) $c : null);

        $tenantsTotal   = Tenant::count();
        $everPaid       = SubscriptionPayment::where('status', 'approved')->distinct('tenant_id')->count('tenant_id');
        $trialingCount  = Subscription::where('status', Subscription::STATUS_TRIALING)->where('ends_at', '>', now())
            ->distinct('tenant_id')->count('tenant_id');

        $popular = Subscription::join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->groupBy('plans.id', 'plans.name')
            ->selectRaw('plans.name, COUNT(*) as subs')->orderByDesc('subs')->limit(5)->get();

        $statusCounts = Tenant::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'signup_series' => array_values($signups),
            'conversion' => [
                'tenants_total'   => $tenantsTotal,
                'ever_paid'       => $everPaid,
                'trial_to_paid'   => $tenantsTotal > 0 ? round($everPaid / $tenantsTotal * 100, 1) : null,
                'currently_trialing' => $trialingCount,
            ],
            'popular_plans'  => $popular,
            'tenant_status'  => $statusCounts,
        ]);
    }
}
