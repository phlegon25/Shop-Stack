<?php

namespace App\Support;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves the authenticated principal DIRECTLY from the bearer token,
 * bypassing the auth-guard user cache.
 *
 * Laravel's RequestGuard caches the resolved user per app instance. In a
 * token-only API we never want a stale identity — identity MUST come from
 * the token presented with THIS request.
 */
class BearerPrincipal
{
    /**
     * @return array{0: User|SuperAdmin, 1: PersonalAccessToken}|null
     */
    public static function resolve(Request $request): ?array
    {
        $plain = $request->bearerToken();

        if (! $plain) {
            return null;
        }

        $token = PersonalAccessToken::findToken($plain);

        if ($token === null) {
            return null;
        }

        // Token expiry: an explicit per-token TTL (remember-me, impersonation,
        // super-admin sessions) or the global Sanctum TTL — whichever fires.
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        if ($token->expires_at === null
            && config('sanctum.expiration') !== null
            && $token->created_at->lte(now()->subMinutes((int) config('sanctum.expiration')))) {
            return null;
        }

        $principal = $token->tokenable;

        if (! $principal instanceof User && ! $principal instanceof SuperAdmin) {
            return null;
        }

        // Attach the token so currentAccessToken() works everywhere downstream.
        $principal->withAccessToken($token);

        return [$principal, $token];
    }

    /** Overrides the (possibly stale, guard-cached) identity on the request. */
    public static function applyToRequest(Request $request, User|SuperAdmin $principal): void
    {
        $request->setUserResolver(fn () => $principal);
    }
}
