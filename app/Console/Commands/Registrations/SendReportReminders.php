<?php

declare(strict_types=1);

namespace App\Console\Commands\Registrations;

use App\Domain\Deadlines\ReportFollowUp;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Notifications\Reports\ReportReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily reminder for reports that are still missing (scheduled in routes/console.php, W2-A2 block).
 * D-151: a laporan hasil has no deadline. For every registration in menunggu_laporan without any report the ketua gets
 * one reminder 3 days and one 14 days after `final_on` (ReportFollowUp::REMINDER_DAYS). Idempotent: a row in
 * `registration_reminders` (unique registration_id + kind `report_after_<n>`) is inserted first and only the run that
 * inserts it sends the mail. A missed day is caught up for CATCH_UP_DAYS, and then only the latest reminder that is due
 * goes out; anything further back is left alone, so the first run after the deploy sends no burst to old rows.
 * A report that waits for its revision (back in menunggu_laporan) was sent: no reminder.
 */
final class SendReportReminders extends Command
{
    /** How many days after its day a missed reminder is still sent. */
    public const CATCH_UP_DAYS = 7;

    protected $signature = 'sth:reports:remind
        {--date= : Tanggal acuan Y-m-d (default: hari ini, Asia/Jakarta)}';

    protected $description = 'Remind team leaders 3 and 14 days after the final to report the result.';

    public function handle(): int
    {
        // QA10: like sth:arsip-otomatis, a reference date is for checks on a local database only; in production a
        // future date would mail every ketua a reminder (and use up the real one).
        if ($this->dateGiven() && app()->isProduction()) {
            $this->error('--date tidak boleh dipakai di production.');

            return self::INVALID;
        }

        $today = $this->today();

        if ($today === null) {
            $this->error('Format --date harus Y-m-d.');

            return self::FAILURE;
        }

        // Only finals that lie inside one of the reminder windows: day 3 … day max + CATCH_UP_DAYS.
        $latest = $today->subDays(min(ReportFollowUp::REMINDER_DAYS))->toDateString();
        $earliest = $today->subDays(max(ReportFollowUp::REMINDER_DAYS) + self::CATCH_UP_DAYS)->toDateString();
        $sent = 0;

        Registration::query()
            ->where('status', RegistrationStatus::MenungguLaporan)
            ->whereDoesntHave('report')
            ->whereHas('competition', fn (Builder $c) => $c->withTrashed()
                ->whereNotNull('final_on')
                ->whereDate('final_on', '>=', $earliest)
                ->whereDate('final_on', '<=', $latest))
            ->with(['competition' => fn ($c) => $c->withTrashed(), 'leader.user'])
            ->chunkById(200, function ($registrations) use ($today, &$sent): void {
                foreach ($registrations as $registration) {
                    $sent += $this->remind($registration, $today) ? 1 : 0;
                }
            });

        $this->info(sprintf('Pengingat terkirim: %d', $sent));

        return self::SUCCESS;
    }

    /** The reminder day due today (the latest one within its catch-up window), or null. */
    public static function dueReminder(int $daysSinceFinal): ?int
    {
        $due = array_filter(
            ReportFollowUp::REMINDER_DAYS,
            fn (int $day): bool => $daysSinceFinal >= $day && $daysSinceFinal <= $day + self::CATCH_UP_DAYS,
        );

        return $due === [] ? null : max($due);
    }

    private function remind(Registration $registration, CarbonImmutable $today): bool
    {
        $finalOn = $registration->competition?->final_on;

        if ($finalOn === null) {
            return false;
        }

        $due = self::dueReminder(ReportFollowUp::daysSinceFinal($finalOn, $today));

        if ($due === null) {
            return false;
        }

        $user = $registration->leader?->user;

        if ($user === null || ! $user->canLogIn()) {
            return false;
        }

        $inserted = DB::table('registration_reminders')->insertOrIgnore([
            'registration_id' => $registration->getKey(),
            'kind' => 'report_after_'.$due,
            'sent_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        $user->notify(new ReportReminder(
            (string) $registration->getKey(),
            (string) $registration->competition->name,
            $due,
        ));

        return true;
    }

    private function dateGiven(): bool
    {
        $option = $this->option('date');

        return $option !== null && $option !== '';
    }

    private function today(): ?CarbonImmutable
    {
        $option = $this->option('date');

        if ($option === null || $option === '') {
            return CarbonImmutable::now()->startOfDay();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $option);
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $option ? $date : null;
    }
}
