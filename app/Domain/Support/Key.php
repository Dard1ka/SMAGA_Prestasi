<?php

declare(strict_types=1);

namespace App\Domain\Support;

use BackedEnum;

/**
 * Lets domain services accept either an app enum (App\Enums\*) or its raw backing value.
 */
final class Key
{
    public static function of(string|int|BackedEnum $key): string
    {
        return $key instanceof BackedEnum ? (string) $key->value : (string) $key;
    }
}
