<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Central audit writer for all security-sensitive events.
 *
 * - Context payloads are passed through a DENY-list so secrets
 *   (passwords, tokens, 2FA secrets, hashes) can NEVER be logged.
 * - Records are append-only (enforced by the Audit model).
 */
class AuditLogger
{
    /** Keys that must never appear in an audit context. */
    private const DENY_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'plain_text_token', 'access_token', 'secret', 'two_factor_secret',
        'code', 'otp', 'hash', 'token_hash', 'authorization',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param string        $action   e.g. "auth.login", "payment.approved"
     * @param array{
     *   tenant_id?: int|null,
     *   actor?: User|SuperAdmin|null,
     *   actor_type?: string,
     *   subject?: Model|null,
     *   context?: array,
     * } $options
     */
    public function log(string $action, array $options = []): void
    {
        $actor      = $options['actor'] ?? null;
        $actorType  = $options['actor_type'] ?? null;
        $tenantId   = $options['tenant_id'] ?? $this->tenantContext->tenantId();
        $impersonation = $this->tenantContext->impersonation();

        if ($actor === null) {
            $actor = $this->tenantContext->user();
        }

        if ($actor instanceof User) {
            $actorType ??= 'user';
            $tenantId ??= $actor->tenant_id;
        } elseif ($actor instanceof SuperAdmin) {
            $actorType ??= 'super_admin';
        } else {
            $actorType ??= 'system';
        }

        $subject = $options['subject'] ?? null;

        Audit::create([
            'tenant_id'                => $tenantId,
            'actor_type'               => $actorType,
            'actor_id'                 => $actor?->id,
            'actor_label'              => $actor?->name,
            'actor_email'              => $actor?->email,
            'action'                   => $action,
            'subject_type'             => $subject instanceof Model ? $subject->getMorphClass() : null,
            'subject_id'               => $subject instanceof Model ? $subject->getKey() : null,
            'impersonation_session_id' => $options['impersonation_session_id'] ?? ($impersonation['session_id'] ?? null),
            'context'                  => $this->sanitize($options['context'] ?? []),
            'ip'                       => $this->safeRequest()?->ip(),
            'user_agent'               => substr((string) $this->safeRequest()?->userAgent(), 0, 512) ?: null,
        ]);
    }

    /** Remove any sensitive values recursively. */
    private function sanitize(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::DENY_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function safeRequest(): ?\Illuminate\Http\Request
    {
        return app()->bound('request') && ! app()->runningInConsole()
            ? request()
            : null;
    }
}
