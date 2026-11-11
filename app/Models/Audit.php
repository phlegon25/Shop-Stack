<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit record. The application never updates or deletes audits;
 * model events hard-block any such attempt as a defense-in-depth measure.
 */
class Audit extends Model
{
    public const UPDATED_AT = null;

    // NOTE: deliberately NOT using the tenant global scope — platform admins
    // query cross-tenant, and writes always set tenant_id explicitly.
    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'actor_label', 'actor_email',
        'action', 'subject_type', 'subject_id', 'impersonation_session_id',
        'context', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit records cannot be deleted.'));
    }
}
