<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use App\Support\Audit\AuditChain;
use App\Support\Audit\SecurityLog;
use Illuminate\Console\Command;

/**
 * `php artisan sth:audit:seal`: seals committed activity_log rows that have no seal yet (oldest first). Normally every
 * row is sealed right after its transaction commits (App\Support\Audit\AuditChain::register()); this is the safety net
 * for a lost after-commit seal and for rows written before the chain existed. Idempotent, scheduled (B2 block).
 */
final class SealAuditLog extends Command
{
    protected $signature = 'sth:audit:seal {--limit=5000 : Maksimal baris per jalan}';

    protected $description = 'Segel baris log audit yang belum masuk rantai hash.';

    public function handle(AuditChain $chain): int
    {
        $sealed = $chain->sealPending(max(1, (int) $this->option('limit')));

        if ($sealed > 0) {
            SecurityLog::notice('security.audit_rows_sealed_late', ['count' => $sealed]);
        }

        $this->info($sealed === 0 ? 'Semua baris audit sudah disegel.' : "{$sealed} baris audit disegel.");

        return self::SUCCESS;
    }
}
