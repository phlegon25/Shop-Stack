<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlatformSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // super-admin permission middleware enforces authorization
    }

    public function rules(): array
    {
        return [
            'momo_number'          => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-]+$/'],
            'momo_name'            => ['sometimes', 'nullable', 'string', 'max:120'],
            'momo_provider'        => ['sometimes', 'nullable', 'string', 'max:40',
                \Illuminate\Validation\Rule::in(['MTN MoMo', 'Telecel Cash', 'AirtelTigo Money', 'Other', null])],
            // Second MoMo provider — the platform visibly supports two wallets.
            'momo_number_2'        => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-]+$/'],
            'momo_name_2'          => ['sometimes', 'nullable', 'string', 'max:120'],
            'momo_provider_2'      => ['sometimes', 'nullable', 'string', 'max:40',
                \Illuminate\Validation\Rule::in(['MTN MoMo', 'Telecel Cash', 'AirtelTigo Money', 'Other', null])],
            'bank_name'            => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_account_number'  => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[0-9\-\s]+$/'],
            'bank_account_name'    => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Suspension policy knob — applies platform-wide when a sub lapses.
            'grace_period_days'    => ['sometimes', 'integer', 'min:0', 'max:60'],
        ];
    }
}
