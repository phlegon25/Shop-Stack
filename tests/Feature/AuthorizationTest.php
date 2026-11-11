<?php

namespace Tests\Feature;

use Tests\SecurityTestCase;

/**
 * CASHIER CANNOT PERFORM ADMINISTRATIVE ACTIONS — and other role rules —
 * verified server-side regardless of what a UI would show.
 */
class AuthorizationTest extends SecurityTestCase
{
    private function fixtures(): array
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant, 'owner');
        $admin   = $this->makeUser($tenant, 'admin');
        $cashier = $this->makeUser($tenant, 'cashier');
        $product = $this->makeProduct($tenant, ['stock_quantity' => 100]);

        return [$tenant, $owner, $admin, $cashier, $product];
    }

    public function test_cashier_cannot_manage_users(): void
    {
        [$tenant, $owner, $admin, $cashier] = $this->fixtures();

        $this->asTenantUser($cashier)->getJson('/api/v1/users')->assertForbidden();

        $this->asTenantUser($cashier)->postJson('/api/v1/users', [
            'name' => 'evil', 'email' => 'evil@test.dev', 'role' => 'cashier',
            'password' => 'Pass#123456a', 'password_confirmation' => 'Pass#123456a',
        ])->assertForbidden();
    }

    public function test_cashier_cannot_manage_products_categories_or_stock(): void
    {
        [$tenant, $owner, $admin, $cashier, $product] = $this->fixtures();

        $this->asTenantUser($cashier)->postJson('/api/v1/products', [
            'sku' => 'P1', 'name' => 'Nope', 'cost_price' => 1, 'selling_price' => 1,
        ])->assertForbidden();

        $this->asTenantUser($cashier)->postJson('/api/v1/categories', ['name' => 'Nope'])
            ->assertForbidden();

        $this->asTenantUser($cashier)->postJson('/api/v1/stock/adjustments', [
            'product_id' => $product->id, 'quantity_change' => 500, 'note' => 'free stock',
        ])->assertForbidden();
    }

    public function test_cashier_cannot_void_sales_or_manage_purchases(): void
    {
        [$tenant, $owner, $admin, $cashier, $product] = $this->fixtures();

        $saleId = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 50,
        ])->assertCreated()->json('data.id');

        $this->asTenantUser($cashier)->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'nope'])
            ->assertForbidden();

        $this->asTenantUser($cashier)->postJson('/api/v1/purchases', [
            'reference' => 'PO-1', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 5]],
        ])->assertForbidden();

        $this->asTenantUser($cashier)->getJson('/api/v1/reports/top-products')->assertForbidden();
    }

    public function test_cashier_can_do_their_actual_job(): void
    {
        [$tenant, $owner, $admin, $cashier, $product] = $this->fixtures();

        $this->asTenantUser($cashier)->getJson('/api/v1/products')->assertOk();
        $this->asTenantUser($cashier)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'amount_paid' => 100,
        ])->assertCreated();
    }

    public function test_role_cannot_be_assigned_by_client_input(): void
    {
        [$tenant, $owner] = $this->fixtures();

        // Registers as a tenant; attempts to smuggle role/permissions.
        $response = $this->postJson('/api/v1/auth/register', [
            'business_name' => 'Evil Shop',
            'owner_name'    => 'Evil',
            'email'         => 'evil2@test.dev',
            'password'      => 'Pass#123456a',
            'password_confirmation' => 'Pass#123456a',
            'role'          => 'super_admin',
            'permissions'   => ['*'],
            'is_super_admin'=> true,
        ])->assertCreated();

        $created = \App\Models\User::where('email', 'evil2@test.dev')->first();
        $this->assertSame('owner', $created->role); // always tenant owner, nothing else
        $this->assertDatabaseMissing('super_admins', ['email' => 'evil2@test.dev']);
    }

    public function test_owner_role_cannot_be_granted_or_modified_via_api(): void
    {
        [$tenant, $owner, $admin, $cashier] = $this->fixtures();

        // Cannot create an owner
        $this->asTenantUser($owner)->postJson('/api/v1/users', [
            'name' => 'CoOwner', 'email' => 'co@test.dev', 'role' => 'owner',
            'password' => 'Pass#123456a', 'password_confirmation' => 'Pass#123456a',
        ])->assertStatus(422);

        // Cannot modify an owner account
        $this->asTenantUser($owner)->putJson("/api/v1/users/{$owner->id}", ['role' => 'cashier'])
            ->assertStatus(422); // self-modification guard

        $this->asTenantUser($admin)->putJson("/api/v1/users/{$owner->id}", ['status' => 'disabled'])
            ->assertForbidden(); // admins lack users.manage entirely
    }

    public function test_super_admin_with_limited_permissions_is_blocked_elsewhere(): void
    {
        $limited = $this->makeSuperAdmin(['permissions' => ['tenants.view']]);
        $settingsBearer = $this->tokenForSuperAdmin($limited);

        $this->withHeader('Authorization', 'Bearer '.$settingsBearer)
            ->getJson('/api/v1/super-admin/tenants')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$settingsBearer)
            ->putJson('/api/v1/super-admin/settings/payment', ['momo_number' => '0240000000'])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$settingsBearer)
            ->postJson('/api/v1/super-admin/payments/1/review', ['action' => 'approve'])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$settingsBearer)
            ->postJson('/api/v1/super-admin/tenants/1/impersonate', ['reason' => 'test reason here'])
            ->assertForbidden();
    }
}
