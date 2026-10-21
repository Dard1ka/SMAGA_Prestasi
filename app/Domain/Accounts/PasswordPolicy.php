<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Support\Dates;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Rules for a NEW password (first-login change, SECURITY §3a K5). Comparison is case-insensitive
 * after removing separators, so "02-03-2007", "02/03/2007" and "02032007" are all caught.
 * Returns short Indonesian messages; an empty list means the password is acceptable.
 * The HIBP `uncompromised()` check stays in the Laravel validation layer.
 *
 * D-138: MSG_INITIAL_PATTERN covers the initial password of D-138 (the password equals it after normalising, so a
 * longer password that merely contains the value passes) and the old `sm4g4` pattern (contained).
 *
 * M2-A (QA of 2026-10-05, Q1-02 and Q2-05):
 * - Own account names: the username and the part of the email before the "@" are refused, whole and in pieces of
 *   4+ characters, like NIS/NISN and the name already were. Staff have no NIS, so this is their identity check.
 * - Common passwords: a small built-in list of words (COMMON_WORDS) and of patterns (alphabet and keyboard runs,
 *   digit runs, one character repeated). Digits and symbols around them never help: the password is refused when
 *   fewer than MIN_OTHER_LETTERS other letters are left after those words and patterns are cut out. So
 *   "password123", "Qwerty!2026", "P@ssw0rd-99" and "admin12345" fail, while a longer phrase that only contains such
 *   a word ("sandiwara malam 77") passes. It is a short list, not a dictionary: HIBP covers the rest in production.
 */
final class PasswordPolicy
{
    public const DEFAULT_MIN_LENGTH = 8;

    public const MAX_LENGTH = 64;

    public const MSG_TOO_LONG = 'Maksimal 64 karakter.';

    public const MSG_LETTERS_AND_DIGITS = 'Harus ada huruf dan angka.';

    public const MSG_INITIAL_PATTERN = 'Jangan memakai pola kata sandi awal.';

    public const MSG_SCHOOL_NAME = 'Jangan memakai nama sekolah.';

    public const MSG_BIRTH_DATE = 'Jangan memakai tanggal lahir.';

    public const MSG_STUDENT_NUMBER = 'Jangan memakai NIS atau NISN.';

    public const MSG_OWN_NAME = 'Jangan memakai nama sendiri.';

    public const MSG_USERNAME = 'Jangan memakai nama pengguna.';

    public const MSG_EMAIL = 'Jangan memakai email sendiri.';

    public const MSG_TOO_COMMON = 'Kata sandi ini terlalu mudah ditebak.';

    /** Normalised form of InitialPassword::LEGACY_PREFIX. */
    private const INITIAL_PATTERN = 'sm4g4';

    private const SCHOOL_WORDS = ['smaga', 'sman3'];

    private const MIN_NAME_TOKEN = 4;

    private const MIN_NUMBER_LENGTH = 4;

    /** A username is refused whole from this length on (usernames have at least 3 characters). */
    private const MIN_USERNAME = 3;

    /**
     * Words people reach for first, lowercase, without spaces. Spelling tricks with digits are listed as their own
     * word ("passw0rd"); "@" and "$" are read as "a" and "s" before the comparison.
     */
    private const COMMON_WORDS = [
        // "password" and its relatives
        'password', 'passw0rd', 'p4ssword', 'p4ssw0rd', 'pa55word', 'pa55w0rd', 'pasword', 'passwd', 'pass',
        'katasandi', 'katakunci', 'sandi', 'kunci', 'rahasia', 'r4hasia', 'secret', 'ganti', 'changeme',
        // accounts and signing in
        'administrator', 'admin', 'adm1n', 'username', 'user', 'root', 'guest', 'login', 'masuk', 'welcome',
        'selamatdatang', 'letmein',
        // school, app and place
        'smaga', 'sman3', 'sekolah', 'siswa', 'murid', 'pelajar', 'guru', 'prestasi', 'talenthub',
        'semarang', 'jateng', 'jawatengah', 'jakarta', 'indonesia', 'nusantara', 'merdeka', 'garuda', 'pancasila',
        // everyday favourites
        'bismillah', 'alhamdulillah', 'assalamualaikum', 'allahuakbar', 'iloveyou', 'sayang', 'cinta',
        'qwerty', 'abc', 'testing', 'test', 'coba', 'hello', 'monkey', 'dragon', 'football', 'sepakbola',
        'superman', 'naruto',
    ];

    /** Letters that sit next to each other in the alphabet or on a keyboard row. */
    private const LETTER_ROWS = ['abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm'];

    private const DIGIT_ROWS = ['01234567890'];

    /** "abcd", "qwer", "lkjh". */
    private const MIN_LETTER_RUN = 4;

    /** "12345", "98765". */
    private const MIN_DIGIT_RUN = 5;

    /** "aaaa", "0000". */
    private const MIN_REPEAT = 4;

    /** Letters a password needs besides the common words and patterns it contains. */
    private const MIN_OTHER_LETTERS = 5;

    /** Normalised initial password (D-138); '' = none to refuse. */
    private readonly string $initialPassword;

    public function __construct(private readonly int $minLength = self::DEFAULT_MIN_LENGTH, ?string $initialPassword = null)
    {
        if ($minLength < 1 || $minLength > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Panjang minimal kata sandi harus 1–64.');
        }

        $this->initialPassword = self::normalize((string) $initialPassword);
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /** @return list<string> */
    public function validate(
        string $password,
        DateTimeInterface|string|null $birthDate = null,
        ?string $nis = null,
        ?string $nisn = null,
        ?string $name = null,
        ?string $username = null,
        ?string $email = null,
    ): array {
        $errors = [];
        $length = mb_strlen($password);
        $normalized = self::normalize($password);

        if ($length < $this->minLength) {
            $errors[] = sprintf('Minimal %d karakter.', $this->minLength);
        }

        if ($length > self::MAX_LENGTH) {
            $errors[] = self::MSG_TOO_LONG;
        }

        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\p{N}/u', $password) !== 1) {
            $errors[] = self::MSG_LETTERS_AND_DIGITS;
        }

        if (str_contains($normalized, self::INITIAL_PATTERN) || ($this->initialPassword !== '' && $normalized === $this->initialPassword)) {
            $errors[] = self::MSG_INITIAL_PATTERN;
        }

        if (self::containsAny($normalized, self::SCHOOL_WORDS)) {
            $errors[] = self::MSG_SCHOOL_NAME;
        }

        if ($birthDate !== null && self::containsAny($normalized, self::birthDateForms($birthDate))) {
            $errors[] = self::MSG_BIRTH_DATE;
        }

        if (self::containsAny($normalized, self::numberForms($nis, $nisn))) {
            $errors[] = self::MSG_STUDENT_NUMBER;
        }

        if ($name !== null && self::containsAny($normalized, self::nameTokens($name))) {
            $errors[] = self::MSG_OWN_NAME;
        }

        if (self::containsAny($normalized, self::identifierForms($username, self::MIN_USERNAME))) {
            $errors[] = self::MSG_USERNAME;
        }

        if (self::containsAny($normalized, self::identifierForms(self::emailLocalPart($email), self::MIN_NAME_TOKEN))) {
            $errors[] = self::MSG_EMAIL;
        }

        if (self::isTooCommon($password)) {
            $errors[] = self::MSG_TOO_COMMON;
        }

        return $errors;
    }

    public function passes(
        string $password,
        DateTimeInterface|string|null $birthDate = null,
        ?string $nis = null,
        ?string $nisn = null,
        ?string $name = null,
        ?string $username = null,
        ?string $email = null,
    ): bool {
        return $this->validate($password, $birthDate, $nis, $nisn, $name, $username, $email) === [];
    }

    /**
     * True when the password is little more than common words and patterns: something from the lists is in it and
     * fewer than MIN_OTHER_LETTERS other letters remain. Digits and symbols never count as "other".
     */
    public static function isTooCommon(string $password): bool
    {
        $text = self::normalize(strtr(mb_strtolower($password), ['@' => 'a', '$' => 's']));

        // Runs first ("abcdef" is one run, not "abc" plus a rest). A space marks each cut, so the pieces around a
        // cut cannot join into a new word.
        $rest = str_replace(self::commonWords(), ' ', self::cutRuns($text));

        if ($rest === $text) {
            return false;
        }

        return preg_match_all('/\p{L}/u', $rest) < self::MIN_OTHER_LETTERS;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    /** @return list<string> ddmmyy, ddmmyyyy (also dd-mm-yyyy), yyyymmdd (also yyyy-mm-dd) */
    private static function birthDateForms(DateTimeInterface|string $birthDate): array
    {
        $date = Dates::toDate($birthDate);

        return [$date->format('dmy'), $date->format('dmY'), $date->format('Ymd')];
    }

    /** @return list<string> */
    private static function numberForms(?string ...$numbers): array
    {
        $forms = [];

        foreach ($numbers as $number) {
            $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

            if (strlen($digits) >= self::MIN_NUMBER_LENGTH) {
                $forms[] = $digits;
            }
        }

        return $forms;
    }

    /** @return list<string> */
    private static function nameTokens(string $name): array
    {
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $t): bool => mb_strlen($t) >= self::MIN_NAME_TOKEN));
    }

    private static function emailLocalPart(?string $email): ?string
    {
        return $email === null ? null : explode('@', $email, 2)[0];
    }

    /**
     * A username or the local part of an email as it may show up in a password: the whole identifier without its
     * separators ("dwi.rahayu" → "dwirahayu") and each piece of 4+ characters that has a letter ("rahayu").
     * A bare number such as the "2026" of "dwi.2026" is not a piece: a year alone does not point at the account.
     *
     * @return list<string>
     */
    private static function identifierForms(?string $identifier, int $minWhole): array
    {
        $identifier = mb_strtolower(trim((string) $identifier));
        $whole = self::normalize($identifier);
        $forms = mb_strlen($whole) >= $minWhole ? [$whole] : [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $identifier, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (mb_strlen($piece) >= self::MIN_NAME_TOKEN && preg_match('/\p{L}/u', $piece) === 1) {
                $forms[] = $piece;
            }
        }

        return array_values(array_unique($forms));
    }

    /** @return list<string> the word list, longest first, so "katasandi" is cut before "sandi" */
    private static function commonWords(): array
    {
        static $words = null;

        if ($words === null) {
            $words = self::COMMON_WORDS;
            usort($words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $words;
    }

    /** Replaces every run ("abcd", "lkjh", "12345", "98765") and repeat ("aaaa", "0000") with a space. */
    private static function cutRuns(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $run = self::runLength($text, $i);

            if ($run > 0) {
                $out .= ' ';
                $i += $run;

                continue;
            }

            $out .= $text[$i];
            $i++;
        }

        return $out;
    }

    /** Length of the run or repeat that starts at `$from`, or 0 when there is none of the minimum length. */
    private static function runLength(string $text, int $from): int
    {
        $char = $text[$from];

        if ($char === ' ') {
            return 0;
        }

        $digit = ctype_digit($char);
        $repeat = strspn($text, $char, $from);
        $best = $repeat >= self::MIN_REPEAT ? $repeat : 0;

        foreach ($digit ? self::DIGIT_ROWS : self::LETTER_ROWS as $row) {
            foreach ([$row, strrev($row)] as $direction) {
                $run = 1;

                while ($from + $run < strlen($text) && str_contains($direction, substr($text, $from, $run + 1))) {
                    $run++;
                }

                if ($run >= ($digit ? self::MIN_DIGIT_RUN : self::MIN_LETTER_RUN)) {
                    $best = max($best, $run);
                }
            }
        }

        return $best;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
