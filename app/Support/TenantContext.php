<?php

namespace App\Support;

use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Per-request tenant context.
 *
 * The tenant is ALWAYS derived from the authenticated user / impersonation
 * session server-side. A client can never choose a tenant — any tenant_id in
 * request input is ignored.
 */
class TenantContext
{
    private ?Tenant $tenant = null;
    private ?User $user = null;

    /** Active impersonation metadata, when the request is an impersonated one. */
    private ?array $impersonation = null;

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->tenant = $user->tenant;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?int
    {
        return $this->tenant?->id;
    }

    public function setImpersonation(int $sessionId, SuperAdmin $admin, string $reason): void
    {
        $this->impersonation = [
            'session_id' => $sessionId,
            'admin_id'   => $admin->id,
            'admin_name' => $admin->name,
            'reason'     => $reason,
        ];
    }

    public function isImpersonated(): bool
    {
        return $this->impersonation !== null;
    }

    public function impersonation(): ?array
    {
        return $this->impersonation;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->user = null;
        $this->impersonation = null;
    }
}
