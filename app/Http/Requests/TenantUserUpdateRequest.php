<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'string', 'min:2', 'max:120'],
            // Demoting an owner or promoting anyone TO owner is impossible here.
            'role'   => ['sometimes', Rule::in(['admin', 'cashier'])],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ];
    }
}
