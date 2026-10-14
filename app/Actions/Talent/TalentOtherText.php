<?php

declare(strict_types=1);

namespace App\Actions\Talent;

/**
 * The student's own text for the jenis "Lainnya" or a cabang "Lainnya" ("Sebutkan", D-143,
 * `talent_applications.other_text`): plain text of 3 to 60 characters. One place for tidying the text and for the key
 * that makes two texts the same choice ("Kaligrafi" and "  kaligrafi " are one talent).
 */
final class TalentOtherText
{
    public const MIN = 3;

    public const MAX = 60;

    public const REQUIRED_MESSAGE = 'Tulis talentamu di kolom Sebutkan.';

    public const LENGTH_MESSAGE = 'Tulis 3 sampai 60 karakter.';

    /** Every run of white space (line breaks too) becomes one space, trimmed; nothing left = null. */
    public static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /** Comparison key of a text: tidied and lower case ('' for no text). */
    public static function key(?string $value): string
    {
        return mb_strtolower(self::clean($value) ?? '');
    }
}
