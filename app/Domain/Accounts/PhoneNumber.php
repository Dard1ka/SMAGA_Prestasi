<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * A student's No HP (D-149): one rule for the student import (ImportStudents) and the student's own edit on the
 * registration form and on "Ikut tim" (UpdateStudentPhone). An Indonesian mobile number: "08…" or "+628…", 10 to 15
 * digits after the prefix rule `^(\+62|0)8\d{7,12}$`. The number itself is personal data: never in an audit row.
 */
final class PhoneNumber
{
    public const PATTERN = '/^(\+62|0)8\d{7,12}$/';

    public const INVALID = 'No HP tidak valid (contoh 0812…).';

    /**
     * Drops the usual separators (spaces, dots, dashes, brackets) and repairs the two common forms:
     * "62812…" → "+62812…", "812…" (a spreadsheet cell that lost its leading zero) → "0812…". Anything else is
     * returned compacted, for isValid() to judge.
     */
    public static function normalize(string $value): string
    {
        $compact = (string) preg_replace('/[\s.\-()]/', '', trim($value));

        if (preg_match('/^62\d+$/', $compact) === 1) {
            return '+'.$compact;
        }

        if (preg_match('/^8\d+$/', $compact) === 1) {
            return '0'.$compact;
        }

        return $compact;
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
