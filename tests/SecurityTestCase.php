<?php

namespace Tests;

use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\TotpService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    protected $seed = true; // plans + super admin + platform settings (no demo data in testing)

    protected TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = app(TenantContext::class);
    }

    /* ----------------------------------------------------------------
     |  Fixture factories
     * ---------------------------------------------------------------- */

    protected function makeTenant(array $attributes = [], bool $withTrial = true): Tenant
    {
        $tenant = Tenant::create(array_merge([
            'name' => 'Tenant '.Str::random(6),
            'slug' => 'tenant-'.Str::lower(Str::random(8)),
        ], $attributes));

        if ($withTrial) {
            app(SubscriptionService::class)->startTrial($tenant);
        }

        return $tenant->refresh();
    }

    protected function makeUser(Tenant $tenant, string $role = User::ROLE_OWNER, array $attributes = []): User
    {
        $user = new User();
        $user->fill(array_merge([
            'name'     => ucfirst($role).' '.Str::random(5),
            'email'    => Str::lower(Str::random(8)).'@test.dev',
            'password' => 'Test#Pass12345',
        ], array_diff_key($attributes, array_flip(['status', 'role']))));
        $user->tenant_id = $tenant->id;
        $user->role      = $role;
        $user->status    = $attributes['status'] ?? User::STATUS_ACTIVE;
        $user->password_changed_at = Carbon::now();
        $user->save();

        return $user;
    }

    /** Grant a live (or expired when $live=false) subscription window. */
    protected function setSubscriptionWindow(Tenant $tenant, bool $live): void
    {
        $tenant->subscriptions()->update([
            'status'     => Subscription::STATUS_ACTIVE,
            'starts_at'  => now()->subDays(10),
            'ends_at'    => $live ? now()->addDays(10) : now()->subDay(),
            'updated_at' => now(),
        ]);
    }

    protected function makeSuperAdmin(array $attributes = []): SuperAdmin
    {
        $admin = new SuperAdmin();
        $admin->fill(array_merge([
            'name'     => 'Admin '.Str::random(5),
            'email'    => Str::lower(Str::random(8)).'@platform.test',
            'password' => 'SuperSecret#12345',
        ], array_diff_key($attributes, array_flip(['is_active']))));
        $admin->permissions ??= config('pos.super_admin_permissions');
        $admin->is_active = $attributes['is_active'] ?? true;
        $admin->save();

        return $admin;
    }

    protected function enableTwoFactor(SuperAdmin $admin): string
    {
        $secret = app(TotpService::class)->generateSecret();
        $admin->forceFill([
            'two_factor_secret'       => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    protected function makeProduct(Tenant $tenant, array $attributes = []): Product
    {
        // Seed through the same tenant-context path the HTTP layer uses.
        $owner = $tenant->users()->first() ?? $this->makeUser($tenant);
        $this->tenantContext->setUser($owner);

        $stock = $attributes['stock_quantity'] ?? 100; // saleable by default in fixtures
        unset($attributes['stock_quantity']);

        $product = Product::create(array_merge([
            'category_id'   => Category::firstOrCreate(['name' => 'General'])->id,
            'sku'           => 'SKU-'.strtoupper(Str::random(6)),
            'name'          => 'Product '.Str::random(5),
            'cost_price'    => 10,
            'selling_price' => 20,
            'is_active'     => true,
        ], $attributes));

        $product->forceFill(['stock_quantity' => $stock])->save();

        $this->tenantContext->clear();

        return $product->refresh();
    }

    /* ----------------------------------------------------------------
     |  Auth helpers (real tokens — expiry middleware paths stay real)
     * ---------------------------------------------------------------- */

    protected function tokenForUser(User $user, array $abilities = ['tenant'], $expiresAt = null): string
    {
        return $user->createToken('test', $abilities, $expiresAt ?? now()->addHours(12))->plainTextToken;
    }

    protected function tokenForSuperAdmin(SuperAdmin $admin, array $abilities = ['super-admin']): string
    {
        return $admin->createToken('test-sa', $abilities, now()->addHours(8))->plainTextToken;
    }

    protected function asTenantUser(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenForUser($user));
    }

    protected function asSuperAdmin(SuperAdmin $admin): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenForSuperAdmin($admin));
    }

    /** Full SA 2FA login flow, returns the full-access token. */
    protected function loginSuperAdmin(SuperAdmin $admin, string $password = 'SuperSecret#12345', ?string $secret = null): string
    {
        $response = $this->postJson('/api/v1/super-admin/login', [
            'email'    => $admin->email,
            'password' => $password,
        ]);

        $body = $response->json();

        if (isset($body['token']) && isset($body['two_factor_setup_required'])) {
            // No 2FA enrolled — enroll now.
            $setupToken = $body['token'];
            $this->withHeader('Authorization', 'Bearer '.$setupToken)
                ->postJson('/api/v1/super-admin/two-factor/setup');

            $code = app(TotpService::class)->currentCode($admin->refresh()->two_factor_secret);
            $this->withHeader('Authorization', 'Bearer '.$setupToken)
                ->postJson('/api/v1/super-admin/two-factor/confirm', ['code' => $code]);

            return $setupToken; // upgraded in place
        }

        $challenge = $body['challenge'];
        $code = app(TotpService::class)->currentCode($admin->refresh()->two_factor_secret);

        return $this->postJson('/api/v1/super-admin/two-factor/challenge', [
            'challenge' => $challenge,
            'code'      => $code,
        ])->json('token');
    }
}
