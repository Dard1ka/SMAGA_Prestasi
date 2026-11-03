<?php

declare(strict_types=1);

namespace App\Domain\Archive;

use Carbon\CarbonImmutable;

/**
 * Computed student dates stored on students.grade12_start_date / students.archive_at.
 */
final readonly class GraduationDates
{
    public function __construct(
        public CarbonImmutable $grade12StartDate,
        public CarbonImmutable $archiveAt,
    ) {}
}
