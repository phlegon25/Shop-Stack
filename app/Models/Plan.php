<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected $fillable = [
        'name', 'slug', 'description', 'price', 'duration_days',
        'is_active', 'sort_order', 'product_limit', 'user_limit',
        'storage_mb', 'features', 'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'price'         => 'decimal:2',
            'duration_days' => 'integer',
            'is_active'     => 'boolean',
            'features'      => 'array',
            'trial_days'    => 'integer',
        ];
    }
}
