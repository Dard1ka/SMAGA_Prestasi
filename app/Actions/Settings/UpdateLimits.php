<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Archive\GraduationCalculator;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengaturan › Batas & arsip: `archive_months_after_grade12` (default 18, D-13). Changing it recalculates
 * `archive_at` (= grade12_start_date + N months) for every student who is not archived yet, so the daily job follows
 * the new rule; archived accounts are never touched. Audited (`settings.limits_updated`, from/to + count).
 * D-151: a laporan hasil has no deadline any more; `report_deadline_days` stays in the settings but is no longer
 * edited here.
 */
final class UpdateLimits
{
    public const MIN_MONTHS = 1;

    public const MAX_MONTHS = GraduationCalculator::MAX_ARCHIVE_MONTHS;

    /**
     * @return array{recalculated: int}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, int $archiveMonths): array
    {
        Gate::forUser($actor)->authorize('update', Setting::class);

        if ($archiveMonths < self::MIN_MONTHS || $archiveMonths > self::MAX_MONTHS) {
            throw ValidationException::withMessages(['archiveMonths' => 'Isi 1–120 bulan.']);
        }

        return DB::transaction(function () use ($actor, $archiveMonths): array {
            Setting::query()
                ->where('key', SettingKey::ArchiveMonthsAfterGrade12->value)
                ->lockForUpdate()
                ->get();

            $fromMonths = Setting::int(SettingKey::ArchiveMonthsAfterGrade12);

            if ($fromMonths === $archiveMonths) {
                return ['recalculated' => 0];
            }

            Setting::put(SettingKey::ArchiveMonthsAfterGrade12, $archiveMonths);
            $recalculated = $this->recalculateArchiveDates(new GraduationCalculator($archiveMonths));

            activity('setting')
                ->causedBy($actor)
                ->event('settings.limits_updated')
                ->withProperties([
                    'archive_months_after_grade12' => ['from' => $fromMonths, 'to' => $archiveMonths],
                    'archive_dates_recalculated' => $recalculated,
                ])
                ->log('settings.limits_updated');

            return ['recalculated' => $recalculated];
        });
    }

    private function recalculateArchiveDates(GraduationCalculator $calculator): int
    {
        $count = 0;

        DB::table('students')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->whereNotNull('students.grade12_start_date')
            ->whereNull('students.deleted_at')
            ->where('users.status', '!=', UserStatus::Archived->value)
            ->select(['students.id', 'students.grade12_start_date', 'students.archive_at'])
            ->orderBy('students.id')
            ->lockForUpdate()
            ->chunk(500, function ($rows) use ($calculator, &$count): void {
                foreach ($rows as $row) {
                    $archiveAt = $calculator->archiveAtFrom(substr((string) $row->grade12_start_date, 0, 10))->toDateString();

                    if (substr((string) $row->archive_at, 0, 10) !== $archiveAt) {
                        DB::table('students')->where('id', $row->id)->update(['archive_at' => $archiveAt, 'updated_at' => now()]);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
