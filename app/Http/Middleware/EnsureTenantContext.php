<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ImpersonationService;
use App\Services\SubscriptionService;
use App\Support\BearerPrincipal;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the server-side tenant context for tenant API routes.
 *
 * - Rejects any principal that is not a tenant user (super-admin tokens are
 *   refused on tenant routes — complete separation of planes).
 * - Derives the tenant EXCLUSIVELY from the authenticated user record.
 *   Client-supplied tenant identifiers are never consulted.
 * - Handles impersonation tokens: validates the underlying session record
 *   and marks the request as impersonated (flag surfaced to the client so
 *   the UI can show a persistent banner).
 */
class EnsureTenantContext
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resolved = BearerPrincipal::resolve($request);

        if ($resolved === null) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401);
        }

        [$principal, $token] = $resolved;

        if (! $principal instanceof User) {
            return response()->json([
                'message' => 'This endpoint is only available to tenant accounts.',
                'code'    => 'wrong_account_type',
            ], 403);
        }

        if (! $principal->isActive()) {
            $this->audit->log('authz.denied', [
                'actor'   => $principal,
                'context' => ['reason' => 'account_disabled'],
            ]);

            return response()->json(['message' => 'This account has been disabled.', 'code' => 'account_disabled'], 403);
        }

        $tenant = $principal->tenant;

        if ($tenant === null) {
            return response()->json(['message' => 'Account is not attached to a workspace.', 'code' => 'no_tenant'], 403);
        }

        $this->context->setUser($principal);
        $request->attributes->set('tenant', $tenant);

        // Identity for the rest of this request comes from THIS bearer token.
        BearerPrincipal::applyToRequest($request, $principal);

        // Impersonation session enforcement (expired sessions are revoked).
        if ($token && str_starts_with((string) $token->name, ImpersonationService::TOKEN_PREFIX)) {
            $sessionId = (int) substr($token->name, strlen(ImpersonationService::TOKEN_PREFIX));
            /** @var ImpersonationSession|null $session */
            $session = ImpersonationSession::whereKey($sessionId)
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($session === null || ! $session->isActive()) {
                if ($session !== null) {
                    app(ImpersonationService::class)->end($session, 'expired');
                }
                $token->delete();

                return response()->json([
                    'message' => 'The impersonation session has expired.',
                    'code'    => 'impersonation_expired',
                ], 403);
            }

            $this->context->setImpersonation($session->id, $session->superAdmin, $session->reason);
        }

        // Lazily expire due subscriptions for this tenant.
        app(SubscriptionService::class)->sweepExpiries($tenant);

        $response = $next($request);

        if ($this->context->isImpersonated()) {
            $response->headers->set('X-Impersonation-Active', 'true');
        }

        return $response;
    }
}
