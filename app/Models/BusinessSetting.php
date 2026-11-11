<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'logo_path', 'currency', 'timezone', 'tax_rate',
        'invoice_footer', 'low_stock_default', 'expiry_warn_days',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate'             => 'decimal:2',
            'onboarding_completed'=> 'boolean',
        ];
    }

    /** Signed-less URL path to the tenant logo; null when none set. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? url('/api/v1/settings/logo') : null;
    }
}
