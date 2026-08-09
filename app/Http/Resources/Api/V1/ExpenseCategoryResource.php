<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ExpenseCategory */
class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'house_id' => $this->house_id,
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
