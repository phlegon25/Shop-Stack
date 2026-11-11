<?php

namespace Tests\Unit;

use App\Support\Permissions;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function test_owner_has_wildcard_access(): void
    {
        $this->assertTrue(Permissions::roleAllows('owner', 'anything.at.all'));
    }

    public function test_cashier_cannot_manage_but_can_sell(): void
    {
        $this->assertTrue(Permissions::roleAllows('cashier', 'sales.create'));
        $this->assertFalse(Permissions::roleAllows('cashier', 'products.manage'));
        $this->assertFalse(Permissions::roleAllows('cashier', 'users.manage'));
        $this->assertFalse(Permissions::roleAllows('cashier', 'sales.void'));
    }

    public function test_unknown_roles_have_no_permissions(): void
    {
        $this->assertFalse(Permissions::roleAllows('super_admin_hacker', 'sales.create'));
        $this->assertFalse(Permissions::roleAllows('', 'sales.create'));
    }
}
