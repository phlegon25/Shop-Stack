<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'email', 'password', 'permissions'];

    protected $hidden = ['password', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password'               => 'hashed',
            'permissions'            => 'array',
            'two_factor_secret'      => 'encrypted',
            'two_factor_confirmed_at'=> 'datetime',
            'last_login_at'          => 'datetime',
            'is_active'              => 'boolean',
        ];
    }

    public function hasPermission(string $permission): bool
    {
        $grants = $this->permissions ?? [];
        return in_array($permission, $grants, true);
    }

    public function twoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function publicPayload(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'permissions'       => $this->permissions ?? [],
            'two_factor_enabled'=> $this->twoFactorEnabled(),
        ];
    }
}
