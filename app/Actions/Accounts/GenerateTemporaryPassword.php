<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

/**
 * K10 (docs/SECURITY.md §3a): random temporary password for an Admin reset — 12 characters from an alphabet
 * without look-alikes (no 0/O, 1/l/I), at least one lowercase letter, one uppercase letter and one digit.
 * CSPRNG only (random_int). Shown once to the Admin, never stored in plaintext or logged.
 */
final class GenerateTemporaryPassword
{
    public const LENGTH = 12;

    private const LOWER = 'abcdefghjkmnpqrstuvwxyz';

    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const DIGITS = '23456789';

    public function handle(): string
    {
        $all = self::LOWER.self::UPPER.self::DIGITS;
        $chars = [self::pick(self::LOWER), self::pick(self::UPPER), self::pick(self::DIGITS)];

        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }

        // Fisher–Yates with random_int so the guaranteed classes are not always in front.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
