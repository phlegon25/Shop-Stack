<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Product;
use App\Models\User;

/**
 * Hard, plan-driven caps. A null limit means "unlimited". The check reads
 * the tenant's CURRENT plan from its operational subscription — limits are
 * never taken from client input and never hardcoded per-tier in PHP.
 */
class PlanLimits
{
    public function __construct(private TenantContext $context) {}

    public function currentPlan(): ?Plan
    {
        return $this->context->tenant()->operationalSubscription()?->plan;
    }

    public function assertProductSlot(): void
    {
        $limit = $this->currentPlan()?->product_limit;

        if ($limit !== null && Product::count() >= (int) $limit) {
            abort(422, "Your plan allows up to {$limit} products. Upgrade your plan to add more.");
        }
    }

    public function assertUserSlot(): void
    {
        $limit = $this->currentPlan()?->user_limit;

        if ($limit !== null && User::count() >= (int) $limit) {
            abort(422, "Your plan allows up to {$limit} user accounts. Upgrade your plan to add more.");
        }
    }
}
