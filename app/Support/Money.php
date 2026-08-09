<?php

namespace App\Support;

/**
 * Decimal-safe money helpers using BCMath (2 decimal places).
 */
final class Money
{
    public const SCALE = 8;

    public static function of(int|float|string $amount): string
    {
        if (is_float($amount)) {
            $amount = number_format($amount, 2, '.', '');
        }

        return bcadd((string) $amount, '0', 2);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 2);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 2);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (bccomp($b, '0', $scale) === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return bcdiv($a, $b, $scale);
    }

    public static function percentOf(string $amount, string $percent): string
    {
        return self::div(self::mul($amount, $percent, self::SCALE), '100', self::SCALE);
    }

    public static function round(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, 2);
    }

    /**
     * Distribute $total across positive $weights keyed by id.
     * Zero-weight keys receive 0.00.
     * Remainder is assigned to the highest positive-weight key.
     *
     * @param  array<int|string, int|string>  $weights
     * @return array<int|string, string>
     */
    public static function allocateByWeights(string $total, array $weights): array
    {
        $total = self::of($total);
        $positive = [];
        $result = [];

        foreach ($weights as $key => $weight) {
            if (bccomp((string) $weight, '0', 8) === 1) {
                $positive[$key] = $weight;
            } else {
                $result[$key] = '0.00';
            }
        }

        if ($positive === []) {
            return array_fill_keys(array_keys($weights), '0.00');
        }

        $weightSum = '0';

        foreach ($positive as $weight) {
            $weightSum = bcadd($weightSum, (string) $weight, 8);
        }

        $running = '0.00';
        $keys = array_keys($positive);
        sort($keys);

        foreach ($keys as $index => $key) {
            $isLast = $index === array_key_last($keys);

            if ($isLast) {
                $share = self::sub($total, $running);
            } else {
                $raw = self::div(
                    self::mul($total, (string) $positive[$key], self::SCALE),
                    $weightSum,
                    self::SCALE
                );
                $share = self::round($raw);
                $running = self::add($running, $share);
            }

            $result[$key] = $share;
        }

        return $result;
    }

    /**
     * Equal split among participants with remainder to highest user id.
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    public static function allocateEqually(string $total, array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        if ($userIds === []) {
            return [];
        }

        $weights = array_fill_keys($userIds, 1);

        return self::allocateByWeights($total, $weights);
    }
}
