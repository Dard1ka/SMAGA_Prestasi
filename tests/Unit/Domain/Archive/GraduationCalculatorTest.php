<?php

declare(strict_types=1);

use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;

it('computes grade-12 start and archive date', function (int $level, string $year, string $grade12, string $archive) {
    $dates = (new GraduationCalculator)->calculate($level, $year);

    expect($dates->grade12StartDate->format('Y-m-d'))->toBe($grade12)
        ->and($dates->archiveAt->format('Y-m-d'))->toBe($archive);
})->with([
    'X in 2026/2027' => [10, '2026/2027', '2028-07-01', '2030-01-01'],
    'XI in 2025/2026' => [11, '2025/2026', '2026-07-01', '2028-01-01'],
    'XII in 2026/2027' => [12, '2026/2027', '2026-07-01', '2028-01-01'],
    'Nadia: X in 2025/2026' => [10, '2025/2026', '2027-07-01', '2029-01-01'],
]);

it('has separate helpers for each date', function () {
    $calculator = new GraduationCalculator;

    expect($calculator->grade12StartDate(11, new SchoolYear(2026))->format('Y-m-d'))->toBe('2027-07-01')
        ->and($calculator->archiveAt(11, '2026/2027')->format('Y-m-d'))->toBe('2029-01-01')
        ->and($calculator->archiveAtFrom('2028-07-01')->format('Y-m-d'))->toBe('2030-01-01');
});

it('uses the configured archive months', function () {
    $calculator = new GraduationCalculator(12);

    expect($calculator->archiveMonths())->toBe(12)
        ->and($calculator->archiveAt(10, '2026/2027')->format('Y-m-d'))->toBe('2029-07-01');
});

it('is due on and after the archive date', function (string $today, bool $due) {
    expect((new GraduationCalculator)->isDue('2030-01-01', $today))->toBe($due);
})->with([
    ['2029-12-31', false],
    ['2030-01-01', true],
    ['2030-06-15', true],
]);

it('rejects invalid classroom levels', function (int $level) {
    (new GraduationCalculator)->calculate($level, '2026/2027');
})->with([9, 13, 0])->throws(InvalidArgumentException::class, 'Tingkat kelas harus 10, 11, atau 12.');

it('rejects invalid school years', function (string $year) {
    (new GraduationCalculator)->calculate(10, $year);
})->with(['2026-2027', '2026/2028', '26/27', '2026', '2027/2026', ''])
    ->throws(InvalidArgumentException::class, 'Tahun ajaran harus berformat YYYY/YYYY, misalnya 2026/2027.');

it('rejects invalid archive month settings', function (int $months) {
    new GraduationCalculator($months);
})->with([0, 121])->throws(InvalidArgumentException::class);
