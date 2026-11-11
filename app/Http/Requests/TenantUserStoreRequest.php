<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TenantUserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('pos.passwords.tenant_min');

        return [
            'name'  => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            // The owner role can never be granted through the API — prevents
            // privilege escalation. Only admin/cashier assignable.
            'role'     => ['required', Rule::in(array_values(array_diff(config('pos.roles'), ['owner'])))],
            'password' => ['required', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()],
        ];
    }
}
