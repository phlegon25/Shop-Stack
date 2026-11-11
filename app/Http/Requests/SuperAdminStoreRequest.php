<?php

namespace App\Http\Requests;

use App\Models\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SuperAdminStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('pos.passwords.super_admin_min');

        return [
            'name'          => ['required', 'string', 'min:2', 'max:120'],
            'email'         => ['required', 'string', 'email:rfc', 'max:190', Rule::unique('super_admins', 'email')],
            'password'      => ['required', 'confirmed', Password::min($min)->letters()->mixedCase()->numbers()],
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(config('pos.super_admin_permissions'))],
        ];
    }
}
