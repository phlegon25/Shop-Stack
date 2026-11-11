<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'quantity', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'unit_cost'  => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
