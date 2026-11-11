<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-tenant subscription administration: full lifecycle list, expiring
 * radar, and manual extensions (owner never touches these server fields).
 */
class AdminSubscriptionController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notify,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['tenant:id,name,slug,status', 'plan:id,name,price'])
            ->latest('ends_at');

        if ($request->filled('status') && in_array($request->query('status'),
            [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE, Subscription::STATUS_EXPIRED, Subscription::STATUS_CANCELLED], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('expiring_within')) {
            $days = max(1, min(365, (int) $request->query('expiring_within')));
            $query->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
                ->whereBetween('ends_at', [now(), now()->addDays($days)]);
        }

        if ($request->filled('tenant')) {
            $t = mb_substr((string) $request->query('tenant'), 0, 120);
            $query->whereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$t}%"));
        }

        return response()->json($query->paginate(20));
    }

    /** Manually extend a subscription (support recovery, goodwill, etc.). */
    public function extend(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'note' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        $anchor = $subscription->ends_at?->isFuture() ? $subscription->ends_at : now();
        $wasExpired = $subscription->status === Subscription::STATUS_EXPIRED;

        $subscription->forceFill([
            'ends_at'       => $anchor->copy()->addDays($data['days']),
            'status'        => $wasExpired ? Subscription::STATUS_ACTIVE : $subscription->status,
            'grace_ends_at' => null,
        ])->save();

        // A grace-suspended tenant comes back to life on extension.
        $tenant = $subscription->tenant;
        if ($tenant->isSuspended() && str_contains((string) $tenant->suspension_reason, 'grace')) {
            $tenant->forceFill([
                'status' => \App\Models\Tenant::STATUS_ACTIVE,
                'suspended_at' => null, 'suspension_reason' => null, 'suspended_by' => null,
            ])->save();
        }

        $this->audit->log('subscription.extended', [
            'subject' => $subscription,
            'context' => ['days' => $data['days'], 'note' => $data['note'], 'tenant_id' => $tenant->id],
        ]);

        $this->notify->toTenant($tenant->id, 'subscription_extended',
            'Subscription extended',
            "Your access was extended by {$data['days']} day(s). New end date: {$subscription->ends_at->toFormattedDateString()}.");

        return response()->json(['data' => $subscription->fresh()->load('tenant:id,name', 'plan:id,name')]);
    }
}
