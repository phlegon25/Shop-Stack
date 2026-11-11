<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'                => ['required', 'string', 'min:3', 'max:500'],
            'items'                 => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sale_item_id'  => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity'      => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
