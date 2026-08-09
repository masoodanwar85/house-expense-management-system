<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExpenseAllocation */
class ExpenseAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_id' => $this->expense_id,
            'user_id' => $this->user_id,
            'amount' => (string) $this->amount,
            'allocation_details' => $this->allocation_details,
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
