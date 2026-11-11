<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId),
            ],
            'reference'          => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-_\.]+$/'],
            'note'               => ['nullable', 'string', 'max:500'],
            'received_at'        => ['nullable', 'date', 'before_or_equal:now'],
            'items'              => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost'  => ['required', 'numeric', 'min:0', 'max:100000000'],
            // Partial payment at receipt time; unpaid remainder becomes payable.
            'amount_paid'        => ['sometimes', 'numeric', 'min:0', 'max:100000000'],
            'due_date'           => ['nullable', 'date'],
        ];
    }
}
