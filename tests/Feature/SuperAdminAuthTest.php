<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use Tests\SecurityTestCase;

class SuperAdminAuthTest extends SecurityTestCase
{
    public function test_login_without_2fa_only_grants_a_restricted_setup_token(): void
    {
        $admin = $this->makeSuperAdmin(); // 2FA not yet enrolled

        $response = $this->postJson('/api/v1/super-admin/login', [
            'email'    => $admin->email,
            'password' => 'SuperSecret#12345',
        ])->assertOk();

        $this->assertTrue($response->json('two_factor_setup_required'));
        $token = $response->json('token');

        // The restricted token cannot touch real platform endpoints.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/super-admin/tenants')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/super-admin/me')
            ->assertForbidden();
    }

    public function test_full_2fa_enrollment_grants_full_access(): void
    {
        $admin = $this->makeSuperAdmin();

        $fullToken = $this->loginSuperAdmin($admin);

        $this->withHeader('Authorization', 'Bearer '.$fullToken)
            ->getJson('/api/v1/super-admin/me')
            ->assertOk()
            ->assertJsonPath('admin.two_factor_enabled', true);

        $this->assertDatabaseHas('audits', ['action' => 'sa.2fa_enabled']);
        $this->assertDatabaseHas('audits', ['action' => 'sa.login']);
    }

    public function test_enrolled_admin_must_complete_totp_challenge(): void
    {
        $admin  = $this->makeSuperAdmin();
        $secret = $this->enableTwoFactor($admin);

        $response = $this->postJson('/api/v1/super-admin/login', [
            'email'    => $admin->email,
            'password' => 'SuperSecret#12345',
        ])->assertOk();

        $this->assertTrue($response->json('two_factor_required'));
        $challenge = $response->json('challenge');
        $this->assertArrayNotHasKey('token', $response->json()); // NO token before 2FA

        // Wrong code rejected
        $this->postJson('/api/v1/super-admin/two-factor/challenge', [
            'challenge' => $challenge, 'code' => '000000',
        ])->assertStatus(422);

        // Right code → full token
        $code = app(\App\Services\TotpService::class)->currentCode($secret);
        $ok = $this->postJson('/api/v1/super-admin/two-factor/challenge', [
            'challenge' => $challenge, 'code' => $code,
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$ok->json('token'))
            ->getJson('/api/v1/super-admin/me')->assertOk();
    }

    public function test_2fa_challenge_is_single_use(): void
    {
        $admin  = $this->makeSuperAdmin();
        $secret = $this->enableTwoFactor($admin);

        $challenge = $this->postJson('/api/v1/super-admin/login', [
            'email' => $admin->email, 'password' => 'SuperSecret#12345',
        ])->json('challenge');

        $code = app(\App\Services\TotpService::class)->currentCode($secret);

        $this->postJson('/api/v1/super-admin/two-factor/challenge', ['challenge' => $challenge, 'code' => $code])
            ->assertOk();

        // Replay the same challenge — must fail.
        $this->postJson('/api/v1/super-admin/two-factor/challenge', ['challenge' => $challenge, 'code' => $code])
            ->assertStatus(422);
    }

    public function test_super_admin_lockout_is_stricter_than_tenant_lockout(): void
    {
        $admin = $this->makeSuperAdmin();
        $max   = config('pos.throttle.sa_login_attempts'); // 3

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/v1/super-admin/login', ['email' => $admin->email, 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/super-admin/login', ['email' => $admin->email, 'password' => 'SuperSecret#12345'])
            ->assertStatus(429);
    }

    public function test_disabled_super_admin_cannot_login(): void
    {
        $admin = $this->makeSuperAdmin(['is_active' => false]);

        $this->postJson('/api/v1/super-admin/login', ['email' => $admin->email, 'password' => 'SuperSecret#12345'])
            ->assertForbidden();
    }

    public function test_tenant_user_can_never_reach_super_admin_endpoints(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $endpoints = [
            ['getJson', '/api/v1/super-admin/tenants'],
            ['getJson', '/api/v1/super-admin/payments'],
            ['getJson', '/api/v1/super-admin/settings/payment'],
            ['getJson', '/api/v1/super-admin/audits'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $this->asTenantUser($owner)->{$method}($url)->assertForbidden();
        }
    }
}
