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

    /** Normalised form of InitialPassword::PREFIX. */
    private const INITIAL_PATTERN = 'sm4g4';

    private const SCHOOL_WORDS = ['smaga', 'sman3'];

    private const MIN_NAME_TOKEN = 4;

    private const MIN_NUMBER_LENGTH = 4;

    public function __construct(private readonly int $minLength = self::DEFAULT_MIN_LENGTH)
    {
        if ($minLength < 1 || $minLength > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Panjang minimal kata sandi harus 1–64.');
        }
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

        if (str_contains($normalized, self::INITIAL_PATTERN)) {
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

        return $errors;
    }

    public function passes(
        string $password,
        DateTimeInterface|string|null $birthDate = null,
        ?string $nis = null,
        ?string $nisn = null,
        ?string $name = null,
    ): bool {
        return $this->validate($password, $birthDate, $nis, $nisn, $name) === [];
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
