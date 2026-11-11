<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant;

    // stock_quantity is NOT fillable — it changes only via StockService
    // movements inside transactions. tenant_id/category integrity enforced
    // by scope + server-side lookups.
    protected $fillable = [
        'category_id', 'subcategory', 'brand', 'unit', 'description',
        'sku', 'name', 'barcode',
        'cost_price', 'selling_price', 'wholesale_price',
        'low_stock_threshold', 'max_stock', 'supplier_id',
        'expiry_date', 'location', 'image_path', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price'          => 'decimal:2',
            'selling_price'       => 'decimal:2',
            'wholesale_price'     => 'decimal:2',
            'stock_quantity'      => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active'           => 'boolean',
            'expiry_date'         => 'date:Y-m-d',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? url("/api/v1/products/{$this->id}/image") : null;
    }
}
