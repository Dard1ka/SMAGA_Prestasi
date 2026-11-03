<?php

declare(strict_types=1);

use App\Domain\Support\Decimal;

it('normalizes numeric input', function (string|int|float $input, string $expected) {
    expect(Decimal::normalize($input))->toBe($expected);
})->with([
    [80, '80'],
    [0.85, '0.85'],
    [33.6, '33.6'],
    [0.0, '0'],
    [' 1.50 ', '1.50'],
    ['-0', '0'],
]);

it('rejects non-plain numbers', function (string|float $input) {
    Decimal::normalize($input);
})->with(['1e3', '1,5', 'abc', '', '.5', INF])->throws(InvalidArgumentException::class);

it('rounds half away from zero', function (string $input, int $scale, string $expected) {
    expect(Decimal::roundHalfUp($input, $scale))->toBe($expected);
})->with([
    ['2.345', 2, '2.35'],
    ['2.344', 2, '2.34'],
    ['-2.345', 2, '-2.35'],
    ['-0.001', 2, '0.00'],
    ['7', 2, '7.00'],
    ['2.5', 0, '3'],
]);

it('tells callers which values it can normalise, so guards stop guessing with is_numeric', function (string $value, bool $valid): void {
    // is_numeric() accepts forms this class rejects. A caller that guards with is_numeric and then calls
    // normalize() crashes on exactly these, which is what broke the live example on Pengaturan > Bobot.
    expect(Decimal::isValid($value))->toBe($valid);
})->with([
    ['0.85', true],
    ['80', true],
    ['-1.5', true],
    ['0.', false],
    ['.5', false],
    ['+5', false],
    ['1e5', false],
    ['abc', false],
    ['', false],
]);
