<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Tests\SecurityTestCase;

/**
 * UNAUTHORIZED USER CANNOT APPROVE PAYMENTS — and payment state can never
 * be manipulated by the paying tenant.
 */
class PaymentSecurityTest extends SecurityTestCase
{
    private function submitPayment($user, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->asTenantUser($user)->postJson('/api/v1/billing/payments', array_merge([
            'plan_id'       => Plan::first()->id,
            'method'        => 'momo',
            'sender_number' => '0240000000',
            'reference'     => 'REF-'.uniqid(),
        ], $overrides));
    }

    public function test_payment_is_always_created_pending_with_server_side_amount(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        // Attempts to smuggle status/amount/review fields are discarded.
        $this->submitPayment($owner, [
            'status'          => 'approved',
            'amount'          => 0.01,
            'reviewed_by'     => 1,
            'reviewed_at'     => now()->toISOString(),
            'subscription_id' => 99,
        ])->assertCreated()
          ->assertJsonPath('data.status', 'pending')
          ->assertJsonPath('data.amount', (string) Plan::first()->price);
    }

    public function test_tenant_can_never_approve_or_reject_payments(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->submitPayment($owner)->assertCreated();
        $payment = SubscriptionPayment::first();

        foreach (['approve'] as $action) {
            $this->asTenantUser($owner)
                ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => $action])
                ->assertForbidden();
        }

        $this->assertSame('pending', $payment->refresh()->status);
    }

    public function test_nonexistent_approval_endpoint_for_tenants(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        // There is no tenant-plane mutation route at all.
        $this->asTenantUser($owner)->putJson('/api/v1/billing/payments/1', ['status' => 'approved'])
            ->assertNotFound();
        $this->asTenantUser($owner)->patchJson('/api/v1/billing/payments/1', ['status' => 'approved'])
            ->assertNotFound();
    }

    public function test_approval_activates_subscription_transactionally_and_is_audited(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $admin  = $this->makeSuperAdmin();
        $plan   = Plan::orderBy('price')->first();

        $this->submitPayment($owner, ['plan_id' => $plan->id])->assertCreated();
        $payment = SubscriptionPayment::first();

        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'approve'])
            ->assertOk();

        $payment->refresh();
        $this->assertSame('approved', $payment->status);
        $this->assertSame($admin->id, $payment->reviewed_by);
        $this->assertNotNull($payment->subscription_id);

        $subscription = Subscription::findOrFail($payment->subscription_id);
        $this->assertTrue($subscription->isLive());
        $this->assertDatabaseHas('audits', ['action' => 'payment.approved', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('audits', ['action' => 'subscription.activated']);
    }

    public function test_payment_cannot_be_reviewed_twice(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $admin  = $this->makeSuperAdmin();

        $this->submitPayment($owner)->assertCreated();
        $payment = SubscriptionPayment::first();

        $this->asSuperAdmin($admin)->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'approve'])->assertOk();

        // Second approval attempt (different admin, same payment) — rejected.
        $admin2 = $this->makeSuperAdmin();
        $this->asSuperAdmin($admin2)
            ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'approve'])
            ->assertStatus(422);

        // And it can no longer be rejected either.
        $this->asSuperAdmin($admin2)
            ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'reject', 'reason' => 'late'])
            ->assertStatus(422);

        $this->assertSame('approved', $payment->refresh()->status);
        $this->assertDatabaseCount('subscriptions', 2); // trial + paid (no dupes)
    }

    public function test_rejection_requires_a_reason_and_is_audited(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $admin  = $this->makeSuperAdmin();

        $this->submitPayment($owner)->assertCreated();
        $payment = SubscriptionPayment::first();

        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'reject'])
            ->assertStatus(422); // reason missing

        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/payments/{$payment->id}/review", ['action' => 'reject', 'reason' => 'Reference not found on statement'])
            ->assertOk();

        $this->assertSame('rejected', $payment->refresh()->status);
        $this->assertDatabaseHas('audits', ['action' => 'payment.rejected']);
    }

    public function test_duplicate_payment_reference_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->submitPayment($owner, ['reference' => 'SAME-REF-1'])->assertCreated();

        // Even a DIFFERENT tenant cannot replay the same reference (anti-fraud).
        $tenantB = $this->makeTenant();
        $ownerB  = $this->makeUser($tenantB);

        $this->submitPayment($ownerB, ['reference' => 'SAME-REF-1'])->assertStatus(422);
    }

    public function test_payment_reference_is_validated(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->submitPayment($owner, ['reference' => "x'; DROP TABLE tenants;--"])->assertStatus(422);
        $this->submitPayment($owner, ['reference' => 'ab'])->assertStatus(422); // too short

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]); // table intact
    }
}
