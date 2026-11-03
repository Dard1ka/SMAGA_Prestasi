<?php

declare(strict_types=1);

namespace App\Actions\Archive;

use App\Domain\Archive\GraduationCalculator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules for a manually chosen archive date (Admin › Arsip otomatis, SECURITY §3m-8):
 * - strictly after today (Asia/Jakarta), otherwise the 01:00 job archives the account again tonight;
 * - at most MAX_YEARS ahead;
 * - the matching kelas XII start = date − N months (setting `archive_months_after_grade12`), so a later change
 *   of that setting (UpdateLimits recomputes archive_at from grade12_start_date) keeps the manual shift.
 */
final class ArchiveDates
{
    public const MAX_YEARS = 10;

    /**
     * @throws ValidationException
     */
    public static function validated(DateTimeInterface|string $date, string $field = 'archiveDate'): CarbonImmutable
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        try {
            $day = CarbonImmutable::parse($date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date, config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Tanggal tidak valid.']);
        }

        if ($day->lessThanOrEqualTo($today)) {
            throw ValidationException::withMessages([$field => 'Pilih tanggal setelah hari ini.']);
        }

        if ($day->greaterThan($today->addYears(self::MAX_YEARS))) {
            throw ValidationException::withMessages([$field => 'Maksimal '.self::MAX_YEARS.' tahun dari sekarang.']);
        }

        return $day;
    }

    public static function grade12StartFor(CarbonImmutable $archiveAt): CarbonImmutable
    {
        return $archiveAt->subMonthsNoOverflow(app(GraduationCalculator::class)->archiveMonths());
    }

    /**
     * @throws ValidationException
     */
    public static function reason(string $reason, string $field = 'archiveReason'): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([$field => 'Isi alasan (maks. 500 karakter).']);
        }

        return $reason;
    }
}
