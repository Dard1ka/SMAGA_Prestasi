<?php

declare(strict_types=1);

use App\Domain\Accounts\PasswordPolicy;

// Fictional student from DESIGN-SPEC-v2 §5; birth date is the D-24 example.
const PP_BIRTH = '2007-03-02';
const PP_NIS = '225226201';
const PP_NISN = '0098765432';
const PP_NAME = 'Nadia Putri Anindya';

function policyErrors(string $password): array
{
    return (new PasswordPolicy)->validate($password, PP_BIRTH, PP_NIS, PP_NISN, PP_NAME);
}

it('accepts a strong password', function (string $password) {
    expect(policyErrors($password))->toBe([])
        ->and((new PasswordPolicy)->passes($password, PP_BIRTH, PP_NIS, PP_NISN, PP_NAME))->toBeTrue();
})->with(['kopi susu 42 pagi', 'Semangat2026!', 'robot7-lompat']);

it('enforces length and letters plus digits', function () {
    expect(policyErrors('ab12'))->toBe(['Minimal 8 karakter.'])
        ->and(policyErrors('abcdefghij'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS])
        ->and(policyErrors('1234567890'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS])
        ->and(policyErrors(str_repeat('a1', 33)))->toBe([PasswordPolicy::MSG_TOO_LONG]);
});

it('uses a configurable minimum length', function () {
    expect((new PasswordPolicy(10))->validate('kopi12345'))->toBe(['Minimal 10 karakter.']);
});

it('rejects the initial pattern and school names', function (string $password, string $message) {
    expect(policyErrors($password))->toContain($message);
})->with([
    'exact initial password' => ['sm4g4_020307', PasswordPolicy::MSG_INITIAL_PATTERN],
    'pattern with suffix' => ['SM4G4_020307x', PasswordPolicy::MSG_INITIAL_PATTERN],
    'pattern prefix only' => ['xsm4g4rahasia9', PasswordPolicy::MSG_INITIAL_PATTERN],
    'smaga' => ['Smaga2026jaya', PasswordPolicy::MSG_SCHOOL_NAME],
    'sman3' => ['sman-3-semarang1', PasswordPolicy::MSG_SCHOOL_NAME],
]);

it('rejects the birth date in every form', function (string $password) {
    expect(policyErrors($password))->toContain(PasswordPolicy::MSG_BIRTH_DATE);
})->with([
    'dmy' => ['kopi020307'],
    'dmY' => ['kopi02032007'],
    'Ymd' => ['20070302kopi'],
    'd-m-Y' => ['kopi-02-03-2007'],
    'Y-m-d' => ['2007-03-02kopi'],
    'd/m/Y' => ['kopi 02/03/2007'],
]);

it('rejects NIS, NISN and name tokens', function (string $password, string $message) {
    expect(policyErrors($password))->toContain($message);
})->with([
    'nis' => ['kopi225226201', PasswordPolicy::MSG_STUDENT_NUMBER],
    'nisn' => ['0098765432abc', PasswordPolicy::MSG_STUDENT_NUMBER],
    'first name' => ['nadia12345', PasswordPolicy::MSG_OWN_NAME],
    'last name mixed case' => ['ANINDYA-99x', PasswordPolicy::MSG_OWN_NAME],
]);

it('ignores short name tokens and missing context', function () {
    expect((new PasswordPolicy)->validate('ayu12345', null, null, null, 'Citra Ayu Lestari'))->toBe([])
        ->and((new PasswordPolicy)->validate('kopi020307'))->toBe([]);
});

it('returns every failing rule in a fixed order', function () {
    expect(policyErrors('sm4g4020307'))->toBe([
        PasswordPolicy::MSG_INITIAL_PATTERN,
        PasswordPolicy::MSG_BIRTH_DATE,
    ]);
});
