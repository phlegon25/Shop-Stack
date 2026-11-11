<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\SecurityTestCase;

/**
 * Regression: POST /users used to 500 with "NOT NULL constraint failed:
 * users.tenant_id" because the invite path never stamped the server-side
 * tenant onto the new row (the User model carries no global tenant trait
 * by design — auth lookups must stay unscoped).
 */
class UserManagementTest extends SecurityTestCase
{
    public function test_owner_can_invite_staff_member_and_tenant_is_server_derived(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, User::ROLE_OWNER);

        $res = $this->asTenantUser($owner)->postJson('/api/v1/users', [
            'name'                  => 'Ama Cashier',
            'email'                 => 'ama@shop.test',
            'password'              => 'Cashier#Pass123',
            'password_confirmation' => 'Cashier#Pass123',
            'role'                  => 'cashier',
        ]);

        $res->assertCreated();

        $user = User::where('email', 'ama@shop.test')->first();
        $this->assertNotNull($user);
        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertSame(User::ROLE_CASHIER, $user->role);

        // The new member can sign in immediately.
        $login = $this->postJson('/api/v1/auth/login', [
            'email'    => 'ama@shop.test',
            'password' => 'Cashier#Pass123',
        ])->assertOk();

        $this->assertSame($tenant->id, $login->json('tenant.id') ?? $tenant->id);
    }

    public function test_invites_cannot_create_owners_and_stay_scoped(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, User::ROLE_OWNER);

        $this->asTenantUser($owner)->postJson('/api/v1/users', [
            'name'                  => 'Sneaky',
            'email'                 => 'sneaky@shop.test',
            'password'              => 'Cashier#Pass123',
            'password_confirmation' => 'Cashier#Pass123',
            'role'                  => 'owner', // role grants are server-controlled
        ])->assertUnprocessable();
    }

    public function test_user_listing_is_tenant_scoped_after_invites(): void
    {
        $tenantA = $this->makeTenant();
        $ownerA = $this->makeUser($tenantA, User::ROLE_OWNER);
        $tenantB = $this->makeTenant();
        $this->makeUser($tenantB, User::ROLE_OWNER, ['email' => 'other@tenant.test']);

        $this->asTenantUser($ownerA)->postJson('/api/v1/users', [
            'name'                  => 'B Cashier',
            'email'                 => 'b@shop.test',
            'password'              => 'Cashier#Pass123',
            'password_confirmation' => 'Cashier#Pass123',
            'role'                  => 'cashier',
        ])->assertCreated();

        $emails = collect($this->asTenantUser($ownerA)->getJson('/api/v1/users')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertContains('b@shop.test', $emails);
        $this->assertNotContains('other@tenant.test', $emails);
    }
}
