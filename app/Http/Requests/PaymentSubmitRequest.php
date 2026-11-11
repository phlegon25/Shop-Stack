<?php

namespace App\Http\Requests;

use App\Models\SubscriptionPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => [
                'required', 'integer',
                Rule::exists('plans', 'id')->where('is_active', true),
            ],
            'method'        => ['required', Rule::in([SubscriptionPayment::METHOD_MOMO, SubscriptionPayment::METHOD_BANK])],
            'sender_name'   => ['nullable', 'string', 'max:120'],
            'sender_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-]+$/', 'required_if:method,momo'],
            // Unique reference prevents duplicate/replayed submissions.
            'reference'     => [
                'required', 'string', 'min:6', 'max:120',
                'regex:/^[A-Za-z0-9\-\._]+$/',
                Rule::unique('subscription_payments', 'reference'),
            ],
            'proof' => [
                'nullable', 'image', 'mimetypes:jpeg,png,webp',
                'max:'.config('pos.uploads.payment_proof_max_kb'),
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    /**
     * A tenant may only ever submit these fields. Status/amount/review
     * fields are set exclusively by the server.
     */
    public function validatedPayload(): array
    {
        $v = $this->validated();

        return [
            'plan_id'       => $v['plan_id'],
            'method'        => $v['method'],
            'sender_name'   => $v['sender_name'] ?? null,
            'sender_number' => $v['sender_number'] ?? null,
            'reference'     => $v['reference'],
        ];
    }
}
