<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\TenantRegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PasswordResetService;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private SubscriptionService $subscriptions,
        private PasswordResetService $resets,
        private TenantContext $context,
    ) {}

    /**
     * Register a new tenant workspace (tenant + owner + free trial).
     */
    public function register(TenantRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$tenant, $user] = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name'          => $data['business_name'],
                'slug'          => $this->uniqueSlug($data['business_name']),
                'email'         => $data['email'],
                'phone'         => $data['phone'] ?? null,
                'business_type' => $data['business_type'] ?? null,
                'address'       => $data['address'] ?? null,
                'city'          => $data['city'] ?? null,
                'region'        => $data['region'] ?? null,
                'country'       => $data['country'] ?? 'Ghana',
                'currency'      => $data['currency'] ?? 'GHS',
            ]);

            $user = new User([
                'name'     => $data['owner_name'],
                'email'    => $data['email'],
                'password' => $data['password'], // hashed via model cast (Argon2id)
            ]);
            $user->tenant_id   = $tenant->id;
            $user->role        = User::ROLE_OWNER;
            $user->status      = User::STATUS_ACTIVE;
            $user->password_changed_at = now();
            $user->save();

            return [$tenant, $user];
        });

        $this->subscriptions->startTrial($tenant);
        $this->provisionWorkspace($tenant, $user, $data);

        $this->audit->log('tenant.registered', [
            'actor'   => $user,
            'subject' => $tenant,
            'context' => ['business' => $tenant->name],
        ]);

        $token = $this->issueToken($user, remember: false);

        return response()->json([
            'message'      => 'Workspace created. Your free trial has started.',
            'token'        => $token,
            'token_type'   => 'Bearer',
            'user'         => $user->publicPayload(),
            'tenant'       => $this->tenantPayload($tenant),
        ], 201);
    }

    /**
     * Tenant login with per-email+IP throttling and lockout.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->validated('email'));
        $key   = $this->throttleKey($email, $request->ip());

        if (RateLimiter::tooManyAttempts($key, config('pos.throttle.login_attempts'))) {
            $seconds = RateLimiter::availableIn($key);
            $this->audit->log('auth.login_locked', [
                'actor_type' => 'system',
                'context'    => ['email' => $email],
            ]);

            return response()->json([
                'message'     => 'Too many failed attempts. Try again later.',
                'code'        => 'login_locked',
                'retry_after' => $seconds,
            ], 429)->header('Retry-After', (string) $seconds);
        }

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($key, config('pos.throttle.login_lock_minutes') * 60);

            $this->audit->log('auth.login_failed', [
                'actor_type' => 'system',
                'tenant_id'  => $user?->tenant_id,
                'context'    => ['email' => $email],
            ]);

            return response()->json(['message' => 'Invalid credentials.', 'code' => 'invalid_credentials'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'This account has been disabled.', 'code' => 'account_disabled'], 403);
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->log('auth.login', ['actor' => $user]);

        $tenant = $user->tenant->load('currentSubscription.plan');

        return response()->json([
            'token'      => $this->issueToken($user, (bool) $request->validated('remember', false)),
            'token_type' => 'Bearer',
            'user'       => $user->publicPayload(),
            'tenant'     => $this->tenantPayload($tenant),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user   = $request->user();
        $tenant = $this->context->tenant()->load('currentSubscription.plan');

        $payload = [
            'user'        => $user->publicPayload() + ['permissions' => $user->permissions()],
            'tenant'      => $this->tenantPayload($tenant),
        ];

        if ($this->context->isImpersonated()) {
            // Lets the client render a persistent impersonation banner.
            $payload['impersonation'] = $this->context->impersonation();
        }

        return response()->json($payload);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        $this->audit->log('auth.logout', ['actor' => $user]);

        return response()->json(['message' => 'Logged out.']);
    }

    /** Sign out of every device — revokes all tokens. */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->tokens()->delete();

        $this->audit->log('auth.logout_all', ['actor' => $user]);

        return response()->json(['message' => 'All sessions signed out.']);
    }

    /**
     * Change own password. All OTHER sessions are revoked after the change.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            $this->audit->log('auth.password_change_failed', ['actor' => $user]);

            return response()->json(['message' => 'Current password is incorrect.', 'code' => 'invalid_credentials'], 422);
        }

        $user->forceFill([
            'password'            => $request->validated('password'),
            'password_changed_at' => now(),
        ])->save();

        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current?->id)->delete();

        $this->audit->log('auth.password_changed', ['actor' => $user]);

        return response()->json(['message' => 'Password changed. Other sessions were signed out.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->resets->sendResetLink('user', Str::lower($request->validated('email')));

        // Identical response whether or not the account exists.
        return response()->json(['message' => 'If the account exists, a reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $ok = $this->resets->reset(
            'user',
            Str::lower($request->validated('email')),
            $request->validated('token'),
            $request->validated('password'),
        );

        if (! $ok) {
            return response()->json(['message' => 'The reset token is invalid or has expired.', 'code' => 'invalid_token'], 422);
        }

        return response()->json(['message' => 'Password has been reset. Please sign in.']);
    }

    private function issueToken(User $user, bool $remember): string
    {
        $expires = $remember
            ? now()->addDays(config('pos.tokens.tenant_remember_days'))
            : now()->addHours(config('pos.tokens.tenant_hours'));

        return $user
            ->createToken($remember ? 'tenant-remember' : 'tenant-session', ['tenant'], $expires)
            ->plainTextToken;
    }

    private function tenantPayload(Tenant $tenant): array
    {
        $subscription = $tenant->currentSubscription;

        return [
            'id'     => $tenant->id,
            'name'   => $tenant->name,
            'slug'   => $tenant->slug,
            'status' => $tenant->status,
            'subscription' => $subscription ? [
                'plan'       => $subscription->plan?->name,
                'status'     => $subscription->status,
                'ends_at'    => $subscription->ends_at->toISOString(),
                'days_left'  => max(0, now()->diffInDays($subscription->ends_at, false)),
            ] : null,
        ];
    }

    /**
     * Bootstrap a fresh workspace: business settings + default catalogue
     * (expense categories + measurement units) + welcome notification.
     * The onboarding wizard then guides the owner through the rest.
     * NB: runs outside the tenant middleware pipeline, so context is set manually.
     */
    private function provisionWorkspace(Tenant $tenant, User $owner, array $data): void
    {
        app(\App\Support\TenantContext::class)->setUser($owner);

        // NB: tenant_id is assigned as a plain attribute — it is deliberately
        // NOT in any fillable list, so firstOrCreate-style fills are avoided.
        $settings = new \App\Models\BusinessSetting();
        $settings->tenant_id = $tenant->id;
        $settings->currency  = $data['currency'] ?? 'GHS';
        $settings->save();

        foreach ([
            'Rent', 'Utilities', 'Salaries & Wages', 'Transport & Logistics',
            'Stock Purchase', 'Marketing & Advertising', 'Equipment & Repairs',
            'Professional Fees', 'Taxes & Levies', 'Miscellaneous',
        ] as $name) {
            $category = new \App\Models\ExpenseCategory();
            $category->tenant_id = $tenant->id;
            $category->name      = $name;
            $category->is_system = true;
            $category->save();
        }

        foreach (['Piece', 'Pack', 'Box', 'Bottle', 'Bag', 'Sachet', 'Dozen', 'Kg', 'Litre'] as $unit) {
            $row = new \App\Models\Unit();
            $row->tenant_id = $tenant->id;
            $row->name      = $unit;
            $row->save();
        }

        app(\App\Services\NotificationService::class)->toTenant(
            $tenant->id, 'welcome',
            'Welcome to ' . config('pos.platform_name') . '!',
            'Your workspace is ready. Follow the setup wizard to add your first product, '
                . 'then start selling. Your free trial is active — no card required.',
        );

        $businessType = $data['business_type'] ?? 'business';
        app(\App\Services\NotificationService::class)->toPlatform(
            'tenant_registered',
            "New tenant: {$tenant->name}",
            "{$tenant->name} ({$businessType}) just registered and started a trial.",
            ['tenant_id' => $tenant->id],
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'shop';

        do {
            $slug = $base.'-'.Str::lower(Str::random(4));
        } while (Tenant::where('slug', $slug)->exists());

        return $slug;
    }

    private function throttleKey(string $email, ?string $ip): string
    {
        return 'login:'.hash('sha256', $email.'|'.$ip);
    }
}
