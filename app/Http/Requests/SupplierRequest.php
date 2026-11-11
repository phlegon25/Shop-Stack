<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'min:2', 'max:120'],
            'phone'   => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]+$/'],
            'email'   => ['nullable', 'string', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
