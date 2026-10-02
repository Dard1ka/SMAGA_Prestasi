<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows of the database cache store. Laravel only removes an expired row when that exact key is
 * read or written again, and the public pages use versioned keys (`publik:vN:…`, see
 * App\Livewire\Publik\Data\PublicAchievements::flush()) that are never read again after a flush, so dead rows
 * (plus old rate-limiter counters) would pile up. Scheduled daily (routes/console.php). DML only, in batches.
 */
final class PruneExpiredCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH = 1000;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(): int
    {
        if (config('cache.default') !== 'database') {
            return 0;
        }

        $table = (string) config('cache.stores.database.table', 'cache');
        $connection = config('cache.stores.database.connection');
        $deleted = 0;

        do {
            $count = DB::connection($connection)->table($table)
                ->where('expiration', '<', now()->getTimestamp())
                ->limit(self::BATCH)
                ->delete();

            $deleted += $count;
        } while ($count === self::BATCH);

        return $deleted;
    }
}
