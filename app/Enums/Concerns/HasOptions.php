<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared helpers for string-backed enums that define label().
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasOptions
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return array<string, string> value => label, in declaration (display) order */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
