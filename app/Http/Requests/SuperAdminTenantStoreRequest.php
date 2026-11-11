<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SuperAdminTenantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('pos.passwords.tenant_min');

        return [
            'name'        => ['required', 'string', 'min:2', 'max:120'],
            'phone'       => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]+$/'],
            'owner_name'  => ['required', 'string', 'min:2', 'max:120'],
            'email'       => [
                'required', 'string', 'email:rfc', 'max:190',
                Rule::unique('users', 'email'),
                Rule::unique('super_admins', 'email'),
            ],
            'password'    => ['required', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()],
            'grant_trial' => ['sometimes', 'boolean'],
        ];
    }
}
