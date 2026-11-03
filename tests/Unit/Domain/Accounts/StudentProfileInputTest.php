<?php

declare(strict_types=1);

use App\Domain\Accounts\PhoneNumber;
use App\Domain\Accounts\StudentProfileInput;
use Carbon\CarbonImmutable;

// D-152: the two profile fields of a student (activation "Data diri" and Profil saya). Fictional values only.

function ps1Today(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-15');
}

it('accepts a real birth date in the window and a valid No HP', function (): void {
    expect(StudentProfileInput::errors('2010-03-02', '081234567890', ps1Today()))->toBe([])
        ->and(StudentProfileInput::errors('2010-03-02', '0812 3456 7890', ps1Today()))->toBe([])
        ->and(StudentProfileInput::errors('2010-03-02', '6281234567890', ps1Today()))->toBe([]);
});

it('names every problem of both fields at once', function (): void {
    expect(StudentProfileInput::errors('', '', ps1Today()))->toBe([
        'birth_date' => StudentProfileInput::BIRTH_DATE_EMPTY,
        'phone' => StudentProfileInput::PHONE_EMPTY,
    ]);
});

it('refuses a birth date that is not a real Y-m-d day', function (string $value): void {
    expect(StudentProfileInput::errors($value, '081234567890', ps1Today()))
        ->toBe(['birth_date' => StudentProfileInput::BIRTH_DATE_INVALID]);
})->with(['02-03-2010', '2010-02-30', '2010-13-01', '2010/03/02', 'besok', '2010-3-2']);

it('refuses a birth date outside the window of 10 to 30 years before today', function (string $value): void {
    expect(StudentProfileInput::errors($value, '081234567890', ps1Today()))
        ->toBe(['birth_date' => StudentProfileInput::BIRTH_DATE_RANGE]);
})->with(['2091-03-02', '2026-10-15', '2016-10-16', '1996-10-14', '1909-03-02']);

it('keeps both edges of the window', function (): void {
    expect(StudentProfileInput::errors('2016-10-15', '081234567890', ps1Today()))->toBe([])
        ->and(StudentProfileInput::errors('1996-10-15', '081234567890', ps1Today()))->toBe([]);
});

it('uses the import rule for the No HP', function (string $value): void {
    expect(StudentProfileInput::errors('2010-03-02', $value, ps1Today()))
        ->toBe(['phone' => PhoneNumber::INVALID]);
})->with(['12345', '0212345678', 'nomor saya', '08123']);

it('never repeats a typed value in an error text', function (): void {
    $errors = StudentProfileInput::errors('2091-03-02', '0212345678', ps1Today());

    expect(implode(' ', $errors))->not->toContain('2091')->not->toContain('0212345678');
});
