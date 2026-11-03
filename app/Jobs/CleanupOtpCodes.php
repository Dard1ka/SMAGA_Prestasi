<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OtpCode;
use App\Support\Audit\SecurityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes OTP rows (used, expired or abandoned) created more than 24 hours ago (backlog #16, SECURITY §3b).
 * Codes live 10 minutes, so nothing needs them later; the hashes, target e-mails and attempt counters should
 * not pile up. Scheduled hourly (routes/console.php, B2 block). Deletes in small batches by id (DML only, no
 * TRUNCATE: the production DB user cannot). Idempotent.
 */
final class CleanupOtpCodes implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RETENTION_HOURS = 24;

    public const BATCH = 500;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): int
    {
        $cutoff = now()->subHours(self::RETENTION_HOURS);
        $deleted = 0;

        do {
            $ids = OtpCode::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += OtpCode::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === self::BATCH);

        if ($deleted > 0) {
            SecurityLog::info('security.otp_cleanup', ['deleted' => $deleted, 'older_than_hours' => self::RETENTION_HOURS]);
        }

        return $deleted;
    }
}
