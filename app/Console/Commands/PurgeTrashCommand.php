<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Trash\PurgeTrash;
use Illuminate\Console\Command;

/**
 * Daily 02:00 Asia/Jakarta (routes/console.php): deletes for good the classes and students whose 7 days in the trash of
 * Admin › Kelas are over (App\Actions\Trash\PurgeTrash; the lead's decision of 5 Nov 2026, smaga_server only).
 * Idempotent, safe to run again by hand: `php artisan sth:sampah:hapus-permanen`. Prints counts only, never a name.
 * Exit 1 when an entry could not be purged (a group PurgeStudents refused: its sentence is printed and logged; it stays
 * in the trash and is tried again on the next run).
 */
final class PurgeTrashCommand extends Command
{
    protected $signature = 'sth:sampah:hapus-permanen';

    protected $description = 'Hapus permanen kelas dan siswa yang sudah lewat 7 hari di Sampah (idempoten).';

    public function handle(PurgeTrash $purge): int
    {
        $result = $purge->handle();

        foreach ($result['refusals'] as $refusal) {
            $this->warn('Ditolak: '.$refusal);
        }

        if ($result['entries'] === 0 && $result['kelas_tertunda'] === 0 && $result['siswa_tertunda'] === 0) {
            $this->info('Tidak ada data di Sampah yang waktunya dihapus permanen.');

            return self::SUCCESS;
        }

        if ($result['entries'] > 0) {
            $this->info("Dihapus permanen: {$result['kelas']} kelas, {$result['siswa']} siswa.");

            foreach ($result['data'] as $kind => $count) {
                $this->line('  '.str_replace('_', ' ', (string) $kind).': '.$count);
            }
        }

        if ($result['kelas_tertunda'] > 0 || $result['siswa_tertunda'] > 0) {
            $this->warn("Belum bisa dihapus: {$result['kelas_tertunda']} kelas, {$result['siswa_tertunda']} siswa. Dicoba lagi pada jadwal berikutnya.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
