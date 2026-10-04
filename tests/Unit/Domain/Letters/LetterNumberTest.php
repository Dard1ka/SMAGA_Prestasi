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

// M1-A (lead, 2026-10-04): Tata Usaha types the complete number ------------------------------------

it('accepts a complete number typed by TU and tidies its spaces', function (string $input, string $expected) {
    $letters = new LetterNumber;

    expect($letters->numberError($input))->toBeNull()
        ->and($letters->isValidNumber($input))->toBeTrue()
        ->and($letters->parseNumber($input))->toBe($expected);
})->with([
    'siswa format' => ['400.3.8/313/MRD/X/2026', '400.3.8/313/MRD/X/2026'],
    'guru format' => ['400.3.8.6/1681/2026', '400.3.8.6/1681/2026'],
    'any other format' => ['B-12/SMAN3/2026', 'B-12/SMAN3/2026'],
    'outer and repeated spaces' => ["  400.3.8 /  313\t/ MRD ", '400.3.8 / 313 / MRD'],
    'shortest' => ['1/A', '1/A'],
    'longest' => [str_repeat('1', 64), str_repeat('1', 64)],
]);

it('rejects a number that cannot be a letter number, with an Indonesian message', function (string $input, string $message) {
    $letters = new LetterNumber;

    expect($letters->numberError($input))->toBe($message)
        ->and($letters->isValidNumber($input))->toBeFalse()
        ->and(fn () => $letters->parseNumber($input))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty' => ['', LetterNumber::MSG_NUMBER_REQUIRED],
    'only spaces' => ['   ', LetterNumber::MSG_NUMBER_REQUIRED],
    'too short' => ['12', LetterNumber::MSG_NUMBER_LENGTH],
    'too long' => [str_repeat('1', 65), LetterNumber::MSG_NUMBER_LENGTH],
    'no digit' => ['MRD/X/abc', LetterNumber::MSG_NUMBER_DIGIT],
    'hash' => ['400.3.8/313#1', LetterNumber::MSG_NUMBER_CHARACTERS],
    'angle brackets' => ['<b>313</b>', LetterNumber::MSG_NUMBER_CHARACTERS],
    'line break inside' => ["400.3.8\n313;x", LetterNumber::MSG_NUMBER_CHARACTERS],
    'non-latin letter' => ['400.3.8/313/MÄD', LetterNumber::MSG_NUMBER_CHARACTERS],
]);

it('says only strings can be a number', function () {
    expect((new LetterNumber)->isValidNumber(313))->toBeFalse()
        ->and((new LetterNumber)->isValidNumber(null))->toBeFalse();
});

it('compares numbers without case and spaces', function () {
    $letters = new LetterNumber;

    expect($letters->key('400.3.8/313/MRD/X/2026'))->toBe('400.3.8/313/mrd/x/2026')
        ->and($letters->key(' 400.3.8 / 313 / mrd / x / 2026 '))->toBe('400.3.8/313/mrd/x/2026')
        ->and($letters->key('400.3.8/314/MRD/X/2026'))->not->toBe($letters->key('400.3.8/313/MRD/X/2026'));
});

it('builds the example under the number field from the settings format', function () {
    $letters = new LetterNumber;

    expect($letters->example(LetterNumber::TEMPLATE_SISWA, '2026-10-04'))->toBe('400.3.8/1/MRD/X/2026')
        ->and($letters->example(LetterNumber::TEMPLATE_SISWA, '2026-10-04', null, 313))->toBe('400.3.8/313/MRD/X/2026')
        // the last used number fits the format: the example continues from it
        ->and($letters->example(LetterNumber::TEMPLATE_SISWA, '2026-11-02', '400.3.8/312/MRD/X/2026', 1))->toBe('400.3.8/313/MRD/XI/2026')
        ->and($letters->example(LetterNumber::TEMPLATE_GURU, '2026-10-04', '400.3.8.6/1680/2026'))->toBe('400.3.8.6/1681/2026')
        // a last number in another shape is ignored
        ->and($letters->example(LetterNumber::TEMPLATE_GURU, '2026-10-04', 'B-12/SMAN3/2026', 7))->toBe('400.3.8.6/7/2026')
        // a broken format gives no example instead of an error
        ->and($letters->example('tanpa kode', '2026-10-04'))->toBeNull();
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
