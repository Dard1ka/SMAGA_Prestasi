<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Humas/Kesiswaan create an appreciation event, e.g. "Upacara Senin" (name ≤ 200, date today or later, a start
 * time). Audited.
 *
 * The start time is required (D-123, lead 2026-10-10): the notice is the only place a student or a guru
 * pembimbing learns when to show up. The date rule stays day-level: today is fine, and so is a time of today that
 * already passed (this morning's upacara is recorded with its true time). Name + date make an event a duplicate.
 */
final class CreateAppreciationEvent
{
    public const NAME_MAX = 200;

    /**
     * @param  string  $heldTime  "07:00" (or "07:00:00"), app timezone
     *
     * @throws ValidationException keys `name`, `held_on`, `held_time`
     */
    public function handle(User $actor, string $name, DateTimeInterface|string $heldOn, string $heldTime): AppreciationEvent
    {
        Gate::forUser($actor)->authorize('create', AppreciationEvent::class);

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        // Name, date and time are checked together, so an empty form names every field at once (QA5 #65).
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Isi nama acara.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'Nama acara maksimal 200 karakter.';
        }

        try {
            $date = self::date($heldOn);

            if ($date->lessThan(CarbonImmutable::today())) {
                $errors['held_on'] = 'Tanggal acara tidak boleh lewat.';
            }
        } catch (ValidationException $e) {
            $errors['held_on'] = (string) ($e->errors()['held_on'][0] ?? 'Isi tanggal acara.');
        }

        try {
            $time = EventWhen::parseTime($heldTime);
        } catch (ValidationException $e) {
            $errors['held_time'] = (string) ($e->errors()['held_time'][0] ?? EventWhen::TIME_MESSAGE);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $name, $date, $time): AppreciationEvent {
            $exists = AppreciationEvent::query()
                ->where('name', $name)
                ->whereDate('held_on', $date->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['name' => 'Acara ini sudah ada.']);
            }

            $event = AppreciationEvent::query()->create([
                'name' => $name,
                'held_on' => $date->toDateString(),
                'held_time' => $time,
            ]);

            activity('appreciation')
                ->performedOn($event)
                ->causedBy($actor)
                ->event('appreciation.event_created')
                ->withProperties(['name' => $name, 'held_on' => $date->toDateString(), 'held_time' => $event->heldTime()])
                ->log('appreciation.event_created');

            return $event;
        });
    }

    private static function date(DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone((string) config('app.timezone'))->startOfDay();
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), (string) config('app.timezone'));
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== trim($value)) {
            throw ValidationException::withMessages(['held_on' => 'Isi tanggal acara.']);
        }

        return $date;
    }
}
