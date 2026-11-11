<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\SecurityTestCase;

class AuthenticationTest extends SecurityTestCase
{
    public function test_passwords_are_never_stored_in_plaintext(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $this->assertNotSame('Test#Pass12345', $user->password);
        $this->assertTrue(Hash::check('Test#Pass12345', $user->password));
        // Bcrypt in the test env; Argon2id is the configured production driver.
        $this->assertMatchesRegularExpression('/^\$2[ayb]\$/', $user->password);
    }

    public function test_api_never_returns_password_hashes(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        foreach ([
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test#Pass12345']),
            $this->asTenantUser($user)->getJson('/api/v1/me'),
            $this->asTenantUser($user)->getJson('/api/v1/users'),
        ] as $response) {
            $this->assertStringNotContainsString($user->password, $response->getContent());
            $this->assertStringNotContainsString('"password"', $response->getContent());
        }
    }

    public function test_login_lockout_after_repeated_failures(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $max = config('pos.throttle.login_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'WrongPass!123'])
                ->assertUnauthorized();
        }

        // Next attempt — even with the CORRECT password — is locked out.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test#Pass12345'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'login_locked');

        $this->assertDatabaseHas('audits', ['action' => 'auth.login_locked']);
        $this->assertDatabaseHas('audits', ['action' => 'auth.login_failed']);
    }

    public function test_failed_login_uses_generic_message_and_is_audited(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        // Wrong password for existing account
        $r1 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'WrongPass!123']);
        // Non-existent account
        $r2 = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@test.dev', 'password' => 'WrongPass!123']);

        $this->assertSame($r1->json('message'), $r2->json('message')); // no user enumeration
        $this->assertDatabaseHas('audits', ['action' => 'auth.login_failed']);
    }

    public function test_disabled_user_cannot_login_and_existing_token_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);
        $token  = $this->tokenForUser($user);

        $user->forceFill(['status' => User::STATUS_DISABLED])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test#Pass12345'])
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_disabled');
    }

    public function test_logout_revokes_the_session_token(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);
        $token  = $this->tokenForUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_password_change_revokes_other_sessions(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $sessionA = $this->tokenForUser($user); // "browser A"
        $sessionB = $this->tokenForUser($user); // "browser B"

        $this->withHeader('Authorization', 'Bearer '.$sessionB)
            ->postJson('/api/v1/auth/change-password', [
                'current_password'      => 'Test#Pass12345',
                'password'              => 'NewSecret#987',
                'password_confirmation' => 'NewSecret#987',
            ])
            ->assertOk();

        // Session A is dead, the session that changed the password stays alive.
        $this->withHeader('Authorization', 'Bearer '.$sessionA)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$sessionB)->getJson('/api/v1/me')->assertOk();
        $this->assertDatabaseHas('audits', ['action' => 'auth.password_changed']);
    }

    public function test_remember_me_extends_token_expiry(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $normal = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test#Pass12345']);
        $remember = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Test#Pass12345', 'remember' => true]);

        $normalExpiry = \Laravel\Sanctum\PersonalAccessToken::findToken($normal->json('token'))->expires_at;
        $rememberExpiry = \Laravel\Sanctum\PersonalAccessToken::findToken($remember->json('token'))->expires_at;

        $this->assertTrue($rememberExpiry->gt($normalExpiry));
        $this->assertTrue(now()->diffInDays($rememberExpiry) >= config('pos.tokens.tenant_remember_days') - 1);
    }

    public function test_wrong_account_type_tokens_are_rejected_across_planes(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);
        $admin  = $this->makeSuperAdmin();

        // Tenant token on the super-admin plane
        $this->withHeader('Authorization', 'Bearer '.$this->tokenForUser($user))
            ->getJson('/api/v1/super-admin/tenants')
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_account_type');

        // Super-admin token on the tenant plane
        $this->withHeader('Authorization', 'Bearer '.$this->tokenForSuperAdmin($admin))
            ->getJson('/api/v1/products')
            ->assertForbidden()
            ->assertJsonPath('code', 'wrong_account_type');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->getJson('/api/v1/super-admin/tenants')->assertUnauthorized();
        $this->postJson('/api/v1/sales', [])->assertUnauthorized();
    }
}
