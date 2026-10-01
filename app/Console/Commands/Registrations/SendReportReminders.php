<?php

declare(strict_types=1);

namespace App\Console\Commands\Registrations;

use App\Domain\Deadlines\ReportDeadline;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Notifications\Reports\ReportReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily reminder for reports that are still missing (scheduled in routes/console.php, W2-A2 block).
 * For every registration in menunggu_laporan the ketua gets one reminder when the due date
 * (final_on + report_deadline_days, D-25) is 14 days away and one when it is 3 days away.
 * Idempotent: a row in `registration_reminders` (unique registration_id + kind) is inserted first and
 * only the run that inserts it sends the mail; if the job missed a day, only the most urgent reminder
 * that is due goes out. Overdue reports get no reminder (the Lomba saya screen shows "Lewat batas").
 */
final class SendReportReminders extends Command
{
    /** Days before the due date. */
    public const THRESHOLDS = [14, 3];

    protected $signature = 'sth:reports:remind
        {--date= : Tanggal acuan Y-m-d (default: hari ini, Asia/Jakarta)}';

    protected $description = 'Remind team leaders about laporan hasil that are due in 14 or 3 days.';

    public function handle(): int
    {
        $today = $this->today();

        if ($today === null) {
            $this->error('Format --date harus Y-m-d.');

            return self::FAILURE;
        }

        $deadline = app(ReportDeadline::class);
        $sent = 0;

        Registration::query()
            ->where('status', RegistrationStatus::MenungguLaporan)
            ->whereHas('competition', fn (Builder $c) => $c->withTrashed()->whereNotNull('final_on'))
            ->with(['competition', 'leader.user'])
            ->chunkById(200, function ($registrations) use ($deadline, $today, &$sent): void {
                foreach ($registrations as $registration) {
                    $sent += $this->remind($registration, $deadline, $today) ? 1 : 0;
                }
            });

        $this->info(sprintf('Pengingat terkirim: %d', $sent));

        return self::SUCCESS;
    }

    private function remind(Registration $registration, ReportDeadline $deadline, CarbonImmutable $today): bool
    {
        $finalOn = $registration->competition->final_on;
        $daysLeft = $deadline->daysLeft($finalOn, $today);

        $due = array_values(array_filter(self::THRESHOLDS, fn (int $days): bool => $daysLeft >= 0 && $daysLeft <= $days));

        if ($due === []) {
            return false;
        }

        $user = $registration->leader?->user;

        if ($user === null || ! $user->canLogIn()) {
            return false;
        }

        $inserted = DB::table('registration_reminders')->insertOrIgnore([
            'registration_id' => $registration->getKey(),
            'kind' => 'report_due_'.min($due),
            'sent_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        $user->notify(new ReportReminder(
            (string) $registration->getKey(),
            (string) $registration->competition->name,
            $deadline->dueDate($finalOn)->locale('id')->translatedFormat('j M Y'),
            $daysLeft,
        ));

        return true;
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
