<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AllocationRule */
class AllocationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'rule_type' => $this->rule_type?->value ?? $this->rule_type,
            'configuration' => $this->configuration,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'version' => $this->version,
            'created_by' => $this->created_by,
        ];
    }
}
