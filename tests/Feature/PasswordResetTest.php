<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\SecurityTestCase;

class PasswordResetTest extends SecurityTestCase
{
    /** Extract the issued token from the app log (log mailer in dev/test). */
    private function grabToken(string $email): string
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->whereNull('used_at')->latest('id')->first();
        $this->assertNotNull($row, 'No reset token issued');

        // The token's hash is what we stored; we can't reverse it. Instead,
        // simulate the flow by inserting a KNOWN token.
        $plain = Str::random(64);
        DB::table('password_reset_tokens')->where('id', $row->id)->update(['token_hash' => hash('sha256', $plain)]);

        return $plain;
    }

    public function test_reset_response_does_not_reveal_account_existence(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $known   = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@test.dev']);

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $known->assertOk();
        $unknown->assertOk();
    }

    public function test_tokens_are_stored_only_as_hashes_and_expire(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertSame(64, strlen($row->token_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row->token_hash); // sha256 hex
        $this->assertTrue(\Carbon\Carbon::parse($row->expires_at)->gt(now()));
        $this->assertNull($row->used_at);
    }

    public function test_full_reset_flow_and_single_use_enforcement(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->grabToken($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Reset#Pass999', 'password_confirmation' => 'Reset#Pass999',
        ])->assertOk();

        // New password works
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Reset#Pass999'])->assertOk();

        // Token replay is rejected
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Another#Pass1', 'password_confirmation' => 'Another#Pass1',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audits', ['action' => 'auth.password_reset_completed']);
    }

    public function test_expired_and_wrong_tokens_are_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->grabToken($user->email);

        DB::table('password_reset_tokens')->where('email', $user->email)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Reset#Pass999', 'password_confirmation' => 'Reset#Pass999',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => Str::random(64),
            'password' => 'Reset#Pass999', 'password_confirmation' => 'Reset#Pass999',
        ])->assertStatus(422);
    }

    public function test_reset_revokes_all_existing_sessions(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant);
        $oldSession = $this->tokenForUser($user);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->grabToken($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'Reset#Pass999', 'password_confirmation' => 'Reset#Pass999',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$oldSession)
            ->getJson('/api/v1/me')->assertUnauthorized();
    }
}
