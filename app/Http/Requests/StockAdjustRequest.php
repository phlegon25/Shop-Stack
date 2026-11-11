<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'quantity_change' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'note'            => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
