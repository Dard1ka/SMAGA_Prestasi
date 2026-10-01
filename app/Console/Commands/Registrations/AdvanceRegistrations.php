<?php

declare(strict_types=1);

namespace App\Console\Commands\Registrations;

use App\Domain\Deadlines\ReportDeadline;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Notifications\Reports\ReportWindowOpened;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily timeline job (ARCHITECTURE §6, scheduled in routes/console.php, W2-A2 block):
 *   surat_terbit → berlangsung        when today ≥ competition.starts_on
 *   berlangsung  → menunggu_laporan   when today > competition.final_on (the ketua is notified)
 * Idempotent: each row is re-read under lockForUpdate() and skipped when its status already moved.
 * Both steps run in order, so a lomba whose final has also passed moves two steps in one run.
 * Every transition goes through RegistrationStateMachine::assertTransition() and is audited
 * (activity log `registration`, event registration.berlangsung / registration.menunggu_laporan, causer = system).
 */
final class AdvanceRegistrations extends Command
{
    protected $signature = 'sth:registrations:advance
        {--date= : Tanggal acuan Y-m-d (default: hari ini, Asia/Jakarta)}';

    protected $description = 'Move registrations along the lomba timeline (surat_terbit → berlangsung → menunggu_laporan).';

    public function handle(RegistrationStateMachine $machine): int
    {
        $today = $this->today();

        if ($today === null) {
            $this->error('Format --date harus Y-m-d.');

            return self::FAILURE;
        }

        $started = $this->advance(
            $machine,
            RegistrationStatus::SuratTerbit,
            RegistrationStatus::Berlangsung,
            fn (Builder $c) => $c->whereNotNull('starts_on')->whereDate('starts_on', '<=', $today->toDateString()),
        );

        $awaiting = $this->advance(
            $machine,
            RegistrationStatus::Berlangsung,
            RegistrationStatus::MenungguLaporan,
            fn (Builder $c) => $c->whereNotNull('final_on')->whereDate('final_on', '<', $today->toDateString()),
        );

        $this->notifyLeaders($awaiting);

        $this->info(sprintf('Berlangsung: %d · menunggu laporan: %d', count($started), count($awaiting)));

        return self::SUCCESS;
    }

    /**
     * @param  Closure(Builder): mixed  $competitionConstraint
     * @return list<Registration> registrations that moved in this run
     */
    private function advance(
        RegistrationStateMachine $machine,
        RegistrationStatus $from,
        RegistrationStatus $to,
        Closure $competitionConstraint,
    ): array {
        $ids = Registration::query()
            ->where('status', $from)
            ->whereHas('competition', fn (Builder $c) => $competitionConstraint($c->withTrashed()))
            ->orderBy('id')
            ->pluck('id');

        $moved = [];

        foreach ($ids as $id) {
            $registration = DB::transaction(function () use ($machine, $id, $from, $to): ?Registration {
                /** @var Registration|null $locked */
                $locked = Registration::query()->whereKey($id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== $from) {
                    return null; // already moved by another run, cancelled, or deleted
                }

                $machine->assertTransition($locked->status, $to);

                $locked->forceFill(['status' => $to])->save();

                activity('registration')
                    ->performedOn($locked)
                    ->event('registration.'.$to->value)
                    ->withProperties(['from' => $from->value, 'to' => $to->value, 'by' => 'scheduler'])
                    ->log('registration.'.$to->value);

                return $locked;
            });

            if ($registration !== null) {
                $moved[] = $registration;
            }
        }

        return $moved;
    }

    /** @param list<Registration> $registrations */
    private function notifyLeaders(array $registrations): void
    {
        if ($registrations === []) {
            return;
        }

        $deadline = app(ReportDeadline::class);

        foreach ($registrations as $registration) {
            $registration->loadMissing(['competition', 'leader.user']);
            $user = $registration->leader?->user;
            $finalOn = $registration->competition?->final_on;

            if ($user === null || $finalOn === null || ! $user->canLogIn()) {
                continue;
            }

            $user->notify(new ReportWindowOpened(
                (string) $registration->getKey(),
                (string) $registration->competition->name,
                $deadline->dueDate($finalOn)->locale('id')->translatedFormat('j M Y'),
            ));
        }
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
