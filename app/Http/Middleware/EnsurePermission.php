<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fine-grained authorization: requires the tenant user to hold at least one
 * of the given permissions (resolved from their DB role — never from input).
 *
 * Usage: ->middleware('permission:sales.create')  or  'permission:a|b'
 */
class EnsurePermission
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $this->context->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        $permissions = count($permissions) === 1 && str_contains($permissions[0], '|')
            ? explode('|', $permissions[0])
            : $permissions;

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'code'    => 'permission_denied',
        ], 403);
    }
}
