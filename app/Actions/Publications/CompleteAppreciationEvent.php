<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Tandai sudah dilaksanakan" (M1-E, lead 2026-10-04): Humas/Kesiswaan confirm that the event took place.
 * Every `dijadwalkan` appreciation of it becomes `selesai` and the event itself is stamped
 * (`completed_at`, `completed_by`), which takes it out of the upcoming list and makes it a record that can
 * no longer be changed or deleted. Returns how many rows changed. Audited. Nobody is notified: the students
 * were told when their achievement was put on the event.
 */
final class CompleteAppreciationEvent
{
    public const BLOCK_DONE = 'done';

    public const BLOCK_NOT_YET = 'not_yet';

    public const BLOCK_EMPTY = 'empty';

    /** @var array<string, string> blocker => message of the ValidationException */
    public const MESSAGES = [
        self::BLOCK_DONE => 'Acara ini sudah ditandai dilaksanakan.',
        self::BLOCK_NOT_YET => 'Acara ini belum berlangsung.',
        self::BLOCK_EMPTY => 'Tidak ada apresiasi yang perlu ditandai.',
    ];

    /**
     * Why the event cannot be confirmed right now, or null when it can. The screen calls this to show the
     * reason instead of a dead button; handle() enforces the same rule under a lock.
     *
     * @param  int  $scheduled  number of `dijadwalkan` rows of the event
     */
    public static function blocker(AppreciationEvent $event, int $scheduled, ?CarbonImmutable $today = null): ?string
    {
        return match (true) {
            $event->isCompleted() => self::BLOCK_DONE,
            $event->held_on->greaterThan($today ?? CarbonImmutable::today()) => self::BLOCK_NOT_YET,
            $scheduled < 1 => self::BLOCK_EMPTY,
            default => null,
        };
    }

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, AppreciationEvent $event): int
    {
        Gate::forUser($actor)->authorize('update', $event);

        return DB::transaction(function () use ($actor, $event): int {
            /** @var AppreciationEvent $locked */
            $locked = AppreciationEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $pending = Appreciation::query()
                ->where('appreciation_event_id', $locked->getKey())
                ->where('status', AppreciationStatus::Dijadwalkan)
                ->lockForUpdate()
                ->get();

            $blocker = self::blocker($locked, $pending->count());

            if ($blocker !== null) {
                throw ValidationException::withMessages(['event' => self::MESSAGES[$blocker]]);
            }

            foreach ($pending as $appreciation) {
                $appreciation->forceFill(['status' => AppreciationStatus::Selesai])->save();
            }

            $locked->forceFill([
                'completed_at' => CarbonImmutable::now(),
                'completed_by' => $actor->getKey(),
            ])->save();

            activity('appreciation')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('appreciation.event_completed')
                ->withProperties([
                    'name' => $locked->name,
                    'held_on' => $locked->held_on->toDateString(),
                    'rows' => $pending->count(),
                    'appreciation_ids' => $pending->modelKeys(),
                ])
                ->log('appreciation.event_completed');

            return $pending->count();
        });
    }
}
