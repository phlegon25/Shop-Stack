<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use App\Support\BearerPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Super Admin plane. Tenant tokens are refused here, and
 * partially-authenticated (pre-2FA) tokens can only reach the 2FA endpoints.
 */
class EnsureSuperAdmin
{
    /**
     * @param string|null $mode  Pass "setup" on 2FA-enrollment endpoints to
     *                           also admit restricted pre-2FA tokens.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $resolved = BearerPrincipal::resolve($request);

        if ($resolved === null) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        [$principal, $token] = $resolved;

        if (! $principal instanceof SuperAdmin) {
            return response()->json([
                'message' => 'This endpoint is restricted to platform administrators.',
                'code'    => 'wrong_account_type',
            ], 403);
        }

        if (! $principal->isActive()) {
            return response()->json(['message' => 'This administrator account is disabled.', 'code' => 'account_disabled'], 403);
        }

        BearerPrincipal::applyToRequest($request, $principal);

        $abilities = $token->abilities ?? [];

        $hasFull   = in_array('super-admin', $abilities, true) || in_array('*', $abilities, true);
        $hasSetup  = in_array('2fa-setup', $abilities, true);

        if (! $hasFull && ! ($mode === 'setup' && $hasSetup)) {
            return response()->json([
                'message' => 'Two-factor authentication is required before continuing.',
                'code'    => 'two_factor_required',
            ], 403);
        }

        return $next($request);
    }
}
