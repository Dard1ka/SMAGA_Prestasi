<?php

declare(strict_types=1);

namespace App\Domain\Archive;

use App\Domain\Support\Dates;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * grade-12 start = 1 July of the year the student starts kelas XII; archive_at = + N months
 * (setting `archive_months_after_grade12`, default 18 — D-13, D-29).
 * Example: kelas X in 2026/2027 → XII from 2028-07-01 → archived on 2030-01-01.
 */
final class GraduationCalculator
{
    public const DEFAULT_ARCHIVE_MONTHS = 18;

    public const MAX_ARCHIVE_MONTHS = 120;

    private const LEVELS = [10, 11, 12];

    public function __construct(private readonly int $archiveMonths = self::DEFAULT_ARCHIVE_MONTHS)
    {
        if ($archiveMonths < 1 || $archiveMonths > self::MAX_ARCHIVE_MONTHS) {
            throw new InvalidArgumentException('Jarak arsip harus 1–120 bulan.');
        }
    }

    public function archiveMonths(): int
    {
        return $this->archiveMonths;
    }

    public function calculate(int $classroomLevel, SchoolYear|string $importSchoolYear): GraduationDates
    {
        $grade12Start = $this->grade12StartDate($classroomLevel, $importSchoolYear);

        return new GraduationDates($grade12Start, $this->archiveAtFrom($grade12Start));
    }

    public function grade12StartDate(int $classroomLevel, SchoolYear|string $importSchoolYear): CarbonImmutable
    {
        if (! in_array($classroomLevel, self::LEVELS, true)) {
            throw new InvalidArgumentException('Tingkat kelas harus 10, 11, atau 12.');
        }

        $schoolYear = $importSchoolYear instanceof SchoolYear
            ? $importSchoolYear
            : SchoolYear::fromString($importSchoolYear);

        $year = $schoolYear->startYear + (12 - $classroomLevel);

        return Dates::toDate(sprintf('%04d-07-01', $year));
    }

    public function archiveAt(int $classroomLevel, SchoolYear|string $importSchoolYear): CarbonImmutable
    {
        return $this->archiveAtFrom($this->grade12StartDate($classroomLevel, $importSchoolYear));
    }

    /** Recompute archive_at from a stored grade12_start_date (e.g. after the setting changes). */
    public function archiveAtFrom(DateTimeInterface|string $grade12StartDate): CarbonImmutable
    {
        return Dates::toDate($grade12StartDate)->addMonthsNoOverflow($this->archiveMonths);
    }

    /** True from the archive date onwards (used by the daily archive job). */
    public function isDue(DateTimeInterface|string $archiveAt, DateTimeInterface|string $today): bool
    {
        return Dates::daysBetween($archiveAt, $today) >= 0;
    }
}
