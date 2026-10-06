<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use Illuminate\Validation\ValidationException;

/**
 * The free-text fields of the talent pipeline, all optional (lead, 2026-10-04): catatan untuk guru pembina
 * (`note`), catatan untuk siswa (`student_note`, and its unsent draft `student_note_draft`) and the student's
 * message with "Minta ditinjau ulang" (`review_message`). One place for trimming, the length limit, the
 * draft-or-typed choice of a decision and the one-line form used in notifications.
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

    /**
     * The note for the student that a decision (lolos, tidak lolos, dipulihkan) sends (M2-B, QA Q2-14):
     * - the caller passed a text for this decision ($wasGiven): that text, an empty one = no note;
     * - the caller passed none (the row button, a bulk move without a note): the draft that "Simpan" kept
     *   on the row (`student_note_draft`), so a saved note is never dropped without a word.
     * Either way the decision clears the draft afterwards.
     *
     * @param  ?string  $given  the caller's text after clean() (null = empty)
     * @return array{0: ?string, 1: bool} the note, and whether it came from the draft
     */
    public static function forDecision(?string $given, bool $wasGiven, ?string $draft): array
    {
        if ($wasGiven) {
            return [$given, false];
        }

        $draft = trim((string) $draft);

        return $draft === '' ? [null, false] : [mb_substr($draft, 0, self::MAX), true];
    }

    /** One line for a notification body: line breaks and runs of spaces become one space. */
    public static function inline(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
