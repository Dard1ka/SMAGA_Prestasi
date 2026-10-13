<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Support\Dates;
use DateTimeInterface;

/**
 * Laporan hasil without a deadline (D-151): the ketua is asked to report as soon as the lomba is over, reminded on
 * day 3 and day 14 after `final_on` (sth:reports:remind), and the school dashboard counts registrations that have not
 * reported more than 14 days after the final. Pure, day-level. ReportDeadline and its setting stay in code (unused for
 * display); nothing prints a due date any more.
 */
final class ReportFollowUp
{
    /** Days after `final_on` on which the ketua gets a reminder (once each). */
    public const REMINDER_DAYS = [3, 14];

    /** "Belum lapor > 14 hari" on the school dashboard: no report this many days after the final. */
    public const NOT_REPORTED_DAYS = 14;

    /** The one request the student screens and mails use instead of a deadline. */
    public const PROMPT = 'Segera laporkan hasil lombamu.';

    /** Whole calendar days from the final to $today (negative before the final). */
    public static function daysSinceFinal(DateTimeInterface|string $finalOn, DateTimeInterface|string $today): int
    {
        return Dates::daysBetween($finalOn, $today);
    }
}
