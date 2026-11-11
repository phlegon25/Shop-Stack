<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null; // append-only ledger

    public const TYPES = [
        'sale', 'sale_void', 'sale_return',
        'purchase', 'purchase_cancel', 'adjustment',
    ];

    protected $fillable = [
        'product_id', 'type', 'quantity_change', 'quantity_after',
        'reference_type', 'reference_id', 'note', 'user_id', 'impersonation_session_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'quantity_after'  => 'integer',
            'created_at'      => 'datetime',
        ];
    }
}
