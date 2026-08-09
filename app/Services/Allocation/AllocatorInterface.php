<?php

namespace App\Services\Allocation;

use App\Services\Allocation\DTO\AllocationContext;

interface AllocatorInterface
{
    /**
     * Allocate an amount across members.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<int, string> user_id => amount (2dp string)
     */
    public function allocate(string $amount, AllocationContext $context, array $configuration = []): array;
}
