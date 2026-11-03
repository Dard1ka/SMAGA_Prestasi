<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Accounts\ApplyBirthDateInitialPasswords;
use Illuminate\Console\Command;

/**
 * D-173 (smaga_server): moves the students who have not activated yet and are STILL on the one configured initial
 * password of D-138 (`STH_INITIAL_STUDENT_PASSWORD`) to the birth-date password "SMAGA_" + ddmmyyyy, the password a new
 * import gives since D-173. Rules and safety: App\Actions\Accounts\ApplyBirthDateInitialPasswords.
 *
 *   php artisan sth:siswa:kata-sandi-awal                 (dry run: counts per class, writes nothing)
 *   php artisan sth:siswa:kata-sandi-awal --terapkan      (writes; APP_ENV=local, e.g. the school's server today)
 *   php artisan sth:siswa:kata-sandi-awal --terapkan --force   (writes in any other APP_ENV)
 *
 * Prints counts only: never a name, a NISN or a password; with more than one chunk of accounts also a progress count
 * after every chunk. Safe to run again: a second run finds 0, and a run stopped part-way keeps (and has audited) what
 * it wrote. Run it while STH_INITIAL_STUDENT_PASSWORD still holds the value the old students received (the command
 * compares their hash with it).
 */
final class ApplyInitialPasswordRule extends Command
{
    protected $signature = 'sth:siswa:kata-sandi-awal
        {--terapkan : Ganti kata sandinya (tanpa ini hanya pratinjau, tidak ada yang diubah)}
        {--force : Izinkan --terapkan di luar APP_ENV=local}';

    protected $description = 'Ganti kata sandi awal lama siswa yang belum aktivasi ke pola SMAGA_ + tanggal lahir (ddmmyyyy). Tanpa --terapkan hanya pratinjau.';

    public function handle(ApplyBirthDateInitialPasswords $rule): int
    {
        $apply = (bool) $this->option('terapkan');

        if ($apply && ! app()->environment('local') && ! $this->option('force')) {
            $this->error('Di luar APP_ENV=local, --terapkan hanya berjalan bersama --force. Tidak ada yang diubah.');

            return self::FAILURE;
        }

        // bcrypt makes every account take a moment (one check in the preview, a check and a new hash with --terapkan), so
        // a long run says up front how many accounts it reads and then prints a count after every chunk: it never looks
        // hung. Counts only.
        $total = ApplyBirthDateInitialPasswords::candidates()->count();
        $progress = null;

        if ($total > 0) {
            $this->line("Memeriksa {$total} akun siswa belum aktivasi. Ratusan akun bisa butuh beberapa menit.");
        }

        if ($total > ApplyBirthDateInitialPasswords::CHUNK) {
            $progress = function (int $checked) use ($total): void {
                $this->line("  {$checked}/{$total} akun diperiksa");
            };
        }

        $result = $apply ? $rule->handle($progress) : $rule->preview($progress);

        if ($result['move'] === 0) {
            $this->info($apply
                ? 'Tidak ada siswa yang perlu diganti. Tidak ada yang diubah.'
                : 'Tidak ada siswa belum aktivasi yang masih memakai kata sandi awal lama.');
        } else {
            $this->info($apply
                ? "Kata sandi awal {$result['move']} siswa diganti ke pola SMAGA_ + tanggal lahir (ddmmyyyy)."
                : "Pratinjau, tidak ada yang diubah: {$result['move']} siswa belum aktivasi masih memakai kata sandi awal lama.");

            foreach ($result['by_class'] as $class => $count) {
                $this->line("  {$class}: {$count} siswa");
            }
        }

        $left = array_filter([
            $result['no_birth_date'] > 0 ? "{$result['no_birth_date']} tanpa tanggal lahir" : null,
            $result['other_password'] > 0 ? "{$result['other_password']} dengan kata sandi lain" : null,
            ($result['changed_meanwhile'] ?? 0) > 0 ? "{$result['changed_meanwhile']} berubah saat perintah berjalan" : null,
        ]);

        if ($left !== []) {
            $this->line('Tidak diubah (siswa belum aktivasi): '.implode(', ', $left).'.');
        }

        if (! $apply && $result['move'] > 0) {
            $this->line('Jalankan lagi dengan --terapkan untuk mengganti kata sandinya.');
        }

        return self::SUCCESS;
    }
}
