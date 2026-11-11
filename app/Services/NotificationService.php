<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * In-app notification engine.
 *
 * - Tenant notifications are visible to every active user of that tenant
 *   (user_id null) or to specific users.
 * - Platform notifications (tenant_id null) are visible ONLY to super admins.
 * - A dedupe ledger (notification_logs) guarantees scheduled jobs never send
 *   the same event twice — the ledger row is the idempotency key.
 */
class NotificationService
{
    /** Notify all users of a tenant (or a subset via $userIds). */
    public function toTenant(
        int $tenantId,
        string $type,
        string $title,
        string $body,
        ?array $data = null,
        ?array $userIds = null,
    ): void {
        if ($userIds === null || $userIds === []) {
            Notification::create([
                'tenant_id' => $tenantId,
                'type'      => $type,
                'title'     => $title,
                'body'      => $body,
                'data'      => $data,
            ]);

            return;
        }

        foreach (array_unique($userIds) as $userId) {
            Notification::create([
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'body'      => $body,
                'data'      => $data,
            ]);
        }
    }

    /** Notify the super-admin plane (tenant_id = null). */
    public function toPlatform(string $type, string $title, string $body, ?array $data = null): void
    {
        Notification::create([
            'tenant_id' => null,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'data'      => $data,
        ]);
    }

    /**
     * Record an event key for dedupe. Returns true the FIRST time this
     * (tenant, key) pair is seen — callers notify only when true.
     * Exception-safe under contention via the unique index + insertOrIgnore.
     */
    public function firstTime(?int $tenantId, string $eventKey): bool
    {
        $eventKey = mb_substr($eventKey, 0, 190);

        return DB::table('notification_logs')->insertOrIgnore([
            'tenant_id'  => $tenantId,
            'event_key'  => $eventKey,
            'created_at' => now(),
        ]) === 1;
    }
}
