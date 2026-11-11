<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tenant-scoped rows are reached only through tenant-bound queries; null
// tenant_id rows are platform-side and only reachable by super admins.
class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data'       => 'array',
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
