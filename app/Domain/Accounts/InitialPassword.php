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

    public function __construct(private readonly string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Kata sandi awal siswa belum diatur.');
        }
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
