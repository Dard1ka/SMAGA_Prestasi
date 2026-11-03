<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\ArchiveDueStudentAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily 01:00 Asia/Jakarta (routes/console.php, block W2-D): archives students whose archive_at has arrived
 * (D-13, D-29, SECURITY §3m-8). Idempotent — safe to run again by hand:
 *   php artisan sth:arsip-otomatis            (today)
 *   php artisan sth:arsip-otomatis --date=2030-01-01   (only for checks on a local DB)
 */
final class ArchiveDueStudents extends Command
{
    protected $signature = 'sth:arsip-otomatis {--date= : Hari acuan Y-m-d (default: hari ini, Asia/Jakarta)}';

    protected $description = 'Arsipkan akun siswa yang tanggal arsipnya sudah tiba (idempoten).';

    public function handle(ArchiveDueStudentAccounts $archive): int
    {
        $option = $this->option('date');
        $today = null;

        if (is_string($option) && $option !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $option)) {
                $this->error('Format --date harus Y-m-d.');

                return self::INVALID;
            }

            if (app()->isProduction()) {
                $this->error('--date tidak boleh dipakai di production.');

                return self::INVALID;
            }

            $today = CarbonImmutable::createFromFormat('!Y-m-d', $option, config('app.timezone'));
        }

        $count = $archive->handle($today ?: null);
        $this->info($count === 0 ? 'Tidak ada akun yang perlu diarsipkan.' : "{$count} akun siswa diarsipkan.");

        return self::SUCCESS;
    }
}
