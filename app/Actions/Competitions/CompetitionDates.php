<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * The date rules of a lomba that need more than one field or the clock (QA 2026-10-05, Q1-04). One place for
 * CreateCompetition, UpdateCompetition, VerifyCompetition and the Kesiswaan screens:
 *
 * - "Tanggal mulai" is never before "Batas daftar": a lomba cannot start while its registration is still open.
 * - A lomba whose registration deadline or final date has passed may still be published, because old lomba
 *   are sometimes entered for the record, but only after an explicit "yes" (VerifyCompetition's
 *   `$confirmPastDates`). Saving such dates without publishing needs no confirmation.
 *
 * Values may be model dates, `Y-m-d` form values or `Y-m-d H:i:s` strings; anything else counts as "no date".
 */
final class CompetitionDates
{
    public const STARTS_BEFORE_DEADLINE = 'Tanggal mulai tidak boleh sebelum batas daftar.';

    public const PAST_QUESTION = 'Tanggal lomba ini sudah lewat. Tetap publikasikan?';

    /** Error key of VerifyCompetition's "confirm first" refusal. */
    public const PAST_KEY = 'past_dates';

    public static function startsBeforeDeadline(mixed $startsOn, mixed $closesAt): bool
    {
        $start = self::moment($startsOn);
        $close = self::moment($closesAt);

        return $start !== null && $close !== null && $start->toDateString() < $close->toDateString();
    }

    /**
     * Adds the "mulai sebelum batas daftar" error to a validator of the lomba form, next to the other field
     * errors (only when both dates are valid by themselves).
     */
    public static function checkOrder(Validator $validator): void
    {
        $data = $validator->getData();

        if ($validator->errors()->hasAny(['starts_on', 'registration_closes_at'])) {
            return;
        }

        if (self::startsBeforeDeadline($data['starts_on'] ?? null, $data['registration_closes_at'] ?? null)) {
            $validator->errors()->add('starts_on', self::STARTS_BEFORE_DEADLINE);
        }
    }

    /**
     * The dates that already lie in the past, as field => "batas daftar 1 Sep 2026" (empty = nothing passed).
     * The registration deadline counts until the end of its day when only a date is given (the form), the
     * final date until the end of that day.
     *
     * @return array<string, string>
     */
    public static function past(mixed $closesAt, mixed $finalOn, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $past = [];

        $closes = self::moment($closesAt, endOfDayForDates: true);

        if ($closes !== null && $closes->lessThan($now)) {
            $past['registration_closes_at'] = 'batas daftar '.self::label($closes);
        }

        $final = self::moment($finalOn);

        if ($final !== null && $final->toDateString() < $now->toDateString()) {
            $past['final_on'] = 'tanggal final '.self::label($final);
        }

        return $past;
    }

    /**
     * "Tanggal lomba ini sudah lewat (batas daftar 1 Sep 2026, tanggal final 2 Agu 2026). Tetap publikasikan?"
     *
     * @param  array<string, string>  $past  result of past()
     */
    public static function pastMessage(array $past): string
    {
        return 'Tanggal lomba ini sudah lewat ('.implode(', ', $past).'). Tetap publikasikan?';
    }

    private static function label(CarbonImmutable $date): string
    {
        return $date->locale('id')->translatedFormat('j M Y');
    }

    private static function moment(mixed $value, bool $endOfDayForDates = false): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        if (! $dateOnly && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat($dateOnly ? '!Y-m-d' : 'Y-m-d H:i:s', $value);
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable || $date->format($dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $dateOnly && $endOfDayForDates ? $date->endOfDay() : $date;
    }
}
