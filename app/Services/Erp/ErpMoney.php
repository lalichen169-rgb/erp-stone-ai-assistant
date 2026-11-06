<?php

namespace App\Services\Erp;

/**
 * Decimal-safe money helpers for ERP calculations (BCMath).
 *
 * Currency amounts are rounded half-up to two decimal places unless noted.
 */
final class ErpMoney
{
    private const CALC_SCALE = 8;

    public const CURRENCY_SCALE = 2;

    public static function mul(string $left, string $right): string
    {
        return bcmul($left, $right, self::CALC_SCALE);
    }

    public static function div(string $left, string $right): string
    {
        if (bccomp($right, '0', self::CALC_SCALE) === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return bcdiv($left, $right, self::CALC_SCALE);
    }

    public static function add(string ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, $value, self::CALC_SCALE);
        }

        return $total;
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub($left, $right, self::CALC_SCALE);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, self::CALC_SCALE);
    }

    public static function roundCurrency(string $amount): string
    {
        $sign = '';
        if (str_starts_with($amount, '-')) {
            $sign = '-';
            $amount = substr($amount, 1);
        }

        $increment = '0.'.str_repeat('0', self::CURRENCY_SCALE).'5';
        $rounded = bcadd($amount, $increment, self::CURRENCY_SCALE);

        if ($sign === '-') {
            $rounded = bcsub('0', $rounded, self::CURRENCY_SCALE);
        }

        return self::formatCurrency($rounded);
    }

    public static function formatCurrency(string $amount): string
    {
        return bcadd($amount, '0', self::CURRENCY_SCALE);
    }

    public static function fromDecimal(string|int|float $amount): string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }

        if (is_float($amount)) {
            return number_format($amount, self::CALC_SCALE, '.', '');
        }

        return trim((string) $amount);
    }
}
