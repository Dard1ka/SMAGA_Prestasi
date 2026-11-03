<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use App\Support\Audit\AuditChain;
use App\Support\Audit\SecurityLog;
use Illuminate\Console\Command;

/**
 * `php artisan sth:audit:verify` (SECURITY §3m-9): recomputes the activity_log hash chain (App\Support\Audit\AuditChain)
 * and reports deleted, edited or re-ordered audit rows and a truncated tail. Exit code 1 when the chain is broken.
 * Every run writes the result and the chain head (last seal id + hash) to the `security` log, which goes into the
 * off-site backup, so the newest rows cannot be removed unnoticed either. Scheduled daily (routes/console.php, B2).
 * Read-only: it never repairs or re-seals anything.
 */
final class VerifyAuditChain extends Command
{
    protected $signature = 'sth:audit:verify {--max-problems=20 : Berhenti setelah sekian temuan}';

    protected $description = 'Periksa rantai hash log audit (activity_log): deteksi baris yang dihapus atau diubah.';

    public function handle(AuditChain $chain): int
    {
        $report = $chain->verify(max(1, (int) $this->option('max-problems')));

        $context = [
            'checked' => $report->checked,
            'unsealed' => $report->unsealed,
            'head_seal_id' => $report->headSealId,
            'head_hash' => $report->headHash,
        ];

        if (! $report->ok()) {
            foreach ($report->problems as $problem) {
                $this->error($problem);
            }

            $this->error("Rantai audit RUSAK: {$report->checked} segel diperiksa, ".count($report->problems).' temuan.');
            SecurityLog::critical('security.audit_chain_broken', $context + ['problems' => array_slice($report->problems, 0, 5)]);

            return self::FAILURE;
        }

        if ($report->unsealed > 0) {
            $this->warn("{$report->unsealed} baris audit belum disegel. Jalankan php artisan sth:audit:seal.");
        }

        $this->info("Rantai audit utuh: {$report->checked} segel diperiksa.");
        $this->line('Kepala rantai: segel #'.($report->headSealId ?? '-').' '.($report->headHash ?? '-'));
        SecurityLog::info('security.audit_chain_verified', $context);

        return self::SUCCESS;
    }
}
