<?php

declare(strict_types=1);

namespace App\Domain\Support;

use InvalidArgumentException;

/**
 * Decimal-string helpers on top of bcmath. Values are plain numeric strings ("80", "0.85", "33.60").
 */
final class Decimal
{
    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    /** Normalise an int/float/string into a plain numeric string (no exponent, no thousands separator). */
    public static function normalize(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Angka tidak valid.');
            }

            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        $value = trim($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Angka tidak valid.');
        }

        return $value === '-0' ? '0' : $value;
    }

    /**
     * Can normalize() handle this value? Callers that format user input must ask here rather than guess
     * with is_numeric(), which accepts "0.", ".5", "+5" and "1e5" -- all of which normalize() rejects.
     * Delegates to normalize() so the two can never drift apart.
     */
    public static function isValid(string|int|float $value): bool
    {
        try {
            self::normalize($value);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** Normalise and reject negative values. */
    public static function nonNegative(string|int|float $value): string
    {
        $normalized = self::normalize($value);

        if (bccomp($normalized, '0', 12) < 0) {
            throw new InvalidArgumentException('Angka tidak boleh negatif.');
        }

        return $normalized;
    }

    /** Round half-up (half away from zero) to $scale decimals; always returns exactly $scale decimals. */
    public static function roundHalfUp(string|int|float $value, int $scale): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Skala desimal tidak boleh negatif.');
        }

        $normalized = self::normalize($value);
        $offset = '0.'.str_repeat('0', $scale).'5';

        $rounded = str_starts_with($normalized, '-')
            ? bcsub($normalized, $offset, $scale)
            : bcadd($normalized, $offset, $scale);

        // Avoid "-0.00" for tiny negative values.
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }
}
