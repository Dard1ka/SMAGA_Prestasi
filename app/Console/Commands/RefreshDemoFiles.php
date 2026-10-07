<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\File;
use Database\Seeders\Support\Demo\DemoFileRefresher;
use Illuminate\Console\Command;

/**
 * LOCAL ONLY (refuses to run unless APP_ENV=local): replaces the placeholder PDFs of the demo data that is already
 * in the database by realistic sample documents (certificate, piagam, research report, juknis, brochure, poster,
 * surat tugas), and the placeholder covers of the demo lomba by drawn posters, without seeding again.
 *
 *   docker compose exec -T app php artisan sth:demo:refresh-files
 *
 * Touches only `files` rows whose path is under demo/ (what Database\Seeders\Support\DemoFiles writes): the bytes at
 * the same path, `size` and `sha256`. Files uploaded by hand and every other table stay as they are. Safe to run
 * again: files that are up to date are left alone (`--force` makes all of them again). `-v` lists every file,
 * `--berkas="sertifikat-*"` limits the run to files with such a name (`--berkas="sampul-*"`: the covers only).
 *
 * `--lengkapi` is the one option that adds or fills rows, as a fresh demo seed does: a research report file
 * (`files` + `report_files`) for demo riset reports that have a research title but no such file, a cover (`files` +
 * `competitions.cover_file_id`) for the demo lomba that have none, and the number and date of the invitation on
 * demo letters that lack them (QA5). Without it the command says what it would add.
 *
 * Every page of every document carries "CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan dokumen resmi";
 * every poster carries a CONTOH stamp and "… bukan poster resmi".
 */
final class RefreshDemoFiles extends Command
{
    protected $signature = 'sth:demo:refresh-files
        {--force : Buat ulang semua berkas demo, juga yang sudah terbaru}
        {--berkas=* : Hanya berkas demo yang namanya cocok, boleh pakai tanda bintang, mis. --berkas="piagam-*" (boleh diulang)}
        {--lengkapi : Tambahkan juga laporan penelitian pada laporan riset demo yang belum punya, sampul pada lomba demo yang belum punya, dan nomor serta tanggal surat undangan pada surat demo yang belum punya}';

    protected $description = 'Ganti berkas placeholder data demo dengan dokumen dan sampul contoh yang realistis (hanya APP_ENV=local)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah ini hanya untuk APP_ENV=local. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        $refresher = app(DemoFileRefresher::class);
        $started = microtime(true);

        if ($this->option('lengkapi')) {
            $this->line('Laporan penelitian ditambahkan pada '.$refresher->attachMissing().' laporan riset demo.');
            $this->line('Sampul ditambahkan pada '.$refresher->attachMissingCovers().' lomba demo.');
            $this->line('Nomor dan tanggal surat undangan dilengkapi pada '.$refresher->fillLetterBasis().' surat demo.');
        } elseif (($hint = self::completionHint($refresher->missing())) !== null) {
            $this->line($hint);
        }

        $summary = $refresher->refresh(
            (bool) $this->option('force'),
            $this->output->isVerbose() ? fn (File $file, string $what) => $this->line(sprintf('  %s  %s', $what, $file->original_name)) : null,
            array_values(array_filter(array_map(strval(...), (array) $this->option('berkas')), fn (string $name): bool => $name !== '')),
        );

        foreach (DemoFileRefresher::lines($summary) as $line) {
            $this->line($line);
        }

        $this->line(sprintf('Selesai dalam %.1f detik.', microtime(true) - $started));

        return ($summary['skipped']['gagal dibuat'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array{reports: int, letters: int, covers: int} $missing */
    private static function completionHint(array $missing): ?string
    {
        $parts = array_values(array_filter([
            $missing['reports'] > 0 ? $missing['reports'].' laporan riset demo tanpa laporan penelitian' : null,
            $missing['covers'] > 0 ? $missing['covers'].' lomba demo tanpa sampul' : null,
            $missing['letters'] > 0 ? $missing['letters'].' surat demo tanpa nomor undangan' : null,
        ]));

        return $parts === [] ? null : 'Jalankan dengan --lengkapi untuk melengkapi: '.implode(', ', $parts).'.';
    }
}
