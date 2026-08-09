<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MemberAvailabilityPeriod */
class AvailabilityPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'house_id' => $this->house_id,
            'user_id' => $this->user_id,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status?->value ?? $this->status,
            'created_by' => $this->created_by,
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
