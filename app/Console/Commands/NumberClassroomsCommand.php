<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Classrooms\NumberClassrooms;
use Illuminate\Console\Command;

/**
 * D-175 (smaga_server): the classes of an existing database become X-1…X-12, XI-1…XI-12, XII-1…XII-12; lettered
 * classes are renamed in place, empty classes outside that list are deleted. Rules: App\Actions\Classrooms\NumberClassrooms.
 *
 *   php artisan sth:kelas:nomor                       (dry run: what would change, writes nothing)
 *   php artisan sth:kelas:nomor --terapkan            (writes; APP_ENV=local, e.g. the school's server today)
 *   php artisan sth:kelas:nomor --terapkan --force    (writes in any other APP_ENV)
 *
 * Prints class names and counts only. Safe to run again: a second run changes nothing.
 */
final class NumberClassroomsCommand extends Command
{
    protected $signature = 'sth:kelas:nomor
        {--terapkan : Ubah kelasnya (tanpa ini hanya pratinjau, tidak ada yang diubah)}
        {--force : Izinkan --terapkan di luar APP_ENV=local}';

    protected $description = 'Jadikan kelas X-1…X-12, XI-1…XI-12, XII-1…XII-12 dan hapus kelas lain yang kosong. Tanpa --terapkan hanya pratinjau.';

    public function handle(NumberClassrooms $numbering): int
    {
        $apply = (bool) $this->option('terapkan');

        if ($apply && ! app()->environment('local') && ! $this->option('force')) {
            $this->error('Di luar APP_ENV=local, --terapkan hanya berjalan bersama --force. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        $result = $apply ? $numbering->handle() : $numbering->preview();
        $changes = count($result['renamed']) + count($result['merged']) + count($result['created']) + count($result['deleted']);

        if ($changes === 0) {
            $this->info('Kelas sudah X-1…X-12, XI-1…XI-12, XII-1…XII-12. Tidak ada yang diubah.');
        } else {
            $this->info($apply ? 'Kelas sudah diubah:' : 'Pratinjau, tidak ada yang diubah:');
            $this->section('Ganti nama', $result['renamed']);
            $this->section('Digabung', $result['merged']);
            $this->section('Ditambah', $result['created']);
            $this->section('Dihapus (kosong)', $result['deleted']);
        }

        $this->section('Tidak dihapus karena masih ada siswanya', $result['kept']);

        if (! $apply && $changes > 0) {
            $this->line('Jalankan lagi dengan --terapkan untuk mengubah kelasnya.');
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $lines */
    private function section(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->line($title.' ('.count($lines).'): '.implode(', ', $lines));
    }
}
