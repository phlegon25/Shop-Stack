<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null; // financial ledger — append-only

    protected $fillable = ['sale_id', 'customer_id', 'amount', 'method', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
