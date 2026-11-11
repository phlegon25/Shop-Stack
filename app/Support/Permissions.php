<?php

namespace App\Support;

/**
 * Server-side authority for role → permission resolution.
 *
 * Roles and permissions are NEVER taken from client input. They are resolved
 * from the authenticated user's database record via this map.
 */
class Permissions
{
    public static function forRole(?string $role): array
    {
        if ($role === null) {
            return [];
        }

        return config("pos.permissions.{$role}", []);
    }

    public static function roleAllows(string $role, string $permission): bool
    {
        $grants = self::forRole($role);

        if (in_array('*', $grants, true)) {
            return true;
        }

        return in_array($permission, $grants, true);
    }

    public static function roles(): array
    {
        return config('pos.roles', []);
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::roles(), true);
    }
}
