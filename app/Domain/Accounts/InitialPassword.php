<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Support\Dates;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Initial student password.
 *
 * D-173 (smaga_server, the lead's request of 2 Nov 2026): a newly imported student gets PREFIX + the birth date of the
 * imported row as ddmmyyyy (01-02-2010 → SMAGA_01022010), built by forBirthDate(). Students born on one day share it
 * (accepted by the lead). It is hashed by ImportStudents::createStudent(), by the seeders (DataUjiSekolahSeeder, the
 * local DemoSeeder) and by `sth:siswa:kata-sandi-awal`, nowhere else; the student must replace it at the activation
 * (D-138 stays for that: no "Lewati").
 *
 * D-138: the configured value (config `sth.initial_student_password`) was the one initial password of every student
 * imported before D-173. make() still returns it, for four readers: K7 (matches()), PasswordPolicy (refused as a new
 * password, equality; AppServiceProvider), the security-log fingerprint of a 10-digit identifier
 * (SecurityEventSubscriber::identifier()), and the command that moves the pending students who are still on it to the
 * D-173 pattern (App\Actions\Accounts\ApplyBirthDateInitialPasswords). Nothing hashes it for a new account any more.
 *
 * The old D-24 pattern "sm4g4_" + birth date as ddmmyy (2007-03-02 → sm4g4_020307) exists only for accounts imported
 * before 12 Oct 2026: matches() still recognises it for K7, nothing builds it for an account.
 *
 * No value is ever shown or logged (SECURITY K6, K12): not in the UI, mail, flash, exception, log or audit row. The
 * import screen states the D-173 rule with a fictional example only, never a student's own value. Login code never
 * builds a password; K7 only detects one after Hash::check failed.
 */
final class InitialPassword
{
    /** D-173: the fixed start of the birth-date password ("SMAGA_" + ddmmyyyy). */
    public const PREFIX = 'SMAGA_';

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

    /**
     * D-173: the initial password of a student born on $birthDate: PREFIX + the calendar day as ddmmyyyy.
     *
     * The calendar day is the date part of the value, never converted to another timezone: a DateTimeInterface keeps
     * its own timezone (Dates::toDate), so 01-02-2010 00:00 Asia/Jakarta, 2010-02-01 00:00 UTC, the `students.birth_date`
     * cast read back from the database and the string '2010-02-01' all give SMAGA_01022010. Strings must be 'Y-m-d'
     * (or 'Y-m-d H:i:s', the raw DB value); anything else throws InvalidArgumentException.
     */
    public static function forBirthDate(DateTimeInterface|string $birthDate): string
    {
        return self::PREFIX.Dates::toDate($birthDate)->format('dmY');
    }

    /**
     * The configured value of D-138, read by K7 (matches()), PasswordPolicy (equality), the security-log fingerprint
     * (SecurityEventSubscriber::identifier()) and the move of the pending students who still have it
     * (ApplyBirthDateInitialPasswords). Never hashed for a new account since D-173.
     */
    public function make(): string
    {
        return $this->value;
    }

    /**
     * Detection only (SECURITY K7): run after Hash::check failed on an active account; never grants access.
     * True for the configured value of D-138 and, when the birth date is known, for the D-173 pattern and the old D-24
     * pattern. Compared exactly (case-sensitive), like the password itself.
     */
    public function matches(string $candidate, DateTimeInterface|string|null $birthDate = null): bool
    {
        if (hash_equals($this->value, $candidate)) {
            return true;
        }

        if ($birthDate === null) {
            return false;
        }

        return hash_equals(self::forBirthDate($birthDate), $candidate)
            || hash_equals($this->legacy($birthDate), $candidate);
    }

    private function legacy(DateTimeInterface|string $birthDate): string
    {
        return self::LEGACY_PREFIX.Dates::toDate($birthDate)->format('dmy');
    }
}
