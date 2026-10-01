<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Publications\AutoPublishPublication;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scheduled every 5 minutes (routes/console.php, B3 block): publishes every `terjadwal` publication
 * whose scheduled_at has arrived, one Action call (own transaction + lock) per row. Idempotent.
 */
final class AutoPublishPublications implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function handle(AutoPublishPublication $publish): void
    {
        $now = CarbonImmutable::now();

        foreach (AutoPublishPublication::due($now)->limit(500)->pluck('id') as $id) {
            $publish->handle((string) $id, $now);
        }
    }
}
