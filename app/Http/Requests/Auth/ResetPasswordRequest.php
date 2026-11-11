<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('pos.passwords.tenant_min');

        return [
            'email'    => ['required', 'string', 'email', 'max:190'],
            'token'    => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()],
        ];
    }
}
