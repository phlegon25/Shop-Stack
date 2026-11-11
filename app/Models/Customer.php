<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant;

    public const TYPES = ['retail', 'wholesale'];

    // balance is deliberately NOT fillable: it changes only via
    // transactional sale/payment/void operations.
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'type',
        'credit_limit', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'balance'      => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }
}
