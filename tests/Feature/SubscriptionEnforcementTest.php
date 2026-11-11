<?php

namespace Tests\Feature;

use Tests\SecurityTestCase;

/**
 * EXPIRED TENANT CANNOT BYPASS SUBSCRIPTION RESTRICTIONS — no matter what
 * the client sends. Enforcement is purely server-side.
 */
class SubscriptionEnforcementTest extends SecurityTestCase
{
    public function test_expired_tenant_cannot_create_business_records(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => 50]);

        $this->setSubscriptionWindow($tenant, live: false); // expired yesterday

        // Even when the client lies about subscription status, the server decides.
        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items'          => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_paid'    => 100,
            'subscription_status' => 'active',   // ignored
            'subscription_expiry' => '2099-01-01', // ignored
        ])->assertForbidden()->assertJsonPath('code', 'subscription_expired');

        $this->asTenantUser($owner)->postJson('/api/v1/products', [
            'sku' => 'X', 'name' => 'X', 'cost_price' => 1, 'selling_price' => 1,
        ])->assertForbidden()->assertJsonPath('code', 'subscription_expired');

        $this->assertDatabaseHas('audits', ['action' => 'authz.denied']);
    }

    public function test_expired_tenant_keeps_read_only_access_and_can_pay(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant);

        $this->setSubscriptionWindow($tenant, live: false);

        $this->asTenantUser($owner)->getJson('/api/v1/products')->assertOk();
        $this->asTenantUser($owner)->getJson('/api/v1/billing/status')->assertOk();

        // Renewal path stays open.
        $this->asTenantUser($owner)->postJson('/api/v1/billing/payments', [
            'plan_id' => 1, 'method' => 'momo', 'sender_number' => '0240000000',
            'reference' => 'REN-'.uniqid(),
        ])->assertCreated();
    }

    public function test_renewal_restores_business_operations(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => 50]);
        $admin   = $this->makeSuperAdmin();

        $this->setSubscriptionWindow($tenant, live: false);

        $this->asTenantUser($owner)->postJson('/api/v1/billing/payments', [
            'plan_id' => 2, 'method' => 'bank', 'sender_name' => 'Ops',
            'reference' => 'REN-'.uniqid(),
        ])->assertCreated();

        $this->asSuperAdmin($admin)->postJson('/api/v1/super-admin/payments/1/review', ['action' => 'approve'])
            ->assertOk();

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 100,
        ])->assertCreated();
    }

    public function test_suspended_tenant_is_fully_blocked_even_for_reads(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant);

        $tenant->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'Fraud review'])->save();

        $this->asTenantUser($owner)->getJson('/api/v1/products')
            ->assertForbidden()->assertJsonPath('code', 'tenant_suspended');

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 10,
        ])->assertForbidden()->assertJsonPath('code', 'tenant_suspended');
    }

    public function test_subscription_status_is_never_taken_from_client_input(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $admin  = $this->makeSuperAdmin();

        // Tenant tries to "adjust" its own subscription through the SA plane shape
        $this->asTenantUser($owner)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/subscription", [
                'plan_id' => 3, 'note' => 'self-granted',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('subscriptions', ['tenant_id' => $tenant->id, 'granted_by' => 'super_admin:'.$owner->id]);
    }
}
