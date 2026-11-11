<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_CASHIER = 'cashier';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    // Allowlist — tenant_id/role/status are mass-assignment protected and can
    // only be changed through authorized server-side operations.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'            => 'hashed',
            'last_login_at'       => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Server-side permission resolution — role from DB → config map.
     * Client-supplied roles/permissions are never consulted.
     */
    public function hasPermission(string $permission): bool
    {
        return Permissions::roleAllows($this->role, $permission);
    }

    public function permissions(): array
    {
        return Permissions::forRole($this->role);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Safe public representation — no password hash, no internals. */
    public function publicPayload(): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'email' => $this->email,
            'role'  => $this->role,
        ];
    }
}
