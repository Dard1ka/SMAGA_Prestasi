<?php

declare(strict_types=1);

use App\Domain\Archive\SchoolYear;
use Carbon\CarbonImmutable;

it('parses and prints YYYY/YYYY', function () {
    $year = SchoolYear::fromString(' 2026/2027 ');

    expect($year->startYear)->toBe(2026)
        ->and($year->endYear())->toBe(2027)
        ->and((string) $year)->toBe('2026/2027');
});

it('validates the format', function () {
    expect(SchoolYear::isValid('2026/2027'))->toBeTrue()
        ->and(SchoolYear::isValid('2026/2026'))->toBeFalse()
        ->and(SchoolYear::isValid('1999/2000'))->toBeFalse()
        ->and(SchoolYear::isValid('2026/27'))->toBeFalse();
});

it('finds the school year containing a date (starts 1 July)', function (string $date, string $expected) {
    expect(SchoolYear::containing(CarbonImmutable::parse($date))->toString())->toBe($expected);
})->with([
    ['2026-10-01', '2026/2027'],
    ['2026-07-01', '2026/2027'],
    ['2026-06-30', '2025/2026'],
    ['2027-01-15', '2026/2027'],
]);
