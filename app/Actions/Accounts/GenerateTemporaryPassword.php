<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

/**
 * A random, easy-to-type temporary password: three lowercase syllables and four digits, e.g. "bakuro4827" (10
 * characters, about 31 bits; no c, l, q or x, digits 2–9). CSPRNG only (random_int). Written for the Admin reset of
 * D-158; since 2026-10-21 the Admin types the temporary password of a student reset (ResetStudentAccount), so the app
 * no longer calls this. Its only caller is the email scenario (`tests/E2E/support/email-scenario.php`), which makes
 * staff passwords with it: delete this class together with those calls.
 */
final class GenerateTemporaryPassword
{
    public const LENGTH = 10;

    public const SYLLABLES = 3;

    public const DIGIT_COUNT = 4;

    private const CONSONANTS = 'bdfghjkmnprstvwyz';

    private const VOWELS = 'aeiou';

    private const DIGITS = '23456789';

    public function handle(): string
    {
        $password = '';

        for ($i = 0; $i < self::SYLLABLES; $i++) {
            $password .= self::pick(self::CONSONANTS).self::pick(self::VOWELS);
        }

        for ($i = 0; $i < self::DIGIT_COUNT; $i++) {
            $password .= self::pick(self::DIGITS);
        }

        return $password;
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
