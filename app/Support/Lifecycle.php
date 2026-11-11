<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Canonical tenant lifecycle resolver — ONE place both the tenant app and
 * the super-admin console derive the same stage from:
 *
 *   TRIAL → ACTIVE → EXPIRING → GRACE → EXPIRED → SUSPENDED / CANCELLED
 */
class Lifecycle
{
    public const STAGES = ['TRIAL', 'ACTIVE', 'EXPIRING', 'GRACE', 'EXPIRED', 'SUSPENDED', 'CANCELLED', 'NONE'];

    /** Resolve the current stage for a tenant (loads relations once). */
    public static function stage(Tenant $tenant): array
    {
        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            return [
                'stage'  => 'SUSPENDED',
                'detail' => $tenant->suspension_reason,
            ];
        }

        /** @var Subscription|null $sub */
        $sub = $tenant->relationLoaded('currentSubscription')
            ? $tenant->currentSubscription
            : $tenant->operationalSubscription() ?? $tenant->subscriptions()->latest('ends_at')->first();

        // Fall back to the operational (grace) subscription when needed.
        $op = $tenant->currentSubscription ?: $tenant->operationalSubscription();

        if ($op === null) {
            $last = $tenant->subscriptions()->latest('ends_at')->first();

            return [
                'stage' => $last?->status === Subscription::STATUS_CANCELLED ? 'CANCELLED' : 'EXPIRED',
                'detail' => null,
                'subscription' => $last,
            ];
        }

        if ($op->status === Subscription::STATUS_EXPIRED) {
            $graceLeft = $op->grace_ends_at ? max(0, (int) now()->diffInDays($op->grace_ends_at, false)) : 0;

            return [
                'stage'           => 'GRACE',
                'days_left'       => $graceLeft,
                'grace_ends_at'   => $op->grace_ends_at,
                'subscription'    => $op,
            ];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($op->ends_at->copy()->startOfDay());

        return [
            'stage'        => $op->status === Subscription::STATUS_TRIALING
                ? 'TRIAL'
                : ($daysLeft <= 7 ? 'EXPIRING' : 'ACTIVE'),
            'days_left'    => $daysLeft,
            'ends_at'      => $op->ends_at,
            'subscription' => $op,
        ];
    }

    /** Compact per-row payload for tenant listings. */
    public static function badge(Tenant $tenant): array
    {
        $info = self::stage($tenant);
        $sub  = $info['subscription'] ?? null;

        return [
            'stage'     => $info['stage'],
            'days_left' => $info['days_left'] ?? null,
            'plan'      => $sub?->plan?->name,
            'ends_at'   => $sub?->ends_at?->toDateString(),
        ];
    }
}
