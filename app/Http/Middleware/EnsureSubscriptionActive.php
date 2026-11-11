<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscription enforcement — server-side only. Frontend buttons, hidden UI,
 * browser storage and cookies are NOT part of this decision; a tenant can
 * never bypass suspension/expiry by calling endpoints directly.
 *
 * - Suspended tenants: blocked from ALL business operations.
 * - Expired tenants: read-only — mutating business operations are rejected.
 *   Billing endpoints live outside this middleware so payment is always
 *   possible.
 */
class EnsureSubscriptionActive
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();

        if ($tenant === null) {
            return response()->json(['message' => 'Tenant context missing.', 'code' => 'no_tenant'], 403);
        }

        if ($tenant->isSuspended()) {
            $this->audit->log('authz.denied', [
                'context' => ['reason' => 'tenant_suspended', 'path' => $request->path()],
            ]);

            return response()->json([
                'message' => 'This workspace has been suspended. Please contact support.',
                'code'    => 'tenant_suspended',
            ], 403);
        }

        $isReadOnly = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (! $isReadOnly && ! $tenant->hasOperationalAccess()) {
            $this->audit->log('authz.denied', [
                'context' => ['reason' => 'subscription_expired', 'path' => $request->path()],
            ]);

            return response()->json([
                'message' => 'Your subscription has expired. Please renew to continue working.',
                'code'    => 'subscription_expired',
            ], 403);
        }

        return $next($request);
    }
}
