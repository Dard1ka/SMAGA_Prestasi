<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Models\AppreciationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * When an appreciation event is, as text (D-123, lead 2026-10-10): the one place that puts the day and the start
 * time of an event into words, so the Actions, the notifications and the screens say the same. An event made before
 * events had a start time has none and gets its date only; nothing invents "00.00" for it.
 *
 * The time is a wall-clock time of the school (app timezone, Asia/Jakarta), hence "WIB".
 */
final class EventWhen
{
    public const TIME_MESSAGE = 'Isi jam acara.';

    /**
     * The start time typed in "Buat acara": "07:00" (what a time input sends) or "07:00:00"; seconds are dropped.
     *
     * @return string 'HH:MM:00', as stored in appreciation_events.held_time
     *
     * @throws ValidationException key `held_time`
     */
    public static function parseTime(string $value): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $m) !== 1) {
            throw ValidationException::withMessages(['held_time' => self::TIME_MESSAGE]);
        }

        return $m[1].':'.$m[2].':00';
    }

    /** "Senin, 12 Oktober 2026, 07.00 WIB" for cards and dialogs; "Senin, 12 Oktober 2026" without a time. */
    public static function long(AppreciationEvent $event): string
    {
        $time = self::clock($event);

        return self::day($event, 'l, j F Y').($time !== null ? ', '.$time.' WIB' : '');
    }

    /**
     * "Senin, 12 Oktober 2026 pukul 07.00 WIB" for a sentence ("… di Upacara Senin pada …"); the date alone
     * without a time, '' without an event.
     */
    public static function sentence(?AppreciationEvent $event): string
    {
        if ($event === null) {
            return '';
        }

        $time = self::clock($event);

        return self::day($event, 'l, j F Y').($time !== null ? ' pukul '.$time.' WIB' : '');
    }

    /** "12 Okt 2026, 07.00" for a select option or a short line; "12 Okt 2026" without a time. */
    public static function short(AppreciationEvent $event): string
    {
        $time = self::clock($event);

        return self::day($event, 'j M Y').($time !== null ? ', '.$time : '');
    }

    /** "07.00", the way the school writes a time, or null. */
    private static function clock(AppreciationEvent $event): ?string
    {
        $time = $event->heldTime();

        return $time !== null ? str_replace(':', '.', $time) : null;
    }

    private static function day(AppreciationEvent $event, string $format): string
    {
        $day = $event->held_on;

        return $day instanceof CarbonImmutable ? $day->locale('id')->translatedFormat($format) : '';
    }
}
