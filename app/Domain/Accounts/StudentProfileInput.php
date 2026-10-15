<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Carbon\CarbonImmutable;

/**
 * The two profile fields a student fills in (D-152): the birth date and the No HP. One rule for the "Data diri" step of
 * the first activation (CompleteActivation::confirmProfile) and for Profil saya (UpdateStudentProfile).
 *
 * - Birth date: `Y-m-d` (what an <input type="date"> sends), a real day, between MAX_AGE and MIN_AGE years before
 *   today (*sementara*: a wide window for SMA students, it only stops typing slips such as 2091 or 1909).
 * - No HP: the import's rule (PhoneNumber), after its normalisation.
 *
 * Both values are personal data: never in an audit row, a log line or an error text.
 */
final class StudentProfileInput
{
    public const MIN_AGE = 10;

    public const MAX_AGE = 30;

    public const BIRTH_DATE_EMPTY = 'Isi tanggal lahir.';

    public const BIRTH_DATE_INVALID = 'Tulis tanggal lahir yang benar.';

    public const BIRTH_DATE_RANGE = 'Periksa lagi tanggal lahirmu.';

    public const PHONE_EMPTY = 'Isi No HP.';

    /**
     * Every problem of the two fields at once, keyed `birth_date` / `phone` (empty = both are fine).
     *
     * @return array<string, string>
     */
    public static function errors(string $birthDate, string $phone, CarbonImmutable $today): array
    {
        $errors = [];

        if (trim($birthDate) === '') {
            $errors['birth_date'] = self::BIRTH_DATE_EMPTY;
        } elseif (($date = self::parseBirthDate($birthDate)) === null) {
            $errors['birth_date'] = self::BIRTH_DATE_INVALID;
        } elseif (! self::withinRange($date, $today)) {
            $errors['birth_date'] = self::BIRTH_DATE_RANGE;
        }

        $normalized = PhoneNumber::normalize($phone);

        if ($normalized === '') {
            $errors['phone'] = self::PHONE_EMPTY;
        } elseif (! PhoneNumber::isValid($normalized)) {
            $errors['phone'] = PhoneNumber::INVALID;
        }

        return $errors;
    }

    /** `Y-m-d` of a real calendar day, else null ("2026-02-30" and "15-05-2010" are null). */
    public static function parseBirthDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function withinRange(CarbonImmutable $birthDate, CarbonImmutable $today): bool
    {
        $day = $birthDate->startOfDay();

        return $day->lessThanOrEqualTo($today->startOfDay()->subYears(self::MIN_AGE))
            && $day->greaterThanOrEqualTo($today->startOfDay()->subYears(self::MAX_AGE));
    }
}
