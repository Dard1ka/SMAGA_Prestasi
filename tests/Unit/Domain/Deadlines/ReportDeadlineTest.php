<?php

declare(strict_types=1);

use App\Domain\Deadlines\ReportDeadline;
use Carbon\CarbonImmutable;

it('defaults to 45 days after the final date (D-25)', function () {
    $deadline = new ReportDeadline;

    expect($deadline->days())->toBe(45)
        ->and($deadline->dueDate('2026-09-24')->format('Y-m-d'))->toBe('2026-11-08');
});

it('uses the configured number of days', function () {
    expect((new ReportDeadline(30))->dueDate('2026-09-24')->format('Y-m-d'))->toBe('2026-10-24');
});

it('counts days left and flags overdue reports', function (string $today, int $left, bool $overdue) {
    $deadline = new ReportDeadline;

    expect($deadline->daysLeft('2026-09-24', $today))->toBe($left)
        ->and($deadline->isOverdue('2026-09-24', $today))->toBe($overdue);
})->with([
    'day after final' => ['2026-09-25', 44, false],
    'today (spec data)' => ['2026-10-01', 38, false],
    'due date itself' => ['2026-11-08', 0, false],
    'one day late' => ['2026-11-09', -1, true],
]);

it('ignores the time of day and keeps the timezone of the given date', function () {
    $finalOn = CarbonImmutable::parse('2026-09-24 23:59:00', 'Asia/Jakarta');
    $today = CarbonImmutable::parse('2026-11-08 23:30:00', 'Asia/Jakarta');
    $deadline = new ReportDeadline;

    expect($deadline->dueDate($finalOn)->toDateTimeString())->toBe('2026-11-08 00:00:00')
        ->and($deadline->dueDate($finalOn)->timezoneName)->toBe('Asia/Jakarta')
        ->and($deadline->isOverdue($finalOn, $today))->toBeFalse();
});

it('crosses year boundaries', function () {
    expect((new ReportDeadline)->dueDate('2026-12-20')->format('Y-m-d'))->toBe('2027-02-03');
});

it('rejects invalid settings and dates', function (Closure $call) {
    $call();
})->with([
    'zero days' => [fn () => new ReportDeadline(0)],
    'too many days' => [fn () => new ReportDeadline(366)],
    'bad date' => [fn () => (new ReportDeadline)->dueDate('24-09-2026')],
    'impossible date' => [fn () => (new ReportDeadline)->dueDate('2026-02-30')],
])->throws(InvalidArgumentException::class);
