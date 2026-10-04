<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use Illuminate\Validation\ValidationException;

/**
 * The free-text fields of the talent pipeline, all optional (lead, 2026-10-04): catatan untuk guru pembina
 * (`note`), catatan untuk siswa (`student_note`) and the student's message with "Minta ditinjau ulang"
 * (`review_message`). One place for trimming, the length limit and the one-line form used in notifications.
 */
final class TalentNote
{
    public const MAX = 1000;

    public const MAX_REVIEW_MESSAGE = 500;

    /**
     * Trimmed text, or null when nothing was written.
     *
     * @throws ValidationException under $key when the text is longer than $max
     */
    public static function clean(?string $value, string $key, int $max = self::MAX, string $label = 'Catatan'): ?string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([
                $key => $label.' maksimal '.number_format($max, 0, ',', '.').' karakter.',
            ]);
        }

        return $value === '' ? null : $value;
    }

    /** One line for a notification body: line breaks and runs of spaces become one space. */
    public static function inline(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
