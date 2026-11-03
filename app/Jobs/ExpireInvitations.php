<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Registrations\ExpirePendingInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Scheduled every 5 minutes (routes/console.php, B3 block): declines the team invitations that were
 * still open when the lomba's registration closed, one Action call (own transaction + lock) per
 * registration. Idempotent.
 */
final class ExpireInvitations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function handle(ExpirePendingInvitations $expire): void
    {
        $now = CarbonImmutable::now();

        foreach (ExpirePendingInvitations::due($now)->limit(500)->pluck('id') as $id) {
            $expire->handle((string) $id, $now);
        }
    }
}
