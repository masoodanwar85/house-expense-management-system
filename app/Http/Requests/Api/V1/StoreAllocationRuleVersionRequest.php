<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreAllocationRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule_type' => ['required', 'string', 'in:per_day,fixed,hybrid'],
            'configuration' => ['nullable', 'array'],
            'effective_from' => ['required', 'date'],
        ];
    }
}
