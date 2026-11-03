<?php

declare(strict_types=1);

namespace App\Console\Commands\Registrations;

use App\Actions\Registrations\AwaitReport;
use App\Actions\Registrations\StartCompetition;
use App\Actions\Registrations\TimelineTransition;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily timeline job (ARCHITECTURE §6, scheduled in routes/console.php, W2-A2 block). Thin wrapper
 * since B3 (backlog #14): the transitions live in app/Actions/Registrations
 *   StartCompetition  surat_terbit → berlangsung        when today ≥ competition.starts_on
 *   AwaitReport       berlangsung  → menunggu_laporan   when today > competition.final_on (ketua notified)
 * Both run in this order, so a lomba whose final has also passed moves two steps in one run.
 * Idempotent: every row is re-checked under lockForUpdate() inside the Action.
 */
final class AdvanceRegistrations extends Command
{
    protected $signature = 'sth:registrations:advance
        {--date= : Tanggal acuan Y-m-d (default: hari ini, Asia/Jakarta)}';

    protected $description = 'Move registrations along the lomba timeline (surat_terbit → berlangsung → menunggu_laporan).';

    public function handle(StartCompetition $start, AwaitReport $await): int
    {
        // QA10: like sth:arsip-otomatis, a reference date is for checks on a local database only; in production a
        // future date would move live registrations and mail every ketua.
        if ($this->dateGiven() && app()->isProduction()) {
            $this->error('--date tidak boleh dipakai di production.');

            return self::INVALID;
        }

        $today = $this->today();

        if ($today === null) {
            $this->error('Format --date harus Y-m-d.');

            return self::FAILURE;
        }

        $started = $this->advance($start, $today);
        $awaiting = $this->advance($await, $today);

        $this->info(sprintf('Berlangsung: %d · menunggu laporan: %d', $started, $awaiting));

        return self::SUCCESS;
    }

    private function advance(TimelineTransition $step, CarbonImmutable $today): int
    {
        $moved = 0;

        foreach ($step->due($today)->pluck('id') as $id) {
            $moved += $step->handle((string) $id, $today) !== null ? 1 : 0;
        }

        return $moved;
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
