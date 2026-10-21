<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * One "Masukkan" on Apresiasi › Tambah prestasi (D-124, lead 2026-10-10): puts every ticked achievement on an
 * upcoming appreciation event that is not yet confirmed as held. One `appreciations` row (status `dijadwalkan`)
 * and one audit entry per achievement, all in one transaction: all or nothing, so a row that may not go on the
 * event stops the whole click and nobody is told about half a list.
 *
 * The rules per achievement are the ones RecordAppreciation always had: verified, the student may be named
 * (NamedStudent: not deleted, account not disabled; the consent check is gone since D-162), not on this event yet,
 * and on no other event (one achievement, one open event: M2-B, Q2-10).
 *
 * After commit each student who can log in gets AppreciationScheduled, one per achievement as before. The guru
 * pembimbing gets ONE BinaanAppreciationNotice per call that names every binaan of this click (the hand test of
 * 2026-10-09: a team of two was two clicks and two identical-looking mails). Two calls are two notices; that is
 * also what the one-row RecordAppreciation (Publikasi panel) does, which delegates here.
 */
final class RecordAppreciations
{
    /** Achievements per call: keeps the transaction, its locks and the notices of one click bounded. */
    public const MAX = 30;

    public const EMPTY_MESSAGE = 'Pilih minimal satu prestasi.';

    public const MAX_MESSAGE = 'Maksimal 30 prestasi sekali masuk.';

    public const DUPLICATE_MESSAGE = 'Prestasi ini sudah ada di daftar acara tersebut.';

    /** A student NamedStudent leaves out (disabled or deleted account) is never put on an event (D-162). */
    public const INACTIVE_MESSAGE = 'Akun siswa ini nonaktif, jadi prestasinya tidak bisa dimasukkan ke acara apresiasi.';

    /**
     * @param  iterable<Achievement>  $achievements  the same achievement named twice counts once
     * @return Collection<int, Appreciation> the new rows, in the order given, with `event` and `achievement` set
     *
     * @throws ValidationException key `achievement` (the list, or a row that may not go on the event) or `event`
     */
    public function handle(User $actor, AppreciationEvent $event, iterable $achievements): Collection
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('create', Appreciation::class);
        $gate->authorize('update', $event);

        $achievements = self::unique($achievements);

        if ($achievements->isEmpty()) {
            throw ValidationException::withMessages(['achievement' => self::EMPTY_MESSAGE]);
        }

        if ($achievements->count() > self::MAX) {
            throw ValidationException::withMessages(['achievement' => self::MAX_MESSAGE]);
        }

        $achievements->loadMissing('student.user');

        foreach ($achievements as $achievement) {
            if ($achievement->verified_at === null) {
                throw ValidationException::withMessages(['achievement' => 'Prestasi ini belum terverifikasi.']);
            }

            if (! NamedStudent::allows($achievement->student)) {
                throw ValidationException::withMessages(['achievement' => self::INACTIVE_MESSAGE]);
            }
        }

        try {
            /** @var array{0: AppreciationEvent, 1: Collection<int, Appreciation>} $result */
            $result = DB::transaction(function () use ($actor, $event, $achievements): array {
                /** @var AppreciationEvent $lockedEvent */
                $lockedEvent = AppreciationEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

                // A confirmed event is the record of who was appreciated (M1-E): nothing is added afterwards.
                if ($lockedEvent->isCompleted()) {
                    throw ValidationException::withMessages(['event' => 'Acara ini sudah dilaksanakan. Pilih acara lain.']);
                }

                // Day-level (D-87): the whole day of the event still takes achievements, whatever its start time.
                if ($lockedEvent->held_on->lessThan(CarbonImmutable::today())) {
                    throw ValidationException::withMessages(['event' => 'Acara apresiasi ini sudah lewat.']);
                }

                // One achievement, one open event (M2-B, Q2-10). The achievement rows are locked so two events
                // cannot take one at the same moment, in id order so two batches that overlap never wait on each
                // other in a circle; every row an achievement already has decides the answer.
                $ids = $achievements->modelKeys();
                Achievement::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id');

                $existing = Appreciation::query()
                    ->whereIn('achievement_id', $ids)
                    ->with('event')
                    ->get()
                    ->groupBy('achievement_id');

                foreach ($achievements as $achievement) {
                    /** @var Collection<int, Appreciation> $own */
                    $own = $existing->get($achievement->getKey()) ?? new Collection;

                    if ($own->contains(fn (Appreciation $a): bool => $a->appreciation_event_id === $lockedEvent->getKey())) {
                        throw ValidationException::withMessages(['achievement' => self::DUPLICATE_MESSAGE]);
                    }

                    if (($blocking = RecordAppreciation::blocking($own)) !== null) {
                        throw ValidationException::withMessages(['achievement' => RecordAppreciation::blockedMessage($blocking)]);
                    }
                }

                $rows = new Collection;

                foreach ($achievements as $achievement) {
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
                            'held_time' => $lockedEvent->heldTime(),
                        ])
                        ->log('appreciation.recorded');

                    $rows->push($appreciation->setRelation('event', $lockedEvent));
                }

                return [$lockedEvent, $rows];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['achievement' => self::DUPLICATE_MESSAGE]);
        }

        [$lockedEvent, $rows] = $result;

        $this->notify($lockedEvent, $rows, $achievements);

        return $rows;
    }

    /**
     * How many students hear about these achievements (an alumnus or a disabled account does not), for the toast:
     * it may only claim a notice somebody got (QA8). A student with two achievements counts once.
     *
     * @param  iterable<Achievement>  $achievements
     */
    public static function toldStudents(iterable $achievements): int
    {
        $achievements = self::unique($achievements);
        $achievements->loadMissing('student.user');
        $told = [];

        foreach ($achievements as $achievement) {
            if (RecordAppreciation::notifiesStudent($achievement)) {
                $told[(string) $achievement->student->user->getKey()] = true;
            }
        }

        return count($told);
    }

    /**
     * @param  iterable<Achievement>  $achievements
     * @return Collection<int, Achievement> one model per achievement id, in the order given
     */
    private static function unique(iterable $achievements): Collection
    {
        $unique = [];

        foreach ($achievements as $achievement) {
            $unique[(string) $achievement->getKey()] ??= $achievement;
        }

        return new Collection(array_values($unique));
    }

    /**
     * @param  Collection<int, Appreciation>  $rows
     * @param  Collection<int, Achievement>  $achievements
     */
    private function notify(AppreciationEvent $event, Collection $rows, Collection $achievements): void
    {
        $achievements->loadMissing(['student.user', 'competition', 'report']);
        $byId = $achievements->keyBy(fn (Achievement $achievement): string => (string) $achievement->getKey());

        foreach ($rows as $row) {
            /** @var Achievement $achievement */
            $achievement = $byId[(string) $row->achievement_id];
            $row->setRelation('achievement', $achievement);

            if (RecordAppreciation::notifiesStudent($achievement)) {
                $achievement->student->user->notify(AppreciationScheduled::for($row));
            }
        }

        // One notice per guru pembimbing for all binaan of this call (D-124), grouped the way
        // DeleteAppreciationEvent groups the "acara dibatalkan" notice.
        $mentorOf = RecordAppreciation::mentorsFor($achievements);
        $mentors = [];
        $perMentor = [];

        foreach ($rows as $row) {
            $mentor = $mentorOf[(string) $row->achievement_id] ?? null;

            if ($mentor !== null) {
                $mentors[$mentor->getKey()] = $mentor;
                $perMentor[$mentor->getKey()][] = $row;
            }
        }

        foreach ($perMentor as $id => $group) {
            $mentors[$id]->notify(BinaanAppreciationNotice::scheduledMany($event, $group));
        }
    }
}
