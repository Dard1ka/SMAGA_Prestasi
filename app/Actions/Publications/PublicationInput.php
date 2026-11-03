<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationChannel;
use Illuminate\Validation\ValidationException;

/** Shared input rules of the publication Actions (caption, channels). Messages are short UI copy. */
final class PublicationInput
{
    public const CAPTION_MAX = 2000;

    /**
     * Plain-text caption (rendered escaped, never as HTML): trimmed, control characters removed.
     *
     * @throws ValidationException
     */
    public static function caption(?string $caption, bool $required): ?string
    {
        $caption = trim((string) preg_replace('/[^\P{C}\n\t]+/u', '', str_replace("\r\n", "\n", (string) $caption)));

        if ($caption === '') {
            if ($required) {
                throw ValidationException::withMessages(['caption' => 'Tulis caption dulu.']);
            }

            return null;
        }

        if (mb_strlen($caption) > self::CAPTION_MAX) {
            throw ValidationException::withMessages(['caption' => 'Caption maksimal 2.000 karakter.']);
        }

        return $caption;
    }

    /**
     * At least one known channel; returned as a unique list of values in enum order.
     *
     * @param  array<int, mixed>  $channels
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function channels(array $channels): array
    {
        $given = array_map(fn (mixed $c): string => $c instanceof PublicationChannel ? $c->value : (string) $c, $channels);

        foreach ($given as $value) {
            if (PublicationChannel::tryFrom($value) === null) {
                throw ValidationException::withMessages(['channels' => 'Kanal tidak dikenal.']);
            }
        }

        $ordered = array_values(array_filter(
            PublicationChannel::values(),
            fn (string $value): bool => in_array($value, $given, true),
        ));

        if ($ordered === []) {
            throw ValidationException::withMessages(['channels' => 'Pilih minimal satu kanal.']);
        }

        return $ordered;
    }

    /**
     * "Instagram, Website" for a stored channel list.
     *
     * @param  array<int, string>|null  $channels
     */
    public static function channelLabels(?array $channels): string
    {
        return implode(', ', array_map(
            fn (string $value): string => PublicationChannel::tryFrom($value)?->label() ?? $value,
            array_values(array_filter((array) $channels, 'is_string')),
        ));
    }
}
