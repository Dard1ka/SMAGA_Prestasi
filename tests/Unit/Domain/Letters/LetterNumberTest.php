<?php

declare(strict_types=1);

use App\Domain\Letters\LetterNumber;
use Carbon\CarbonImmutable;

it('formats the siswa and guru numbers', function () {
    $letters = new LetterNumber;

    expect($letters->format(LetterNumber::TEMPLATE_SISWA, 312, '2026-09-08'))->toBe('400.3.8/312/MRD/IX/2026')
        ->and($letters->format(LetterNumber::TEMPLATE_GURU, '1680', CarbonImmutable::parse('2026-09-08')))->toBe('400.3.8.6/1680/2026');
});

it('converts months to roman numerals', function (int $month, string $roman) {
    expect((new LetterNumber)->romanMonth($month))->toBe($roman);
})->with([
    [1, 'I'], [2, 'II'], [3, 'III'], [4, 'IV'], [5, 'V'], [6, 'VI'],
    [7, 'VII'], [8, 'VIII'], [9, 'IX'], [10, 'X'], [11, 'XI'], [12, 'XII'],
]);

it('rejects months outside 1–12', function (int $month) {
    (new LetterNumber)->romanMonth($month);
})->with([0, 13])->throws(InvalidArgumentException::class);

it('parses sequences typed by TU', function (int|string $input, int $expected) {
    expect((new LetterNumber)->parseSequence($input))->toBe($expected);
})->with([
    [312, 312],
    ['312', 312],
    [' 0312 ', 312],
    ['99999', 99999],
    [1, 1],
]);

it('rejects invalid sequences with an Indonesian message', function (int|string $input) {
    (new LetterNumber)->parseSequence($input);
})->with([0, -5, 100000, '', 'abc', '12a', '3.5', '123456', '-1'])
    ->throws(InvalidArgumentException::class, 'Nomor urut harus angka 1–99999.');

it('reports sequence validity without throwing', function () {
    $letters = new LetterNumber;

    expect($letters->isValidSequence('312'))->toBeTrue()
        ->and($letters->isValidSequence(0))->toBeFalse()
        ->and($letters->isValidSequence(null))->toBeFalse()
        ->and($letters->isValidSequence(3.0))->toBeFalse();
});

it('validates templates', function (string $template) {
    (new LetterNumber)->assertValidTemplate($template);
})->with([
    'no seq' => ['400.3.8/MRD/{year}'],
    'seq twice' => ['{seq}/{seq}/{year}'],
    'unknown placeholder' => ['400/{seq}/{bulan}/{year}'],
    'year twice' => ['{year}/{seq}/{year}'],
    'empty' => [''],
])->throws(InvalidArgumentException::class);

it('uses month and year of the issue date', function () {
    expect((new LetterNumber)->format(LetterNumber::TEMPLATE_SISWA, 7, '2027-01-04'))->toBe('400.3.8/7/MRD/I/2027');
});

it('parses full numbers back and builds a validation regex', function () {
    $letters = new LetterNumber;

    expect($letters->parse(LetterNumber::TEMPLATE_SISWA, '400.3.8/312/MRD/IX/2026'))
        ->toBe(['sequence' => 312, 'month' => 9, 'year' => 2026])
        ->and($letters->parse(LetterNumber::TEMPLATE_GURU, '400.3.8.6/1680/2026'))
        ->toBe(['sequence' => 1680, 'month' => null, 'year' => 2026])
        ->and($letters->parse(LetterNumber::TEMPLATE_SISWA, '400.3.8/312/MRD/XIII/2026'))->toBeNull()
        ->and($letters->parse(LetterNumber::TEMPLATE_SISWA, '400.3.8/0/MRD/IX/2026'))->toBeNull()
        ->and($letters->parse(LetterNumber::TEMPLATE_GURU, '400.3.8/1680/2026'))->toBeNull()
        ->and(preg_match($letters->pattern(LetterNumber::TEMPLATE_GURU), '400x3x8x6/1/2026'))->toBe(0);
});
