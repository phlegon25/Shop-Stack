<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('superadmin.perm:payments.review')
 *        ->middleware('superadmin.perm:a|b')  (any-of)
 */
class EnsureSuperAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        /** @var SuperAdmin|null $admin */
        $admin = $request->user();

        if (! $admin instanceof SuperAdmin) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        $permissions = count($permissions) === 1 && str_contains($permissions[0], '|')
            ? explode('|', $permissions[0])
            : $permissions;

        foreach ($permissions as $permission) {
            if ($admin->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Missing required administrator permission.',
            'code'    => 'permission_denied',
        ], 403);
    }
}
