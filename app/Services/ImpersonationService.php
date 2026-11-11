<?php

namespace App\Services;

use App\Models\ImpersonationSession;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Super Admin tenant impersonation.
 *
 * Guarantees:
 *  - requires an explicit reason (validated before we get here)
 *  - issues a SHORT-LIVED token; no tenant password is ever read, exposed,
 *    or copied — the token acts as the session
 *  - start and end are fully audited, and every action performed while
 *    impersonating is stamped with the session id
 */
class ImpersonationService
{
    public const TOKEN_PREFIX = 'impersonation:';

    public function __construct(private AuditLogger $audit) {}

    public function start(SuperAdmin $admin, Tenant $tenant, string $reason): array
    {
        /** @var User|null $owner */
        $owner = $tenant->users()
            ->where('role', User::ROLE_OWNER)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($owner === null) {
            abort(422, 'This tenant has no active owner account to impersonate.');
        }

        $minutes  = (int) config('pos.tokens.impersonation_minutes');
        $expires  = now()->addMinutes($minutes);

        $tokenResult = $owner->createToken(
            self::TOKEN_PREFIX.'pending', // renamed once we have the session id
            ['*'],
            $expires,
        );

        $session = ImpersonationSession::create([
            'super_admin_id' => $admin->id,
            'tenant_id'      => $tenant->id,
            'reason'         => $reason,
            'token_id'       => $tokenResult->accessToken->id,
            'started_at'     => now(),
            'expires_at'     => $expires,
        ]);

        $tokenResult->accessToken->forceFill([
            'name' => self::TOKEN_PREFIX.$session->id,
        ])->save();

        $this->audit->log('impersonation.started', [
            'actor'     => $admin,
            'tenant_id' => $tenant->id,
            'subject'   => $session,
            'impersonation_session_id' => $session->id,
            'context'   => ['reason' => $reason, 'expires_at' => $expires->toISOString()],
        ]);

        return [$session, $tokenResult->plainTextToken];
    }

    public function end(ImpersonationSession $session, string $reason = 'manual'): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        if ($session->token_id) {
            PersonalAccessToken::whereKey($session->token_id)->delete();
        }

        $session->end($reason);

        $this->audit->log('impersonation.ended', [
            'actor_type' => 'system',
            'tenant_id'  => $session->tenant_id,
            'subject'    => $session,
            'impersonation_session_id' => $session->id,
            'context'    => ['end_reason' => $reason],
        ]);
    }

    public function sweepExpired(): void
    {
        ImpersonationSession::whereNull('ended_at')
            ->where('expires_at', '<=', now())
            ->each(fn (ImpersonationSession $s) => $this->end($s, 'expired'));
    }
}
