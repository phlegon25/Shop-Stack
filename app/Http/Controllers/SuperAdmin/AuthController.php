<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Models\SuperAdmin;
use App\Services\AuditLogger;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Super Admin authentication — deliberately stronger than tenant auth:
 *  - stricter lockout (3 attempts / 30 min)
 *  - shorter session lifetime
 *  - mandatory TOTP two-factor: accounts without 2FA only receive a
 *    restricted "2fa-setup" token until enrollment is complete.
 */
class AuthController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private TotpService $totp,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->validated('email'));
        $key   = 'sa-login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, config('pos.throttle.sa_login_attempts'))) {
            $seconds = RateLimiter::availableIn($key);
            $this->audit->log('sa.login_locked', ['actor_type' => 'system', 'context' => ['email' => $email]]);

            return response()->json([
                'message'     => 'Too many failed attempts. Try again later.',
                'code'        => 'login_locked',
                'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }

        /** @var SuperAdmin|null $admin */
        $admin = SuperAdmin::where('email', $email)->first();

        if ($admin === null || ! Hash::check($request->validated('password'), $admin->password)) {
            RateLimiter::hit($key, config('pos.throttle.sa_lock_minutes') * 60);
            $this->audit->log('sa.login_failed', ['actor_type' => 'system', 'context' => ['email' => $email]]);

            return response()->json(['message' => 'Invalid credentials.', 'code' => 'invalid_credentials'], 401);
        }

        if (! $admin->isActive()) {
            return response()->json(['message' => 'This administrator account is disabled.', 'code' => 'account_disabled'], 403);
        }

        RateLimiter::clear($key);

        if ($admin->twoFactorEnabled()) {
            // Partial authentication: a short-lived challenge must be completed with the TOTP code.
            $challenge = Str::random(64);
            Cache::put(
                $this->challengeKey($challenge),
                $admin->id,
                now()->addMinutes(config('pos.tokens.two_factor_challenge_minutes')),
            );

            $this->audit->log('sa.login_2fa_challenge', ['actor' => $admin]);

            return response()->json([
                'two_factor_required' => true,
                'challenge'           => $challenge,
                'expires_in'          => config('pos.tokens.two_factor_challenge_minutes') * 60,
            ]);
        }

        // 2FA is not set up yet — issue a RESTRICTED token that may only be
        // used to enroll. Full access is impossible until 2FA is confirmed.
        $token = $admin->createToken('sa-2fa-setup', ['2fa-setup'], now()->addMinutes(30))->plainTextToken;

        return response()->json([
            'two_factor_setup_required' => true,
            'token'                     => $token,
            'token_type'                => 'Bearer',
        ]);
    }

    /** Complete the login challenge with a TOTP code → full super-admin token. */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $challenge = $request->validated('challenge');
        $adminId   = Cache::get($this->challengeKey($challenge));

        /** @var SuperAdmin|null $admin */
        $admin = $adminId ? SuperAdmin::find($adminId) : null;

        if ($admin === null || ! $admin->isActive()) {
            return response()->json(['message' => 'The challenge is invalid or has expired.', 'code' => 'invalid_challenge'], 422);
        }

        if (! $this->totp->verify((string) $admin->two_factor_secret, $request->validated('code'))) {
            // Wrong code does NOT consume the challenge (it stays valid for
            // its 5-minute TTL), but the endpoint is strictly rate limited.
            $this->audit->log('sa.login_2fa_failed', ['actor' => $admin]);

            return response()->json(['message' => 'Invalid authentication code.', 'code' => 'invalid_code'], 422);
        }

        Cache::forget($this->challengeKey($challenge)); // success = consumed (single use)

        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('sa.login', ['actor' => $admin]);

        return response()->json([
            'token'      => $this->issueFullToken($admin),
            'token_type' => 'Bearer',
            'admin'      => $admin->publicPayload(),
        ]);
    }

    /** Begin TOTP enrollment (allowed with the restricted setup token). */
    public function twoFactorSetup(Request $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $secret = $this->totp->generateSecret();
        $admin->forceFill([
            'two_factor_secret'       => $secret,
            'two_factor_confirmed_at' => null, // not trusted until confirmed
        ])->save();

        return response()->json([
            'secret'           => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $admin->email),
            'message'          => 'Scan with your authenticator app, then confirm with a code.',
        ]);
    }

    /**
     * Confirm TOTP enrollment. On success the current (restricted) token is
     * upgraded to a full super-admin session.
     */
    public function twoFactorConfirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        if ($admin->two_factor_secret === null) {
            return response()->json(['message' => 'Two-factor setup has not been started.', 'code' => 'setup_required'], 422);
        }

        if (! $this->totp->verify((string) $admin->two_factor_secret, $request->validated('code'))) {
            return response()->json(['message' => 'Invalid authentication code.', 'code' => 'invalid_code'], 422);
        }

        $admin->forceFill(['two_factor_confirmed_at' => now(), 'last_login_at' => now()])->save();

        // Upgrade the restricted token in place.
        $token = $admin->currentAccessToken();
        $token?->forceFill([
            'name'       => 'sa-session',
            'abilities'  => ['super-admin'],
            'expires_at' => now()->addHours(config('pos.tokens.super_admin_hours')),
        ])->save();

        $this->audit->log('sa.2fa_enabled', ['actor' => $admin]);
        $this->audit->log('sa.login', ['actor' => $admin]);

        return response()->json([
            'message' => 'Two-factor authentication enabled. You are now signed in.',
            'admin'   => $admin->publicPayload(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['admin' => $request->user()->publicPayload()]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();
        $admin->currentAccessToken()?->delete();

        $this->audit->log('sa.logout', ['actor' => $admin]);

        return response()->json(['message' => 'Logged out.']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        if (! Hash::check($request->validated('current_password'), $admin->password)) {
            return response()->json(['message' => 'Current password is incorrect.', 'code' => 'invalid_credentials'], 422);
        }

        $admin->forceFill(['password' => $request->validated('password')])->save();

        $current = $admin->currentAccessToken();
        $admin->tokens()->where('id', '!=', $current?->id)->delete();

        $this->audit->log('sa.password_changed', ['actor' => $admin]);

        return response()->json(['message' => 'Password changed. Other sessions were signed out.']);
    }

    private function issueFullToken(SuperAdmin $admin): string
    {
        return $admin
            ->createToken('sa-session', ['super-admin'], now()->addHours(config('pos.tokens.super_admin_hours')))
            ->plainTextToken;
    }

    private function challengeKey(string $challenge): string
    {
        return 'sa-2fa:'.hash('sha256', $challenge);
    }
}
