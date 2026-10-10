<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The name a person is called by, taken from the full name: the first word that is not an abbreviation.
 * "Drs. Bambang Hartono, M.Pd." → "Bambang", "M. Rizky Pratama" → "Rizky", "Nadia Putri Anindya" → "Nadia".
 * The first word alone gave "Halo Drs.," and "Halo, M." (lead's mail test, 2026-10-09).
 *
 * An abbreviation is a word that ends with a full stop (a title, a degree, an initial) or a single letter
 * ("M Rizky": school lists often drop the full stop). A name made of abbreviations only is returned whole.
 * Used by the greeting of a mail to a student (App\Notifications\Support\MailGreeting) and by "Halo, …" on the
 * student's screens.
 */
final class FirstName
{
    public static function of(?string $name): ?string
    {
        $name = self::fold((string) $name);

        if ($name === '') {
            return null;
        }

        foreach (explode(' ', $name) as $word) {
            // "Bambang, S.Pd.": the comma belongs to the degree that follows.
            $word = rtrim($word, ',');

            if ($word !== '' && ! str_ends_with($word, '.') && mb_strlen($word) > 1) {
                return $word;
            }
        }

        return $name;
    }

    /** Trimmed, every run of whitespace (line breaks too) as one space. */
    public static function fold(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? (string) preg_replace('/\s+/', ' ', $text));
    }
}
