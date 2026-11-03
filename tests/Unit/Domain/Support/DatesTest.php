<?php

declare(strict_types=1);

use App\Domain\Support\Dates;
use App\Domain\Support\Key;
use Carbon\CarbonImmutable;

it('turns strings and date objects into midnight CarbonImmutable dates', function () {
    expect(Dates::toDate('2026-09-24'))->toBeInstanceOf(CarbonImmutable::class)
        ->and(Dates::toDate('2026-09-24')->toDateTimeString())->toBe('2026-09-24 00:00:00')
        ->and(Dates::toDate(new DateTime('2026-09-24 18:30', new DateTimeZone('Asia/Jakarta')))->toDateTimeString())
        ->toBe('2026-09-24 00:00:00')
        ->and(Dates::toDate('2007-03-02 00:00:00')->toDateTimeString())->toBe('2007-03-02 00:00:00')
        ->and(Dates::toDate('2026-09-24 18:30:00')->toDateTimeString())->toBe('2026-09-24 00:00:00');
});

it('rejects loose date strings', function (string $value) {
    Dates::toDate($value);
})->with(['24/09/2026', '2026-9-24', '2026-13-01', '2026-02-29', 'besok', '2026-09-24T10:00:00', '2026-09-24 25:00:00'])
    ->throws(InvalidArgumentException::class, 'Tanggal harus berformat YYYY-MM-DD.');

it('counts calendar days in both directions', function () {
    expect(Dates::daysBetween('2026-09-24', '2026-11-08'))->toBe(45)
        ->and(Dates::daysBetween('2026-11-08', '2026-09-24'))->toBe(-45)
        ->and(Dates::daysBetween('2028-02-28', '2028-03-01'))->toBe(2);
});

it('reads keys from enums or raw values', function () {
    expect(Key::of('kota'))->toBe('kota')
        ->and(Key::of(TestKeyEnum::A))->toBe('A')
        ->and(Key::of(12))->toBe('12');
});

enum TestKeyEnum: string
{
    case A = 'A';
}
