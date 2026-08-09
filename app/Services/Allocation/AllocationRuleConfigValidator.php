<?php

namespace App\Services\Allocation;

use App\Enums\AllocationRuleType;
use App\Enums\FixedApplyTo;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class AllocationRuleConfigValidator
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed> normalized configuration
     */
    public function validate(AllocationRuleType $type, array $configuration): array
    {
        return match ($type) {
            AllocationRuleType::PerDay => $this->validatePerDay($configuration),
            AllocationRuleType::Fixed => $this->validateFixed($configuration),
            AllocationRuleType::Hybrid => $this->validateHybrid($configuration),
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function validatePerDay(array $configuration): array
    {
        // Per-day needs no extra knobs today; reject unknown keys to keep configs strict.
        $unknown = array_diff(array_keys($configuration), []);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'configuration' => ['per_day rules do not accept configuration keys: '.implode(', ', $unknown)],
            ]);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function validateFixed(array $configuration): array
    {
        $applyTo = $configuration['apply_to'] ?? null;

        if (! is_string($applyTo) || FixedApplyTo::tryFrom($applyTo) === null) {
            throw ValidationException::withMessages([
                'configuration.apply_to' => ['fixed rules require apply_to: all_members|active_members|full_period_members'],
            ]);
        }

        $allowed = ['apply_to'];
        $unknown = array_diff(array_keys($configuration), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'configuration' => ['fixed rules only accept: '.implode(', ', $allowed)],
            ]);
        }

        return ['apply_to' => $applyTo];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function validateHybrid(array $configuration): array
    {
        $mode = $configuration['mode'] ?? 'percentage';

        if (! in_array($mode, ['percentage', 'amount_remainder'], true)) {
            throw ValidationException::withMessages([
                'configuration.mode' => ['Hybrid mode must be percentage or amount_remainder.'],
            ]);
        }

        $unknown = array_diff(array_keys($configuration), ['mode', 'components']);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'configuration' => ['hybrid rules only accept: mode, components'],
            ]);
        }

        $components = $configuration['components'] ?? null;

        if (! is_array($components) || $components === []) {
            throw ValidationException::withMessages([
                'configuration.components' => ['hybrid rules require a non-empty components array.'],
            ]);
        }

        return match ($mode) {
            'percentage' => [
                'mode' => 'percentage',
                'components' => $this->validatePercentageComponents($components),
            ],
            'amount_remainder' => [
                'mode' => 'amount_remainder',
                'components' => $this->validateAmountRemainderComponents($components),
            ],
        };
    }

    /**
     * @param  list<mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function validatePercentageComponents(array $components): array
    {
        $normalized = [];
        $totalPercent = '0';

        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}" => ['Each component must be an object.'],
                ]);
            }

            if (array_key_exists('amount', $component) || array_key_exists('share', $component)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}" => ['Percentage hybrid components cannot use amount or share.'],
                ]);
            }

            $type = $component['type'] ?? null;
            $percentage = $component['percentage'] ?? null;

            if (! in_array($type, ['fixed', 'per_day'], true)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}.type" => ['Component type must be fixed or per_day.'],
                ]);
            }

            if (! is_numeric($percentage) || (float) $percentage <= 0) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}.percentage" => ['Percentage must be a number greater than 0.'],
                ]);
            }

            $row = [
                'type' => $type,
                'percentage' => (float) $percentage,
            ];

            if ($type === 'fixed') {
                $row['apply_to'] = $this->requireApplyTo($component, $index);
            }

            $normalized[] = $row;
            $totalPercent = bcadd($totalPercent, (string) $percentage, 2);
        }

        if (bccomp($totalPercent, '100.00', 2) !== 0) {
            throw ValidationException::withMessages([
                'configuration.components' => ["Hybrid component percentages must sum to 100 (got {$totalPercent})."],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $components
     * @return list<array<string, mixed>>
     */
    private function validateAmountRemainderComponents(array $components): array
    {
        $normalized = [];
        $remainderIndexes = [];

        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}" => ['Each component must be an object.'],
                ]);
            }

            if (array_key_exists('percentage', $component)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}" => ['amount_remainder components cannot use percentage.'],
                ]);
            }

            $type = $component['type'] ?? null;

            if (! in_array($type, ['fixed', 'per_day'], true)) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}.type" => ['Component type must be fixed or per_day.'],
                ]);
            }

            $isRemainder = ($component['share'] ?? null) === 'remainder';

            if ($isRemainder) {
                if (array_key_exists('amount', $component) && $component['amount'] !== null && $component['amount'] !== '') {
                    throw ValidationException::withMessages([
                        "configuration.components.{$index}.amount" => ['Remainder component cannot also set amount.'],
                    ]);
                }

                $row = [
                    'type' => $type,
                    'share' => 'remainder',
                ];

                if ($type === 'fixed') {
                    $row['apply_to'] = $this->requireApplyTo($component, $index);
                }

                $normalized[] = $row;
                $remainderIndexes[] = $index;

                continue;
            }

            $amount = $component['amount'] ?? null;

            if (! is_numeric($amount) || Money::compare(Money::of($amount), '0.00') !== 1) {
                throw ValidationException::withMessages([
                    "configuration.components.{$index}.amount" => ['Amount must be greater than zero, or use share=remainder.'],
                ]);
            }

            $row = [
                'type' => $type,
                'amount' => Money::of($amount),
            ];

            if ($type === 'fixed') {
                $row['apply_to'] = $this->requireApplyTo($component, $index);
            }

            $normalized[] = $row;
        }

        if (count($remainderIndexes) !== 1) {
            throw ValidationException::withMessages([
                'configuration.components' => ['amount_remainder mode requires exactly one component with share=remainder.'],
            ]);
        }

        $absoluteCount = count($normalized) - 1;

        if ($absoluteCount < 1) {
            throw ValidationException::withMessages([
                'configuration.components' => ['amount_remainder mode requires at least one absolute amount component.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    private function requireApplyTo(array $component, int $index): string
    {
        $applyTo = $component['apply_to'] ?? null;

        if (! is_string($applyTo) || FixedApplyTo::tryFrom($applyTo) === null) {
            throw ValidationException::withMessages([
                "configuration.components.{$index}.apply_to" => ['Fixed components require apply_to.'],
            ]);
        }

        return $applyTo;
    }
}
