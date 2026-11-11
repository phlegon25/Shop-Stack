<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantSuspendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'  => ['required', 'string', 'min:3', 'max:500'],
            // Dangerous action — must be explicitly confirmed.
            'confirm' => ['required', 'accepted'],
        ];
    }
}
