<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/*
 * QA10: the scheduled commands. `--date` (a reference day for checks on a local database) was accepted in production
 * by the timeline and the reminder command, while sth:arsip-otomatis refuses it: a test run with a future date would
 * move live registrations and mail every ketua. And every scheduled job kept Laravel's default 24 h overlap lock, so a
 * scheduler killed during a run skipped that job for a whole day.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

/** Runs $test with APP_ENV=production and puts the test environment back afterwards. */
function qa10NoticeInProduction(Closure $test): void
{
    $env = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $test();
    } finally {
        app()->detectEnvironment(fn (): string => $env);
    }
}

it('refuses --date in production like sth:arsip-otomatis (QA10)', function (string $command): void {
    qa10NoticeInProduction(function () use ($command): void {
        expect(app()->isProduction())->toBeTrue();

        $this->artisan($command, ['--date' => '2027-01-15'])
            ->expectsOutput('--date tidak boleh dipakai di production.')
            ->assertExitCode(2);
    });

    Notification::assertNothingSent();
})->with(['sth:registrations:advance', 'sth:reports:remind']);

it('still runs without --date in production (QA10 guard)', function (string $command): void {
    qa10NoticeInProduction(fn () => $this->artisan($command)->assertSuccessful());
})->with(['sth:registrations:advance', 'sth:reports:remind']);

it('keeps the overlap lock of every scheduled job to one hour (QA10)', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e): bool => $e->withoutOverlapping);

    expect($events)->not->toBeEmpty();
    $events->each(fn (Event $e) => expect($e->expiresAt)->toBe(60, (string) ($e->description ?: $e->command)));
});
