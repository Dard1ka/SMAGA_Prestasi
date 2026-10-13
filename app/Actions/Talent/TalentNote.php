<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use Illuminate\Validation\ValidationException;

/**
 * The free-text note of a pilihan talenta: the optional "catatan untuk guru pembina" (`note`, lead 2026-10-04) that
 * Kesiswaan writes in the panel (UpdateTalentAssessment). One place for trimming and the length limit. (The note for
 * the student and the review message left with the stages, D-142.)
 */
final class TalentNote
{
    public const MAX = 1000;

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
}
