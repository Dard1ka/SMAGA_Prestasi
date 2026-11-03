<?php

declare(strict_types=1);

namespace App\Domain\Points;

use App\Domain\Support\Decimal;

/**
 * Indonesian number display for points and weights: decimal comma, "." thousands separator.
 */
final class PointFormatter
{
    /** Points: at most $maxDecimals decimals, trailing zeros dropped ("33,6", "80", "1.234,5"). */
    public function format(string|int|float $points, int $maxDecimals = 1): string
    {
        [$sign, $integer, $fraction] = $this->split(Decimal::roundHalfUp($points, $maxDecimals));
        $fraction = rtrim($fraction, '0');

        return $sign.$this->group($integer).($fraction === '' ? '' : ','.$fraction);
    }

    /** Weights: always $decimals decimals ("1,00", "0,85"). */
    public function formatWeight(string|int|float $weight, int $decimals = 2): string
    {
        [$sign, $integer, $fraction] = $this->split(Decimal::roundHalfUp($weight, $decimals));

        return $sign.$this->group($integer).($fraction === '' ? '' : ','.$fraction);
    }

    /** @return array{0: string, 1: string, 2: string} sign, integer digits, fraction digits */
    private function split(string $decimal): array
    {
        $sign = str_starts_with($decimal, '-') ? '-' : '';
        $parts = explode('.', ltrim($decimal, '-'), 2);

        return [$sign, $parts[0], $parts[1] ?? ''];
    }

    private function group(string $integer): string
    {
        return ltrim(strrev(implode('.', str_split(strrev($integer), 3))), '.');
    }
}
