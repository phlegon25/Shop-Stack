<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null; // append-only

    protected $fillable = ['supplier_id', 'purchase_id', 'user_id', 'amount', 'method', 'note'];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
