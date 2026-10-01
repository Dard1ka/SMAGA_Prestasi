<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Support\Dates;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Report deadline = final_on + N days (setting `report_deadline_days`, default 45 — D-25).
 * The due date itself is still on time; the report is overdue from the next day.
 */
final class ReportDeadline
{
    public const DEFAULT_DAYS = 45;

    public const MAX_DAYS = 365;

    public function __construct(private readonly int $days = self::DEFAULT_DAYS)
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Batas laporan harus 1–365 hari.');
        }
    }

    public function days(): int
    {
        return $this->days;
    }

    public function dueDate(DateTimeInterface|string $finalOn): CarbonImmutable
    {
        return Dates::toDate($finalOn)->addDays($this->days);
    }

    /** Days left until the due date: 0 on the due date, negative once overdue. */
    public function daysLeft(DateTimeInterface|string $finalOn, DateTimeInterface|string $today): int
    {
        return Dates::daysBetween($today, $this->dueDate($finalOn));
    }

    public function isOverdue(DateTimeInterface|string $finalOn, DateTimeInterface|string $today): bool
    {
        return $this->daysLeft($finalOn, $today) < 0;
    }
}
