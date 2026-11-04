<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Students\DeleteAllStudents;
use App\Enums\UserStatus;
use Illuminate\Console\Command;

/**
 * smaga_server, the lead's request of 4 Nov 2026: deletes ALL student data (the trial data before the real students are
 * imported) and keeps everything else. What goes and what stays: App\Actions\Students\DeleteAllStudents.
 *
 *   php artisan sth:siswa:hapus-semua                    (dry run: counts per kind, writes nothing)
 *   php artisan sth:siswa:hapus-semua --terapkan         (deletes; APP_ENV=local, e.g. the school's server today)
 *   php artisan sth:siswa:hapus-semua --terapkan --force (deletes in any other APP_ENV)
 *
 * A permanent delete: make a database backup first. Prints counts only, never a name, a NISN or an email. Safe to run
 * again: a second run finds nothing to delete.
 */
final class DeleteAllStudentData extends Command
{
    protected $signature = 'sth:siswa:hapus-semua
        {--terapkan : Hapus datanya (tanpa ini hanya pratinjau, tidak ada yang dihapus)}
        {--force : Izinkan --terapkan di luar APP_ENV=local}';

    protected $description = 'Hapus permanen SEMUA data siswa (akun siswa, pendaftaran, surat, laporan, prestasi, talenta, file); staf, kelas, lomba dan pengaturan tetap. Tanpa --terapkan hanya pratinjau.';

    public function handle(DeleteAllStudents $action): int
    {
        $apply = (bool) $this->option('terapkan');

        if ($apply && ! app()->environment('local') && ! $this->option('force')) {
            $this->error('Di luar APP_ENV=local, --terapkan hanya berjalan bersama --force. Tidak ada yang dihapus.');

            return self::FAILURE;
        }

        $result = $apply ? $action->handle() : $action->preview();

        if ($result['nothing']) {
            $this->info('Tidak ada data siswa untuk dihapus. Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        if ($apply && $result['refusal'] !== null) {
            $this->error('Ditolak: '.$result['refusal'].' Tidak ada yang dihapus.');

            return self::FAILURE;
        }

        $this->info($apply
            ? 'Semua data siswa dihapus permanen:'
            : 'Pratinjau, tidak ada yang dihapus. Yang akan dihapus permanen:');

        foreach ($this->lines($result['counts'], $result['by_status']) as $line) {
            $this->line('  '.$line);
        }

        $kept = $result['kept'];
        $this->line("Tetap ada: {$kept['staf']} akun staf dan guru, {$kept['kelas']} kelas, {$kept['lomba']} lomba, "
            ."{$kept['acara_apresiasi']} acara apresiasi, pengaturan, tanda tangan, bobot poin, jenis talenta dan log audit.");

        if ($apply) {
            if (($result['disk_missing'] ?? 0) > 0) {
                $this->line("Catatan: {$result['disk_missing']} file sudah tidak ada di disk.");
            }

            if (($result['disk_failed'] ?? 0) > 0) {
                $this->warn("{$result['disk_failed']} file tidak bisa dihapus dari disk (datanya sudah terhapus dari database).");
            }

            return self::SUCCESS;
        }

        if ($result['refusal'] !== null) {
            $this->warn('--terapkan akan ditolak: '.$result['refusal']);

            return self::SUCCESS;
        }

        $this->line('Penghapusan ini permanen: buat cadangan database dulu. Jalankan lagi dengan --terapkan untuk menghapus.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $byStatus
     * @return list<string>
     */
    private function lines(array $counts, array $byStatus): array
    {
        $statuses = [];

        foreach ($byStatus as $status => $total) {
            $label = UserStatus::tryFrom($status)?->label() ?? $status;
            $statuses[] = $total.' '.mb_strtolower($label);
        }

        return [
            "siswa: {$counts['siswa']} akun".($statuses === [] ? '' : ' ('.implode(', ', $statuses).')'),
            "pendaftaran: {$counts['pendaftaran']} (dengan {$counts['anggota']} baris anggota)",
            "surat tugas: {$counts['surat']}",
            "laporan hasil: {$counts['laporan']}",
            "prestasi: {$counts['prestasi']}",
            "publikasi: {$counts['publikasi']}",
            "apresiasi (prestasi di acara; acaranya tetap): {$counts['apresiasi']}",
            "pilihan talenta: {$counts['pilihan_talenta']}",
            "profil talenta: {$counts['profil_talenta']}",
            "file: {$counts['file']}",
            "lomba yang dipindah ke Admin: {$counts['lomba_ke_admin']} (tetap di katalog, pengusulnya menjadi Admin)",
        ];
    }
}
