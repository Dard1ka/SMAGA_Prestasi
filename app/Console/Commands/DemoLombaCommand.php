<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Registrations\AwaitReport;
use App\Actions\Registrations\StartCompetition;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Database\Seeders\LombaContohSeeder;
use Illuminate\Console\Command;

/**
 * smaga_server (the lead, 2026-11-05): a presentation lomba whose whole flow fits in one day, from "Daftar" to the
 * laporan hasil.
 *
 *     php artisan sth:lomba:demo              creates today's demo lomba (published, individu, fictional)
 *     php artisan sth:lomba:demo --majukan    moves its registrations whose surat tugas was sent on to the report step
 *
 * The lomba is LombaContohSeeder's sample lomba with today's dates and bidang Sains (its laporan hasil needs only the
 * certificate and photos): registration opens and closes today (23:59), the one-day lomba is today. It is stored as "Tambah lomba" + publish store it (audit `source` = demo), by the first active
 * Admin, and tells nobody. Its name carries the date, so a second run on the same day changes nothing and the next day
 * gets its own lomba.
 *
 * Why --majukan: the daily timeline job (`sth:registrations:advance`, 00:15) starts a lomba on `starts_on` and opens the
 * report window only the day AFTER `final_on` (AwaitReport, today > final_on). For a same-day presentation that is
 * tomorrow, so --majukan runs the same two Actions now for THIS lomba only: surat_terbit → berlangsung (today ≥ starts_on)
 * → menunggu_laporan (reference date final_on + 1). Every other lomba and registration is left alone; the ketua gets the
 * normal ReportWindowOpened notice and the audit rows are the Actions' own. Refused in production, as --date of the
 * timeline job.
 */
final class DemoLombaCommand extends Command
{
    protected $signature = 'sth:lomba:demo
        {--majukan : Bawa pendaftaran lomba demo hari ini yang surat tugasnya sudah dikirim ke tahap laporan hasil}';

    protected $description = 'Lomba demo untuk presentasi: pendaftaran, lomba, dan laporan hasil semuanya hari ini.';

    public const NAME_PREFIX = 'Lomba Demo Presentasi STH';

    public const NO_ADMIN = 'Lomba demo tidak dibuat: belum ada akun Admin yang aktif.';

    public const NOT_YET = 'Lomba demo hari ini belum ada. Jalankan dulu: php artisan sth:lomba:demo';

    /** Registrations that still have to pass approval or the surat tugas before --majukan can move them. */
    private const BEFORE_LETTERS = [
        RegistrationStatus::MenungguKonfirmasi,
        RegistrationStatus::MenungguKepsek,
        RegistrationStatus::Disetujui,
    ];

    /** The demo lomba's name for a day, e.g. "Lomba Demo Presentasi STH 5 November 2026 (Contoh)". */
    public static function nameFor(CarbonImmutable $day): string
    {
        return sprintf('%s %s (Contoh)', self::NAME_PREFIX, $day->locale('id')->translatedFormat('j F Y'));
    }

    public function handle(StartCompetition $start, AwaitReport $await): int
    {
        if (app()->isProduction()) {
            $this->error('Lomba demo tidak dibuat di production.');

            return self::INVALID;
        }

        $today = CarbonImmutable::today();

        return $this->option('majukan') ? $this->advance($today, $start, $await) : $this->create($today);
    }

    private function create(CarbonImmutable $today): int
    {
        $name = self::nameFor($today);
        $existing = Competition::query()->where('name', $name)->first();

        if ($existing !== null) {
            $this->info(sprintf('Lomba demo hari ini sudah ada: %s (status: %s). Tidak ada yang diubah.', $name, $existing->status?->label() ?? '–'));

            return self::SUCCESS;
        }

        $admin = LombaContohSeeder::firstActiveAdmin();

        if ($admin === null) {
            $this->error(self::NO_ADMIN);

            return self::FAILURE;
        }

        app(LombaContohSeeder::class)->publish(
            $admin,
            $name,
            sprintf('juknis-lomba-demo-presentasi-%s-contoh.pdf', $today->format('Ymd')),
            $today,
            $today,
            $today,
            'demo',
            // Sains, not the sample's Riset: a Riset laporan hasil also asks a research title, abstract and report PDF.
            Bidang::Sains,
        );

        $this->info('Lomba demo dibuat dan dipublikasikan: '.$name.'.');
        $this->line(sprintf('Pendaftaran dibuka dan ditutup %s (sampai pukul 23.59); lomba juga hari ini. Peserta: individu.', $today->locale('id')->translatedFormat('j F Y')));
        $this->line('Setelah Tata Usaha mengirim surat tugas, jalankan: php artisan sth:lomba:demo --majukan');

        return self::SUCCESS;
    }

    private function advance(CarbonImmutable $today, StartCompetition $start, AwaitReport $await): int
    {
        // Only the published lomba: a student's proposal that happens to carry the same name is never touched.
        $competition = Competition::query()
            ->where('name', self::nameFor($today))
            ->where('status', CompetitionStatus::Dipublikasikan)
            ->first();

        if ($competition === null || $competition->final_on === null) {
            $this->error(self::NOT_YET);

            return self::FAILURE;
        }

        // AwaitReport opens the report window once the reference day is after final_on.
        $dayAfterFinal = CarbonImmutable::parse($competition->final_on->toDateString())->addDay();

        foreach ($this->ids($competition, RegistrationStatus::SuratTerbit) as $id) {
            $start->handle($id, $today);
        }

        $moved = 0;

        foreach ($this->ids($competition, RegistrationStatus::Berlangsung) as $id) {
            $moved += $await->handle($id, $dayAfterFinal) !== null ? 1 : 0;
        }

        $waiting = Registration::query()
            ->where('competition_id', $competition->getKey())
            ->whereIn('status', self::BEFORE_LETTERS)
            ->count();

        $this->info(sprintf('Lomba demo dimajukan: %d pendaftaran sekarang bisa mengirim laporan hasil.', $moved));

        if ($waiting > 0) {
            $this->line(sprintf('%d pendaftaran belum sampai surat tugas terkirim; jalankan perintah ini lagi setelah Tata Usaha mengirim suratnya.', $waiting));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function ids(Competition $competition, RegistrationStatus $status): array
    {
        return Registration::query()
            ->where('competition_id', $competition->getKey())
            ->where('status', $status)
            ->oldest()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
