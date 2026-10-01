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
 * Humas/Kesiswaan create an appreciation event, e.g. "Upacara Senin, 5 Oktober 2026" (name ≤ 200,
 * date today or later). Audited.
 */
final class CreateAppreciationEvent
{
    public const NAME_MAX = 200;

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, string $name, DateTimeInterface|string $heldOn): AppreciationEvent
    {
        Gate::forUser($actor)->authorize('create', AppreciationEvent::class);

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Isi nama acara.']);
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw ValidationException::withMessages(['name' => 'Nama acara maksimal 200 karakter.']);
        }

        $date = self::date($heldOn);

        if ($date->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['held_on' => 'Tanggal acara tidak boleh lewat.']);
        }

        return DB::transaction(function () use ($actor, $name, $date): AppreciationEvent {
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
            ]);

            activity('appreciation')
                ->performedOn($event)
                ->causedBy($actor)
                ->event('appreciation.event_created')
                ->withProperties(['name' => $name, 'held_on' => $date->toDateString()])
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
