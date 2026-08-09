<?php

namespace App\Services\Allocation;

use App\Exceptions\DomainException;
use App\Services\Allocation\DTO\AllocationContext;
use App\Support\Money;

class HybridAllocator implements AllocatorInterface
{
    public function __construct(
        private readonly FixedAllocator $fixedAllocator,
        private readonly PerDayAllocator $perDayAllocator,
    ) {}

    /**
     * @return array{
     *     amounts: array<int, string>,
     *     components: array<int, array<string, string>>
     * }
     */
    public function allocateDetailed(string $amount, AllocationContext $context, array $configuration = []): array
    {
        $amount = Money::of($amount);
        $components = $configuration['components'] ?? null;

        if (! is_array($components) || $components === []) {
            throw DomainException::because('Hybrid allocation requires components.');
        }

        /** @var array<int, string> $totals */
        $totals = array_fill_keys($context->memberUserIds, '0.00');
        /** @var array<int, array<string, string>> $byComponent */
        $byComponent = array_fill_keys($context->memberUserIds, []);

        $componentAmounts = $this->splitComponentAmounts($amount, $configuration);

        foreach ($components as $index => $component) {
            $type = $component['type'];
            $componentAmount = $componentAmounts[$index];

            $shares = match ($type) {
                'fixed' => $this->fixedAllocator->allocate($componentAmount, $context, [
                    'apply_to' => $component['apply_to'],
                ]),
                'per_day' => $this->perDayAllocator->allocate($componentAmount, $context),
                default => throw DomainException::because("Unsupported hybrid component type: {$type}"),
            };

            foreach ($context->memberUserIds as $userId) {
                $share = $shares[$userId] ?? '0.00';
                $totals[$userId] = Money::add($totals[$userId], $share);
                $byComponent[$userId][$type] = Money::add($byComponent[$userId][$type] ?? '0.00', $share);
            }
        }

        // Guard: component rounding can drift; re-balance totals to expense amount.
        $totals = $this->rebalanceTotals($amount, $totals);

        return [
            'amounts' => $totals,
            'components' => $byComponent,
        ];
    }

    public function allocate(string $amount, AllocationContext $context, array $configuration = []): array
    {
        return $this->allocateDetailed($amount, $context, $configuration)['amounts'];
    }

    /**
     * @param  array{mode?: string, components: list<array<string, mixed>>}  $configuration
     * @return array<int, string>
     */
    private function splitComponentAmounts(string $total, array $configuration): array
    {
        $mode = $configuration['mode'] ?? 'percentage';
        $components = $configuration['components'];

        return match ($mode) {
            'percentage' => $this->splitByPercentage($total, $components),
            'amount_remainder' => $this->splitByAmountRemainder($total, $components),
            default => throw DomainException::because("Unsupported hybrid mode: {$mode}"),
        };
    }

    /**
     * @param  list<array{type: string, percentage: float|int|string, apply_to?: string}>  $components
     * @return array<int, string>
     */
    private function splitByPercentage(string $total, array $components): array
    {
        $weights = [];

        foreach ($components as $index => $component) {
            $weights[$index] = $component['percentage'];
        }

        return Money::allocateByWeights($total, $weights);
    }

    /**
     * @param  list<array{type: string, amount?: string, share?: string, apply_to?: string}>  $components
     * @return array<int, string>
     */
    private function splitByAmountRemainder(string $total, array $components): array
    {
        $amounts = [];
        $remainderIndex = null;
        $absoluteSum = '0.00';

        foreach ($components as $index => $component) {
            if (($component['share'] ?? null) === 'remainder') {
                if ($remainderIndex !== null) {
                    throw DomainException::because('Hybrid amount_remainder allows only one remainder component.');
                }

                $remainderIndex = $index;

                continue;
            }

            $part = Money::of($component['amount'] ?? '0');
            $amounts[$index] = $part;
            $absoluteSum = Money::add($absoluteSum, $part);
        }

        if ($remainderIndex === null) {
            throw DomainException::because('Hybrid amount_remainder requires a remainder component.');
        }

        if (Money::compare($absoluteSum, $total) === 1) {
            throw DomainException::because(
                "Hybrid fixed amounts ({$absoluteSum}) exceed expense amount ({$total})."
            );
        }

        $amounts[$remainderIndex] = Money::sub($total, $absoluteSum);

        ksort($amounts);

        return $amounts;
    }

    /**
     * @param  array<int, string>  $totals
     * @return array<int, string>
     */
    private function rebalanceTotals(string $expected, array $totals): array
    {
        $sum = '0.00';

        foreach ($totals as $amount) {
            $sum = Money::add($sum, $amount);
        }

        $diff = Money::sub($expected, $sum);

        if (Money::compare($diff, '0.00') === 0 || $totals === []) {
            return $totals;
        }

        $positiveKeys = [];

        foreach ($totals as $userId => $amount) {
            if (Money::compare($amount, '0.00') === 1) {
                $positiveKeys[] = $userId;
            }
        }

        $keys = $positiveKeys !== [] ? $positiveKeys : array_keys($totals);
        sort($keys);
        $target = $keys[array_key_last($keys)];
        $totals[$target] = Money::add($totals[$target], $diff);

        return $totals;
    }
}
