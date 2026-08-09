<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['sometimes', 'integer', 'exists:expense_categories,id'],
            'paid_by' => ['sometimes', 'integer', 'exists:users,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'expense_date' => ['sometimes', 'date'],
            'period_start_date' => ['sometimes', 'nullable', 'date'],
            'period_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_start_date'],
        ];
    }
}
