<?php

namespace App\Http\Requests;

use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $categoryId = $this->route('category')?->id;

        $unique = Rule::unique('categories', 'name')->where('tenant_id', $tenantId);
        if ($categoryId) {
            $unique->ignore($categoryId);
        }

        return [
            'name'      => ['required', 'string', 'min:1', 'max:80', $unique],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
                // A category can never become its own parent (or a cycle root).
                fn ($attribute, $value, $fail) => $categoryId && (int) $value === $categoryId
                    ? $fail('A category cannot be its own parent.') : null,
            ],
        ];
    }
}
