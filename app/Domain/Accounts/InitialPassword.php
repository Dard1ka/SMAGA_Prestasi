<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Support\Dates;
use DateTimeInterface;

/**
 * Initial student password (D-24): "sm4g4_" + birth date as ddmmyy, e.g. 2007-03-02 → sm4g4_020307.
 *
 * Only for import / admin reset (then hashed, must_change_password = true). Login code must never
 * build this value (SECURITY K6), and it must never be shown in the UI (K12).
 */
final class InitialPassword
{
    public const PREFIX = 'sm4g4_';

    public function make(DateTimeInterface|string $birthDate): string
    {
        return self::PREFIX.Dates::toDate($birthDate)->format('dmy');
    }

    /**
     * Detection only (SECURITY K7): run after Hash::check failed on an active account; never grants access.
     */
    public function matches(string $candidate, DateTimeInterface|string $birthDate): bool
    {
        return hash_equals($this->make($birthDate), $candidate);
    }
}
