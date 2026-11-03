<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calendar-date helpers. Domain rules work on dates (no time of day); a DateTimeInterface keeps
 * its own timezone, so "today" in Asia/Jakarta stays the Jakarta calendar day.
 */
final class Dates
{
    /**
     * Midnight of the given calendar day. Strings must be strict "Y-m-d" or "Y-m-d H:i:s" (raw DB value,
     * time dropped), read in the app timezone.
     */
    public static function toDate(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        $value = trim($value);

        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return CarbonImmutable::instance($date)->startOfDay();
            }
        }

        throw new InvalidArgumentException('Tanggal harus berformat YYYY-MM-DD.');
    }

    /** Whole calendar days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): int
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable(self::toDate($from)->format('Y-m-d'), $utc);
        $end = new DateTimeImmutable(self::toDate($to)->format('Y-m-d'), $utc);

        return (int) $start->diff($end)->format('%r%a');
    }
}
