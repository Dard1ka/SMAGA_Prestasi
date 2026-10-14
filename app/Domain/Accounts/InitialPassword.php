<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Support\Dates;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Initial student password (D-138): one value from config `sth.initial_student_password`, the same for every
 * newly imported student, hashed at import (and by the local DemoSeeder) and nowhere else.
 *
 * The old D-24 pattern "sm4g4_" + birth date as ddmmyy (2007-03-02 → sm4g4_020307) exists only for accounts
 * imported before 12 Oct 2026: matches() still recognises it for K7, make() never builds it.
 *
 * The value is never shown or logged (SECURITY K6, K12): not in the UI, mail, flash, exception, log or audit row.
 * Login code must never build it; K7 only detects it after Hash::check failed.
 */
final class InitialPassword
{
    /** Prefix of the pattern used before D-138 (K7 still has to recognise it). */
    public const LEGACY_PREFIX = 'sm4g4_';

    /** The value when config/env gives none, or only spaces (config `sth.initial_student_password`). */
    public const DEFAULT = '12345';

    private readonly string $value;

    /** Spaces around the value are not part of it (FS1 fix wave); a value of spaces only is refused. */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Kata sandi awal siswa belum diatur.');
        }

        $this->value = $value;
    }

    /**
     * The configured value as the app uses it: trimmed, DEFAULT when it is empty, blank or not a string. Used by
     * config/sth.php and the container binding, so a blank STH_INITIAL_STUDENT_PASSWORD (or a blank value set at
     * runtime) never makes a login throw, and every comparison (K7, the security log) sees the same value.
     */
    public static function normalize(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? self::DEFAULT : $value;
    }

    public static function fromConfig(): self
    {
        return new self(self::normalize(config('sth.initial_student_password')));
    }

    /** Only for hashing at import (and the local DemoSeeder). */
    public function make(): string
    {
        return $this->value;
    }

    /**
     * Detection only (SECURITY K7): run after Hash::check failed on an active account; never grants access.
     * True for the configured value, and, when the birth date is known, for the old D-24 pattern.
     */
    public function matches(string $candidate, DateTimeInterface|string|null $birthDate = null): bool
    {
        if (hash_equals($this->value, $candidate)) {
            return true;
        }

        return $birthDate !== null && hash_equals($this->legacy($birthDate), $candidate);
    }

    private function legacy(DateTimeInterface|string $birthDate): string
    {
        return self::LEGACY_PREFIX.Dates::toDate($birthDate)->format('dmy');
    }
}
