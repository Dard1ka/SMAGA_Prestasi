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
        ->and(policyErrors('lompatjauh'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS])
        ->and(policyErrors('7391846205'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS])
        // M2-A: a bare alphabet or digit run is also a pattern everybody tries first.
        ->and(policyErrors('abcdefghij'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS, PasswordPolicy::MSG_TOO_COMMON])
        ->and(policyErrors('1234567890'))->toBe([PasswordPolicy::MSG_LETTERS_AND_DIGITS, PasswordPolicy::MSG_TOO_COMMON])
        ->and(policyErrors(str_repeat('a1', 33)))->toBe([PasswordPolicy::MSG_TOO_LONG]);
});

it('uses a configurable minimum length', function () {
    // M2-A: was "kopi12345", which is now also refused as four letters around the digit run 12345.
    expect((new PasswordPolicy(10))->validate('kopi74219'))->toBe(['Minimal 10 karakter.']);
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
    // M2-A: was "ayu12345", which is now refused as three letters around the digit run 12345.
    expect((new PasswordPolicy)->validate('ayu7lompat9', null, null, null, 'Citra Ayu Lestari'))->toBe([])
        ->and((new PasswordPolicy)->validate('kopi020307'))->toBe([]);
});

it('returns every failing rule in a fixed order', function () {
    expect(policyErrors('sm4g4020307'))->toBe([
        PasswordPolicy::MSG_INITIAL_PATTERN,
        PasswordPolicy::MSG_BIRTH_DATE,
    ])->and((new PasswordPolicy)->validate('Nadia-admin-1', null, null, null, PP_NAME, 'admin', 'admin@example.test'))->toBe([
        PasswordPolicy::MSG_OWN_NAME,
        PasswordPolicy::MSG_USERNAME,
        PasswordPolicy::MSG_EMAIL,
    ]);
});

// M2-A (QA 2026-10-05, Q1-02): common passwords -------------------------------------------------------

it('refuses common words and patterns, whatever digits or symbols surround them', function (string $password) {
    expect(policyErrors($password))->toContain(PasswordPolicy::MSG_TOO_COMMON)
        ->and(PasswordPolicy::isTooCommon($password))->toBeTrue();
})->with([
    'the QA finding' => ['password123'],
    'capital and symbol' => ['Password2026!'],
    'digits in front' => ['123password'],
    'symbols on both sides' => ['!!Qwerty99**'],
    '@ and 0 for a and o' => ['P@ssw0rd-99'],
    '$ for s' => ['pa$$word2026'],
    'separators inside' => ['p.a.s.s.w.o.r.d-77'],
    'katasandi' => ['katasandi123'],
    'sandi' => ['sandi-12345'],
    'rahasia' => ['Rahasia2026'],
    'admin' => ['admin12345'],
    'the same word twice' => ['ADMIN-admin-1'],
    'two common words' => ['passwordrahasia1'],
    'indonesia' => ['indonesia45'],
    'semarang' => ['Semarang2026'],
    'sekolah' => ['sekolah12345'],
    'merdeka' => ['merdeka1945'],
    'bismillah' => ['Bismillah123'],
    'iloveyou' => ['iloveyou2026'],
    'keyboard row' => ['qwerty123456'],
    'second keyboard row' => ['asdfgh123456'],
    'keyboard row backwards' => ['lkjhgf2026x'],
    'alphabet run' => ['abcdef123456'],
    'abc123 twice' => ['abc123abc123'],
    'digit run backwards' => ['a0987654321'],
    'one character repeated' => ['aaaaaa111111'],
    'a short word around a digit run' => ['kode123456'],
    'a two-letter ending' => ['passwordku1'],
    'a four-letter ending' => ['sandibaru123'],
]);

it('gives the school name its own message as before and also counts it as common', function () {
    expect(policyErrors('smaga12345'))->toBe([PasswordPolicy::MSG_SCHOOL_NAME, PasswordPolicy::MSG_TOO_COMMON]);
});

it('accepts a longer phrase that only contains a common word, and passwords without any', function (string $password) {
    expect(policyErrors($password))->toBe([])
        ->and(PasswordPolicy::isTooCommon($password))->toBeFalse();
})->with([
    'sandi inside another word' => ['sandiwara malam 77'],
    'semarang in a phrase' => ['kopi-semarang-pagi-42'],
    'guru inside gurun' => ['gurun sahara 9 unta'],
    'bismillah plus more' => ['Bismillah-lulus-PTN-27'],
    'six other letters next to a digit run' => ['kucing123456'],
    'random letters and digits' => ['x7k2m9q4w1'],
    'few letters, random digits' => ['zq7391846205'],
]);

// M2-A (QA 2026-10-05, Q2-05): the account's own username and email ---------------------------------------

it('refuses the username, whole and in pieces', function (string $password, string $username) {
    expect((new PasswordPolicy)->validate($password, username: $username))->toContain(PasswordPolicy::MSG_USERNAME);
})->with([
    'the QA finding: username-username' => ['uji.qa2-uji.qa2', 'uji.qa2'],
    'whole, separators dropped' => ['dwirahayu-2026x', 'dwi.rahayu'],
    'one piece' => ['Rahayu-hebat-77', 'dwi.rahayu'],
    'mixed case' => ['GALIH_P-kuat-91', 'galih_p'],
    'a three-character username' => ['tu1-tu1-tu1-9x', 'tu1'],
]);

it('refuses the part of the email before the @, never the domain', function () {
    $policy = new PasswordPolicy;

    expect($policy->validate('Endang-kuat-2026', email: 'bu.endang@example.test'))->toBe([PasswordPolicy::MSG_EMAIL])
        ->and($policy->validate('buendang-7-lompat', email: 'Bu.Endang@example.test'))->toBe([PasswordPolicy::MSG_EMAIL])
        ->and($policy->validate('example-kuat-77', email: 'bu.endang@example.test'))->toBe([])
        ->and($policy->passes('Endang-kuat-2026', email: 'bu.endang@example.test'))->toBeFalse();
});

it('does not treat a short piece or a bare number of the username or email as the account name', function () {
    $policy = new PasswordPolicy;

    expect($policy->validate('kopi-2026-pagi', username: 'dwi.2026', email: 'dwi.2026@example.test'))->toBe([])
        ->and($policy->validate('dwi-lompat-73', username: 'dwi.rahayu', email: 'dwi.rahayu@example.test'))->toBe([])
        ->and($policy->validate('kopi susu 42 pagi', username: null, email: null))->toBe([])
        ->and($policy->validate('kopi susu 42 pagi', username: '', email: ''))->toBe([]);
});

it('refuses the configured initial password as a new password, whatever case and separators (D-138)', function (): void {
    $policy = new PasswordPolicy(10, 'Pelangi-Rindang-88');

    expect($policy->validate('pelangi rindang 88'))->toBe([PasswordPolicy::MSG_INITIAL_PATTERN])
        ->and($policy->validate('PELANGI_RINDANG_88'))->toBe([PasswordPolicy::MSG_INITIAL_PATTERN])
        ->and($policy->validate('Pelangi-Rindang-89'))->toBe([])
        ->and((new PasswordPolicy(10, '12345'))->validate('12345'))->toContain(PasswordPolicy::MSG_INITIAL_PATTERN)
        ->and((new PasswordPolicy(10, '12345'))->validate('Hujan-12345-pagi'))->not->toContain(PasswordPolicy::MSG_INITIAL_PATTERN)
        ->and((new PasswordPolicy(10))->validate('Pelangi-Rindang-88'))->toBe([]);
});
