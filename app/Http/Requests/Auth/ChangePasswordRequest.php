<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = $this->user() instanceof \App\Models\SuperAdmin
            ? config('pos.passwords.super_admin_min')
            : config('pos.passwords.tenant_min');

        return [
            'current_password' => ['required', 'string', 'max:200'],
            'password'         => ['required', 'confirmed', 'different:current_password', Password::min($min)->letters()->mixedCase()->numbers()],
        ];
    }
}
