<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'house_id' => $this->house_id,
            'expense_category_id' => $this->expense_category_id,
            'paid_by' => $this->paid_by,
            'title' => $this->title,
            'description' => $this->description,
            'amount' => (string) $this->amount,
            'expense_date' => $this->expense_date?->toDateString(),
            'period_start_date' => $this->period_start_date?->toDateString(),
            'period_end_date' => $this->period_end_date?->toDateString(),
            'status' => $this->status?->value ?? $this->status,
            'allocation_rule_id' => $this->allocation_rule_id,
            'created_by' => $this->created_by,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'category' => ExpenseCategoryResource::make($this->whenLoaded('category')),
            'payer' => UserResource::make($this->whenLoaded('payer')),
            'allocation_rule' => AllocationRuleResource::make($this->whenLoaded('allocationRule')),
            'allocations' => ExpenseAllocationResource::collection($this->whenLoaded('allocations')),
        ];
    }
}
