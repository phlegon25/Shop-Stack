<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TenantRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('pos.passwords.tenant_min');

        return [
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_name'    => ['required', 'string', 'min:2', 'max:120'],
            'email'         => [
                'required', 'string', 'email:rfc', 'max:190',
                Rule::unique('users', 'email'),
                Rule::unique('super_admins', 'email'),
            ],
            'phone'    => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]+$/'],
            'business_type' => ['nullable', 'string', 'max:60'],
            'address'       => ['nullable', 'string', 'max:300'],
            'city'          => ['nullable', 'string', 'max:80'],
            'region'        => ['nullable', 'string', 'max:80'],
            'country'       => ['nullable', 'string', 'max:80'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'password' => ['required', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()],
        ];
    }
}
