<?php

namespace App\Http\Requests;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(\App\Support\TenantContext::class)->tenantId();

        return [
            'items'              => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'min:1'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:100000'],
            'payment_method'     => ['required', Rule::in(Sale::PAYMENT_METHODS)],
            'price_type'         => ['sometimes', Rule::in(['retail', 'wholesale'])],
            'amount_paid'        => ['required', 'numeric', 'min:0', 'max:100000000'],
            'discount'           => ['sometimes', 'numeric', 'min:0', 'max:100000000'],
            'taxable'            => ['sometimes', 'boolean'],
            'issue_invoice'      => ['sometimes', 'boolean'],
            'customer_id'        => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('tenant_id', $tenantId),
            ],
            'customer_name'      => ['nullable', 'string', 'max:120'],
            'due_date'           => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * Only these keys may ever reach the service layer — totals, prices,
     * tenant and user fields coming from a client are ignored outright.
     */
    public function validatedPayload(): array
    {
        $v = $this->validated();

        return [
            'items'          => $v['items'],
            'payment_method' => $v['payment_method'],
            'price_type'     => $v['price_type'] ?? 'retail',
            'amount_paid'    => $v['amount_paid'],
            'discount'       => $v['discount'] ?? 0,
            'taxable'        => (bool) ($v['taxable'] ?? false),
            'issue_invoice'  => (bool) ($v['issue_invoice'] ?? false),
            'customer_id'    => $v['customer_id'] ?? null,
            'customer_name'  => $v['customer_name'] ?? null,
            'due_date'       => $v['due_date'] ?? null,
        ];
    }
}
