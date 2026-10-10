<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Publications\RemindDuePublication;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scheduled every 5 minutes (routes/console.php, B3 block): reminds Humas once about every `terjadwal`
 * publication whose scheduled_at has arrived, one Action call (own transaction + lock) per row. Idempotent.
 * It publishes nothing and tells no student (D-122): only "Tandai dipublikasikan" does.
 */
final class RemindDuePublications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function handle(RemindDuePublication $remind): void
    {
        $now = CarbonImmutable::now();

        foreach (RemindDuePublication::due($now)->limit(500)->pluck('id') as $id) {
            $remind->handle((string) $id, $now);
        }
    }
}
