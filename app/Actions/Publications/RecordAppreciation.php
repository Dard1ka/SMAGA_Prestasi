<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use App\Notifications\Publications\AppreciationScheduled;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Puts a verified achievement (student with publish consent) on an upcoming appreciation event that is not
 * yet confirmed as held: one `appreciations` row, status `dijadwalkan`, unique per achievement + event.
 * Audited; the student is notified after commit.
 */
final class RecordAppreciation
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, AppreciationEvent $event, Achievement $achievement): Appreciation
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('create', Appreciation::class);
        $gate->authorize('update', $event);

        $achievement->loadMissing('student');

        if ($achievement->verified_at === null) {
            throw ValidationException::withMessages(['achievement' => 'Prestasi ini belum terverifikasi.']);
        }

        if ($achievement->student?->publish_consent !== true) {
            throw ValidationException::withMessages(['achievement' => 'Siswa belum memberi izin publikasi.']);
        }

        try {
            $appreciation = DB::transaction(function () use ($actor, $event, $achievement): Appreciation {
                /** @var AppreciationEvent $lockedEvent */
                $lockedEvent = AppreciationEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

                // A confirmed event is the record of who was appreciated (M1-E): nothing is added afterwards.
                if ($lockedEvent->isCompleted()) {
                    throw ValidationException::withMessages(['event' => 'Acara ini sudah dilaksanakan. Pilih acara lain.']);
                }

                if ($lockedEvent->held_on->lessThan(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['event' => 'Acara apresiasi ini sudah lewat.']);
                }

                $duplicate = Appreciation::query()
                    ->where('appreciation_event_id', $lockedEvent->getKey())
                    ->where('achievement_id', $achievement->getKey())
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['achievement' => 'Prestasi ini sudah ada di daftar acara tersebut.']);
                }

                $appreciation = new Appreciation([
                    'achievement_id' => $achievement->getKey(),
                    'appreciation_event_id' => $lockedEvent->getKey(),
                ]);
                $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

                activity('appreciation')
                    ->performedOn($appreciation)
                    ->causedBy($actor)
                    ->event('appreciation.recorded')
                    ->withProperties([
                        'achievement_id' => $achievement->getKey(),
                        'appreciation_event_id' => $lockedEvent->getKey(),
                        'held_on' => $lockedEvent->held_on->toDateString(),
                    ])
                    ->log('appreciation.recorded');

                return $appreciation->setRelation('event', $lockedEvent);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['achievement' => 'Prestasi ini sudah ada di daftar acara tersebut.']);
        }

        $this->notify($appreciation, $achievement);

        return $appreciation;
    }

    private function notify(Appreciation $appreciation, Achievement $achievement): void
    {
        $achievement->loadMissing(['student.user', 'competition']);
        $appreciation->setRelation('achievement', $achievement);
        $user = $achievement->student?->user;

        if ($user !== null && $user->canLogIn()) {
            $user->notify(AppreciationScheduled::for($appreciation));
        }
    }
}
