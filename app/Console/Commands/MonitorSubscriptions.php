<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Daily subscription lifecycle engine:
 *
 *   live  → reminder at 14 / 7 / 3 / 1 days before end
 *   end   → EXPIRED + grace window opens (platform-configurable days)
 *   grace → tenant still fully operational, warned daily by the UI banner
 *   grace over → tenant AUTO-SUSPENDED (data is NEVER deleted)
 *
 * Every notification writes a dedupe ledger key first, so a re-run can never
 * double-notify. All state transitions are audit-logged with actor=system.
 */
class MonitorSubscriptions extends Command
{
    protected $signature = 'subscriptions:monitor';

    protected $description = 'Send subscription reminders, expire subscriptions into grace, and auto-suspend tenants past grace.';

    public function __construct(
        private NotificationService $notify,
        private AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $graceDays = max(0, (int) (PlatformSetting::instance()->grace_period_days ?? 3));
        $now = now();

        /*
        |------------------------------------------------------------------
        | 1) Reminders for live subscriptions (trialing / active)
        |------------------------------------------------------------------
        */
        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->where('ends_at', '>', $now)
            ->with(['tenant', 'plan'])
            ->chunkById(200, function ($subs) use ($now) {
                foreach ($subs as $sub) {
                    $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($sub->ends_at->copy()->startOfDay());

                    // Exactly ONE reminder per run day: the 14/7/3/1-day stage
                    // nearest to the actual time left.
                    $stage = collect([1, 3, 7, 14])->first(fn ($s) => $daysLeft <= $s);
                    if ($stage !== null
                        && $this->notify->firstTime($sub->tenant_id, "sub_remind:{$sub->id}:{$stage}")) {
                        $isTrial = $sub->status === Subscription::STATUS_TRIALING;
                        $this->notify->toTenant(
                            $sub->tenant_id,
                            'subscription_reminder',
                            ($isTrial ? 'Trial' : 'Subscription') . " ends in {$daysLeft} day(s)",
                            "Your {$sub->plan?->name} " . ($isTrial ? 'trial' : 'subscription')
                                . " ends on {$sub->ends_at->toFormattedDateString()}. Renew now to avoid interruption.",
                            ['subscription_id' => $sub->id, 'days_left' => $daysLeft],
                        );
                    }
                }
            });

        /*
        |------------------------------------------------------------------
        | 2) End reached: flip to EXPIRED and open the grace window
        |------------------------------------------------------------------
        */
        Subscription::query()
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->where('ends_at', '<=', $now)
            ->with(['tenant', 'plan'])
            ->chunkById(200, function ($subs) use ($graceDays) {
                foreach ($subs as $sub) {
                    $sub->forceFill([
                        'status'        => Subscription::STATUS_EXPIRED,
                        'grace_ends_at' => $graceDays > 0 ? $sub->ends_at->copy()->addDays($graceDays) : $sub->ends_at->copy(),
                    ])->save();

                    if ($this->notify->firstTime($sub->tenant_id, "sub_expired:{$sub->id}")) {
                        $msg = $graceDays > 0
                            ? "Your {$sub->plan?->name} subscription expired on {$sub->ends_at->toFormattedDateString()}. "
                                . "You have a {$graceDays}-day grace period (until {$sub->grace_ends_at->toFormattedDateString()}) "
                                . 'to renew before your workspace is suspended. Your data is always safe.'
                            : "Your {$sub->plan?->name} subscription expired on {$sub->ends_at->toFormattedDateString()}. Renew to keep working. Your data is always safe.";

                        $this->notify->toTenant($sub->tenant_id, 'subscription_expired',
                            'Subscription expired', $msg,
                            ['subscription_id' => $sub->id, 'grace_ends_at' => optional($sub->grace_ends_at)->toDateTimeString()]);
                    }

                    $this->audit->log('subscription.expired', [
                        'actor' => null,
                        'tenant_id' => $sub->tenant_id,
                        'context' => ['subscription_id' => $sub->id, 'plan' => $sub->plan?->name, 'grace_ends_at' => optional($sub->grace_ends_at)->toDateTimeString()],
                    ]);
                }
            });

        /*
        |------------------------------------------------------------------
        | 3) Grace over: auto-suspend (NEVER delete)
        |------------------------------------------------------------------
        */
        Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->whereDoesntHave('currentSubscription')
            ->whereDoesntHave('subscriptions', fn ($q) => $q
                ->where('status', Subscription::STATUS_EXPIRED)
                ->whereNotNull('grace_ends_at')
                ->where('grace_ends_at', '>', $now))
            ->whereHas('subscriptions') // a tenant that never subscribed is left alone
            ->chunkById(100, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $tenant->forceFill([
                        'status'           => Tenant::STATUS_SUSPENDED,
                        'suspended_at'     => now(),
                        'suspension_reason'=> 'Subscription expired and the grace period elapsed (automatic).',
                        'suspended_by'     => null, // system
                    ])->save();

                    if ($this->notify->firstTime($tenant->id, "tenant_suspended:{$tenant->id}:" . now()->toDateString())) {
                        $this->notify->toTenant($tenant->id, 'workspace_suspended',
                            'Workspace suspended',
                            'Your workspace was suspended because the subscription grace period elapsed. '
                                . 'ALL of your data is preserved and will be fully restored as soon as you renew.');
                    }

                    $this->notify->toPlatform('tenant_suspended',
                        "Tenant auto-suspended: {$tenant->name}",
                        "{$tenant->name} was automatically suspended (subscription grace elapsed).",
                        ['tenant_id' => $tenant->id]);

                    $this->audit->log('tenant.auto_suspended', [
                        'actor' => null,
                        'tenant_id' => $tenant->id,
                        'context' => ['tenant_id' => $tenant->id, 'reason' => 'grace_elapsed'],
                    ]);
                }
            });

        /*
        |------------------------------------------------------------------
        | 4) Product expiry alerts (per tenant, weekly per product at most)
        |------------------------------------------------------------------
        */
        Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $warnDays = (int) optional(
                    \App\Models\BusinessSetting::withoutTenancy()->where('tenant_id', $tenant->id)->first()
                )->expiry_warn_days ?: 14;

                $expiring = Product::withoutTenancy()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($warnDays)->toDateString()])
                    ->limit(25)
                    ->get(['id', 'name', 'expiry_date']);

                foreach ($expiring as $product) {
                    $weekKey = now()->format('oW'); // ISO week-based dedupe
                    if ($this->notify->firstTime($tenant->id, "expiry:{$product->id}:{$weekKey}")) {
                        $days = (int) now()->startOfDay()->diffInDays($product->expiry_date);
                        $this->notify->toTenant($tenant->id, 'product_expiry',
                            "Expiring soon: {$product->name}",
                            "{$product->name} expires in {$days} day(s) ({$product->expiry_date->toFormattedDateString()}).",
                            ['product_id' => $product->id]);
                    }
                }
            }
        });

        $this->info('Subscription monitor run complete.');

        return self::SUCCESS;
    }
}
