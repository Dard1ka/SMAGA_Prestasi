<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;
use Carbon\CarbonImmutable;

// Only the fictional example from D-24 is used for the old pattern here (2 March 2007), and the example of the lead's
// request for D-173 (1 Feb 2010). Expected values are built in the test; a failure never prints a configured value.

it('returns the configured value of D-138 (kept for K7 and the move command)', function (): void {
    expect((new InitialPassword('12345'))->make())->toBe('12345');
});

it('refuses an empty value', function (): void {
    new InitialPassword('  ');
})->throws(InvalidArgumentException::class);

it('builds SMAGA_ + the birth date as ddmmyyyy (D-173)', function (): void {
    expect(InitialPassword::PREFIX)->toBe('SMAGA_')
        ->and(InitialPassword::forBirthDate('2010-02-01'))->toBe('SMAGA_01022010')
        ->and(InitialPassword::forBirthDate('2009-12-31'))->toBe('SMAGA_31122009')
        // The raw DB value with a time part reads as its date.
        ->and(InitialPassword::forBirthDate('2010-02-01 00:00:00'))->toBe('SMAGA_01022010');
});

it('keeps the calendar day of the value whatever its timezone (D-173)', function (DateTimeInterface $birthDate): void {
    expect(InitialPassword::forBirthDate($birthDate))->toBe('SMAGA_01022010');
})->with([
    'midnight Asia/Jakarta (the parsed import date)' => [new DateTimeImmutable('2010-02-01 00:00:00', new DateTimeZone('Asia/Jakarta'))],
    'midnight UTC' => [new DateTimeImmutable('2010-02-01 00:00:00', new DateTimeZone('UTC'))],
    'late evening UTC (already 2 Feb in Jakarta)' => [new DateTimeImmutable('2010-02-01 23:30:00', new DateTimeZone('UTC'))],
    'early morning Asia/Jakarta (still 31 Jan in UTC)' => [new DateTimeImmutable('2010-02-01 03:00:00', new DateTimeZone('Asia/Jakarta'))],
    'a mutable DateTime' => [new DateTime('2010-02-01 12:00:00', new DateTimeZone('Asia/Jakarta'))],
    'CarbonImmutable at the start of the day (the students.birth_date cast)' => [CarbonImmutable::create(2010, 2, 1, 0, 0, 0, 'Asia/Jakarta')],
]);

it('refuses a birth date string that is not Y-m-d', function (string $value): void {
    InitialPassword::forBirthDate($value);
})->throws(InvalidArgumentException::class)->with(['01-02-2010', '2010-2-1', '', '2010-02-31']);

it('gives two students born on one day the same password (accepted by the lead, D-173)', function (): void {
    expect(InitialPassword::forBirthDate('2010-02-01'))->toBe(InitialPassword::forBirthDate(new DateTimeImmutable('2010-02-01')));
});

it('recognises the configured value, the D-173 pattern and the old D-24 pattern for K7, and nothing else', function (): void {
    $initial = new InitialPassword('12345');

    expect($initial->matches('12345'))->toBeTrue()
        ->and($initial->matches('12345', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('sm4g4_020307', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('sm4g4_020307'))->toBeFalse()
        ->and($initial->matches('sm4g4_02032007', '2007-03-02'))->toBeFalse()
        ->and($initial->matches('SM4G4_020307', '2007-03-02'))->toBeFalse()
        ->and($initial->matches(' 12345'))->toBeFalse()
        // D-173: the birth-date password, exactly as built (case-sensitive like the password itself).
        ->and($initial->matches('SMAGA_02032007', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('SMAGA_02032007', new DateTimeImmutable('2007-03-02 00:00:00', new DateTimeZone('Asia/Jakarta'))))->toBeTrue()
        ->and($initial->matches('SMAGA_02032007'))->toBeFalse()
        ->and($initial->matches('smaga_02032007', '2007-03-02'))->toBeFalse()
        ->and($initial->matches('SMAGA_020307', '2007-03-02'))->toBeFalse()
        ->and($initial->matches('SMAGA_03032007', '2007-03-02'))->toBeFalse();
});

it('rejects a malformed birth date', function (): void {
    (new InitialPassword('12345'))->matches('x', '02-03-2007');
})->throws(InvalidArgumentException::class);
