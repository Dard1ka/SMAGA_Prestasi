<?php

declare(strict_types=1);

use App\Jobs\PruneExpiredCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function qCacheRow(string $key, int $expiration): void
{
    DB::table('cache')->insert(['key' => $key, 'value' => serialize('x'), 'expiration' => $expiration]);
}

it('deletes expired rows of the database cache and keeps the live ones', function (): void {
    config(['cache.default' => 'database']);

    qCacheRow('publik:v1:overview', now()->subDay()->getTimestamp());
    qCacheRow('publik:v1:ranking', now()->subSecond()->getTimestamp());
    qCacheRow('publik:v2:overview', now()->addHour()->getTimestamp());

    expect((new PruneExpiredCache)->handle())->toBe(2)
        ->and(DB::table('cache')->pluck('key')->all())->toBe(['publik:v2:overview']);
});

it('does nothing when another cache store is in use', function (): void {
    config(['cache.default' => 'array']);

    qCacheRow('old', now()->subDay()->getTimestamp());

    expect((new PruneExpiredCache)->handle())->toBe(0)
        ->and(DB::table('cache')->count())->toBe(1);
});

it('is scheduled', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->description);

    expect($events->contains(fn (string $d): bool => str_contains($d, PruneExpiredCache::class)))->toBeTrue();
});
