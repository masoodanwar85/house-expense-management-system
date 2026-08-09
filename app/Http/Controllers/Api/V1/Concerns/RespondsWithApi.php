<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait RespondsWithApi
{
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->resolveData($data),
        ], $status);
    }

    protected function created(mixed $data = null): JsonResponse
    {
        return $this->ok($data, 201);
    }

    private function resolveData(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
            return $data->resolve();
        }

        return $data;
    }
}
