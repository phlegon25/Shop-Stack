<?php

namespace Tests\Feature;

use App\Models\ImpersonationSession;
use Tests\SecurityTestCase;

class ImpersonationTest extends SecurityTestCase
{
    public function test_impersonation_requires_reason_and_permission(): void
    {
        $admin  = $this->makeSuperAdmin(['permissions' => ['impersonate']]);
        $tenant = $this->makeTenant();
        $this->makeUser($tenant); // owner

        // Missing reason
        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", [])
            ->assertStatus(422);

        // Too-short reason
        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", ['reason' => 'why'])
            ->assertStatus(422);

        // Admin WITHOUT the permission
        $noPerm = $this->makeSuperAdmin(['permissions' => ['tenants.view']]);
        $this->asSuperAdmin($noPerm)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", ['reason' => 'support request #1234'])
            ->assertForbidden();
    }

    public function test_impersonation_issues_short_lived_token_and_logs_event(): void
    {
        $admin  = $this->makeSuperAdmin(['permissions' => ['impersonate']]);
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $response = $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", [
                'reason' => 'Investigating support ticket #42',
            ])->assertCreated();

        $token   = $response->json('token');
        $session = ImpersonationSession::first();

        $this->assertTrue($response->json('banner.show'));
        $this->assertSame($tenant->id, $session->tenant_id);
        $this->assertSame($admin->id, $session->super_admin_id);
        $this->assertTrue($session->expires_at->lte(now()->addMinutes(config('pos.tokens.impersonation_minutes') + 1)));

        // Tenant password is NEVER exposed in the response.
        $this->assertStringNotContainsString($owner->password, $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());

        $this->assertDatabaseHas('audits', ['action' => 'impersonation.started', 'actor_id' => $admin->id]);

        // The impersonation token works on the tenant plane and identifies itself.
        $me = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me');
        $me->assertOk()
            ->assertJsonPath('impersonation.session_id', $session->id)
            ->assertJsonPath('impersonation.admin_id', $admin->id);
        $me->assertHeader('X-Impersonation-Active', 'true');
    }

    public function test_actions_performed_while_impersonating_are_stamped(): void
    {
        $admin   = $this->makeSuperAdmin(['permissions' => ['impersonate']]);
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => 10]);

        $token = $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", ['reason' => 'Fixing sale for ticket #7'])
            ->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 50,
        ])->assertCreated();

        $session = ImpersonationSession::first();

        // Stock movement carries the impersonation session id…
        $this->assertDatabaseHas('stock_movements', [
            'impersonation_session_id' => $session->id,
            'type' => 'sale',
        ]);
        // …and the audit trail attributes the action to the impersonation.
        $this->assertDatabaseHas('audits', [
            'action' => 'sale.created',
            'impersonation_session_id' => $session->id,
        ]);
    }

    public function test_stopping_session_revokes_the_token_immediately(): void
    {
        $admin  = $this->makeSuperAdmin(['permissions' => ['impersonate']]);
        $tenant = $this->makeTenant();
        $this->makeUser($tenant);

        $token = $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", ['reason' => 'support session test'])
            ->json('token');

        $session = ImpersonationSession::first();

        $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/impersonations/{$session->id}/stop")->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')->assertUnauthorized();

        $this->assertDatabaseHas('audits', ['action' => 'impersonation.ended']);
    }

    public function test_expired_impersonation_session_rejects_requests(): void
    {
        $admin  = $this->makeSuperAdmin(['permissions' => ['impersonate']]);
        $tenant = $this->makeTenant();
        $this->makeUser($tenant);

        $token = $this->asSuperAdmin($admin)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/impersonate", ['reason' => 'short lived session test'])
            ->json('token');

        // Force the session into the past.
        ImpersonationSession::first()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'impersonation_expired');
    }
}
