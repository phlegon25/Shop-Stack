<?php

namespace Tests\Feature;

use App\Models\Audit;
use Tests\SecurityTestCase;

class AuditLogTest extends SecurityTestCase
{
    public function test_all_security_sensitive_actions_are_logged(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => 5]);
        $admin   = $this->makeSuperAdmin(['permissions' => ['impersonate', 'settings.manage', 'payments.review', 'tenants.suspend']]);

        // login
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Test#Pass12345']);
        // logout
        $this->asTenantUser($owner)->postJson('/api/v1/auth/logout');
        // sale + stock adjustment
        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 50,
        ]);
        // payment submission
        $this->asTenantUser($owner)->postJson('/api/v1/billing/payments', [
            'plan_id' => 1, 'method' => 'momo', 'sender_number' => '024', 'reference' => 'AUD-'.uniqid(),
        ]);
        // payment approval
        $this->asSuperAdmin($admin)->postJson('/api/v1/super-admin/payments/1/review', ['action' => 'approve']);
        // settings change
        $this->asSuperAdmin($admin)->putJson('/api/v1/super-admin/settings/payment', ['momo_name' => 'New Name']);
        // suspension
        $this->asSuperAdmin($admin)->postJson("/api/v1/super-admin/tenants/{$tenant->id}/suspend", [
            'reason' => 'audit test', 'confirm' => true,
        ]);

        foreach ([
            'auth.login', 'auth.logout', 'sale.created',
            'payment.submitted', 'payment.approved',
            'platform.payment_settings_changed', 'tenant.suspended',
        ] as $action) {
            $this->assertDatabaseHas('audits', ['action' => $action]);
        }
    }

    public function test_audit_records_are_immutable(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Test#Pass12345']);
        $audit = Audit::first();
        $this->assertNotNull($audit);

        $this->expectException(\LogicException::class);
        $audit->update(['action' => 'tampered']);
    }

    public function test_audit_records_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Test#Pass12345']);

        $this->expectException(\LogicException::class);
        Audit::first()->delete();
    }

    public function test_secrets_never_appear_in_audit_contexts(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'Test#Pass12345']);

        $this->asTenantUser($owner)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Test#Pass12345',
            'password' => 'NewSecret#123', 'password_confirmation' => 'NewSecret#123',
        ]);

        foreach (Audit::all() as $audit) {
            $blob = json_encode($audit->context).' '.$audit->action;
            $this->assertStringNotContainsString('Test#Pass12345', $blob);
            $this->assertStringNotContainsString('NewSecret#123', $blob);
        }

        // Deny-listed keys can never be smuggled into an audit context.
        app(\App\Services\AuditLogger::class)->log('test.event', [
            'context' => ['password' => 'x', 'token' => 'y', 'safe' => 'ok'],
        ]);

        $last = Audit::orderByDesc('id')->first();
        $this->assertSame(['safe' => 'ok'], $last->context);
    }

    public function test_tenants_cannot_read_the_platform_audit_log(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $this->asTenantUser($owner)->getJson('/api/v1/super-admin/audits')->assertForbidden();
    }
}
