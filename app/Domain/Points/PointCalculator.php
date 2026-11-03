<?php

declare(strict_types=1);

namespace App\Domain\Points;

use App\Domain\Support\Decimal;
use BackedEnum;

/**
 * points = w_level × w_result × w_organizer, rounded half-up to 2 decimals (ARCHITECTURE §7, D-08).
 * Every team member gets the full points, so there is no division here.
 */
final class PointCalculator
{
    public const SCALE = 2;

    private const WORK_SCALE = 12;

    public function compute(
        string|int|float $levelWeight,
        string|int|float $resultWeight,
        string|int|float $organizerWeight,
    ): string {
        $product = bcmul(
            bcmul(Decimal::nonNegative($levelWeight), Decimal::nonNegative($resultWeight), self::WORK_SCALE),
            Decimal::nonNegative($organizerWeight),
            self::WORK_SCALE,
        );

        return Decimal::roundHalfUp($product, self::SCALE);
    }

    /** Look up the three weights in $weights and snapshot them together with the points. */
    public function breakdown(
        WeightTable $weights,
        string|BackedEnum $level,
        string|BackedEnum $result,
        string|BackedEnum $organizerCategory,
    ): PointBreakdown {
        $levelWeight = $weights->level($level);
        $resultWeight = $weights->result($result);
        $organizerWeight = $weights->organizer($organizerCategory);

        return new PointBreakdown(
            $levelWeight,
            $resultWeight,
            $organizerWeight,
            $this->compute($levelWeight, $resultWeight, $organizerWeight),
        );
    }
}
