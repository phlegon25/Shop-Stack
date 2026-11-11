<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * All subscription state changes live here — tenants can NEVER modify their
 * own subscription rows. Approval is super-admin-only and transactional.
 */
class SubscriptionService
{
    public function __construct(private AuditLogger $audit) {}

    /** Grant the free trial to a freshly registered tenant. */
    public function startTrial(Tenant $tenant): Subscription
    {
        // Prefer the dedicated (hidden) trial plan; fall back to fixtures that
        // predate it by picking the cheapest active plan — tests depend on this.
        $plan = Plan::where('slug', 'trial')->first()
            ?? Plan::where('is_active', true)->orderBy('price')->first();

        return DB::transaction(function () use ($tenant, $plan) {
            $subscription = $tenant->subscriptions()->create([
                'plan_id'    => $plan->id,
                'status'     => Subscription::STATUS_TRIALING,
                'starts_at'  => now(),
                'ends_at'    => now()->addDays(config('pos.trial_days')),
                'granted_by' => 'system',
                'note'       => 'Free trial',
            ]);

            $this->audit->log('subscription.trial_started', [
                'tenant_id' => $tenant->id,
                'subject'   => $subscription,
                'actor_type' => 'system',
            ]);

            return $subscription;
        });
    }

    /**
     * Approve a pending payment and activate/extend the subscription.
     * Runs in ONE transaction so a partial state is impossible.
     */
    public function approvePayment(SubscriptionPayment $payment, SuperAdmin $admin): Subscription
    {
        return DB::transaction(function () use ($payment, $admin) {
            /** @var SubscriptionPayment $locked */
            $locked = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                abort(422, 'This payment has already been reviewed.');
            }

            $tenant = Tenant::whereKey($locked->tenant_id)->lockForUpdate()->firstOrFail();

            // Extend from the current expiry if one is still running,
            // otherwise start from now.
            $currentEnd = $tenant->subscriptions()
                ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
                ->where('ends_at', '>', now())
                ->max('ends_at');

            $startsAt = $currentEnd ? \Carbon\Carbon::parse($currentEnd) : now();
            $endsAt   = (clone $startsAt)->addDays($locked->plan->duration_days);

            // Close previous subscription records for a clean audit trail.
            $tenant->subscriptions()
                ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
                ->where('ends_at', '<=', $startsAt)
                ->update(['status' => Subscription::STATUS_EXPIRED, 'updated_at' => now()]);

            $subscription = $tenant->subscriptions()->create([
                'plan_id'           => $locked->plan_id,
                'status'            => Subscription::STATUS_ACTIVE,
                'starts_at'         => now(),
                'ends_at'           => $endsAt,
                'source_payment_id' => $locked->id,
                'granted_by'        => "super_admin:{$admin->id}",
                'note'              => 'Payment approved',
            ]);

            $locked->forceFill([
                'status'          => SubscriptionPayment::STATUS_APPROVED,
                'reviewed_by'     => $admin->id,
                'reviewed_at'     => now(),
                'subscription_id' => $subscription->id,
            ])->save();

            $this->audit->log('payment.approved', [
                'actor'     => $admin,
                'tenant_id' => $tenant->id,
                'subject'   => $locked,
                'context'   => [
                    'amount'    => $locked->amount,
                    'method'    => $locked->method,
                    'reference' => $locked->reference,
                    'plan_id'   => $locked->plan_id,
                ],
            ]);

            $this->audit->log('subscription.activated', [
                'actor'     => $admin,
                'tenant_id' => $tenant->id,
                'subject'   => $subscription,
                'context'   => ['plan_id' => $locked->plan_id, 'ends_at' => $endsAt->toISOString()],
            ]);

            return $subscription;
        });
    }

    public function rejectPayment(SubscriptionPayment $payment, SuperAdmin $admin, string $reason): void
    {
        DB::transaction(function () use ($payment, $admin, $reason) {
            $locked = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                abort(422, 'This payment has already been reviewed.');
            }

            $locked->forceFill([
                'status'      => SubscriptionPayment::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ])->save();

            $this->audit->log('payment.rejected', [
                'actor'     => $admin,
                'tenant_id' => $locked->tenant_id,
                'subject'   => $locked,
                'context'   => ['reason' => $reason, 'reference' => $locked->reference],
            ]);
        });
    }

    /** Mark due subscriptions as expired (called on tenant context resolution). */
    public function sweepExpiries(Tenant $tenant): void
    {
        $tenant->subscriptions()
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->where('ends_at', '<=', now())
            ->update(['status' => Subscription::STATUS_EXPIRED, 'updated_at' => now()]);
    }
}
