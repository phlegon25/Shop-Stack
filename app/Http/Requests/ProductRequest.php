<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware handles authorization
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $productId = $this->route('product')?->id;

        $skuRule = Rule::unique('products', 'sku')->where('tenant_id', $tenantId);
        if ($productId) {
            $skuRule->ignore($productId);
        }

        return [
            // category existence is validated WITHIN this tenant only.
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'sku'                 => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-_\.]+$/', $skuRule],
            'name'                => ['required', 'string', 'max:160'],
            'barcode'             => ['nullable', 'string', 'max:80'],
            'subcategory'         => ['nullable', 'string', 'max:100'],
            'brand'               => ['nullable', 'string', 'max:100'],
            'unit'                => ['nullable', 'string', 'max:30'],
            'description'         => ['nullable', 'string', 'max:1000'],
            'supplier_id'         => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId),
            ],
            'cost_price'          => ['required', 'numeric', 'min:0', 'max:100000000'],
            'selling_price'       => ['required', 'numeric', 'min:0', 'max:100000000'],
            'wholesale_price'     => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            ...($productId ? [] : [
                'opening_quantity' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            ]),
            'stock_max'           => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'expiry_date'         => ['nullable', 'date', 'after:today'],
            'location'            => ['nullable', 'string', 'max:120'],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }
}
