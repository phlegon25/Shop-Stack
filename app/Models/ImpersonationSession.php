<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'super_admin_id', 'tenant_id', 'reason', 'token_id',
        'started_at', 'expires_at', 'ended_at', 'end_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function end(string $reason = 'manual'): void
    {
        if ($this->ended_at === null) {
            $this->forceFill(['ended_at' => now(), 'end_reason' => $reason])->save();
        }
    }
}
