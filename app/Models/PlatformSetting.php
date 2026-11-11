<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'momo_number', 'momo_name', 'momo_provider',
        'momo_provider_2', 'momo_number_2', 'momo_name_2',
        'bank_name', 'bank_account_number', 'bank_account_name',
        'payment_instructions', 'grace_period_days',
        // platform_name handled via config/env (APP identity), not tenant data.
    ];

    protected function casts(): array
    {
        return ['grace_period_days' => 'integer'];
    }

    /** The single settings row, creating it empty on first use. */
    public static function instance(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /** What TENANTS are allowed to see (payment instructions to pay against).
     *  Only authenticated tenants reach this projection. */
    public function publicPaymentInfo(): array
    {
        return [
            'momo' => array_values(array_filter([
                $this->momo_number ? [
                    'provider' => $this->momo_provider,
                    'name'     => $this->momo_name,
                    'number'   => $this->momo_number,
                ] : null,
                $this->momo_number_2 ? [
                    'provider' => $this->momo_provider_2,
                    'name'     => $this->momo_name_2,
                    'number'   => $this->momo_number_2,
                ] : null,
            ])),
            'bank' => $this->bank_account_number ? [
                'bank_name'      => $this->bank_name,
                'account_name'   => $this->bank_account_name,
                'account_number' => $this->bank_account_number,
            ] : null,
            'payment_instructions' => $this->payment_instructions,
            'currency'             => config('pos.currency'),
        ];
    }
}
