<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Hapus acara" (M1-E, lead 2026-10-04): removes an appreciation event that was created by mistake or was
 * cancelled. Only while the event is not confirmed as held; a confirmed event, or one that already has a
 * `selesai` row, is the record and stays. The scheduled rows are deleted, which releases the achievements
 * (they can go on another event). Every student who had a row and can log in was told "Kamu masuk daftar
 * apresiasi" and is now told the event is off (one notice per student, after commit); so is each guru pembimbing,
 * once for all their binaan on the event (D-110). Audited: actor, time,
 * event name and date, number of rows. Returns the number of released rows.
 */
final class DeleteAppreciationEvent
{
    public const COMPLETED_MESSAGE = 'Acara yang sudah dilaksanakan tidak bisa dihapus.';

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, AppreciationEvent $event): int
    {
        $gate = Gate::forUser($actor);
        // Permission first (403 for other roles); the state is answered below with a message.
        $gate->authorize('update', $event);

        /** @var array{0: int, 1: list<array{0: User, 1: AppreciationCancelled|BinaanAppreciationNotice}>} $result */
        $result = DB::transaction(function () use ($actor, $event, $gate): array {
            /** @var AppreciationEvent $locked */
            $locked = AppreciationEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCompleted()) {
                throw ValidationException::withMessages(['event' => self::COMPLETED_MESSAGE]);
            }

            $gate->authorize('delete', $locked);

            $rows = Appreciation::query()
                ->where('appreciation_event_id', $locked->getKey())
                ->with(['achievement.student.user', 'achievement.competition', 'achievement.report'])
                ->lockForUpdate()
                ->get();

            // Rows confirmed with the old button on an event that was filled again afterwards.
            if ($rows->contains(fn (Appreciation $a): bool => $a->status === AppreciationStatus::Selesai)) {
                throw ValidationException::withMessages(['event' => self::COMPLETED_MESSAGE]);
            }

            $notices = self::notices($locked, $rows);
            $mentorNotices = self::mentorNotices($locked, $rows);

            if ($rows->isNotEmpty()) {
                Appreciation::query()->whereKey($rows->modelKeys())->delete();
            }

            activity('appreciation')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('appreciation.event_deleted')
                ->withProperties([
                    'name' => $locked->name,
                    'held_on' => $locked->held_on->toDateString(),
                    'held_time' => $locked->heldTime(),
                    'rows' => $rows->count(),
                    'achievement_ids' => $rows->pluck('achievement_id')->values()->all(),
                    'notified' => count($notices),
                ])
                ->log('appreciation.event_deleted');

            $locked->delete();

            return [$rows->count(), [...$notices, ...$mentorNotices]];
        });

        foreach ($result[1] as [$user, $notification]) {
            $user->notify($notification);
        }

        return $result[0];
    }

    /** How many students a delete would tell now, for the toast (QA8). */
    public static function studentsToTell(AppreciationEvent $event): int
    {
        return self::toldRows($event->appreciations()->with('achievement.student.user')->get())
            ->map(fn (Appreciation $a): string => (string) $a->achievement->student->user->getKey())
            ->unique()
            ->count();
    }

    /**
     * Rows whose student can log in: only they are told.
     *
     * @param  Collection<int, Appreciation>  $rows
     * @return Collection<int, Appreciation>
     */
    private static function toldRows(Collection $rows): Collection
    {
        return $rows->filter(fn (Appreciation $a): bool => $a->achievement?->student?->user?->canLogIn() === true);
    }

    /**
     * One notice per student who can log in (the rule RecordAppreciation used when it told them).
     *
     * @param  Collection<int, Appreciation>  $rows
     * @return list<array{0: User, 1: AppreciationCancelled}>
     */
    private static function notices(AppreciationEvent $event, Collection $rows): array
    {
        $notices = [];

        $perUser = self::toldRows($rows)
            ->groupBy(fn (Appreciation $a): string => (string) $a->achievement->student->user->getKey());

        foreach ($perUser as $group) {
            /** @var User $user */
            $user = $group->first()->achievement->student->user;
            $notices[] = [$user, AppreciationCancelled::for($event, $group)];
        }

        return $notices;
    }

    /**
     * One notice per guru pembimbing who can log in, listing every binaan row on the event (D-110).
     *
     * @param  Collection<int, Appreciation>  $rows
     * @return list<array{0: User, 1: BinaanAppreciationNotice}>
     */
    private static function mentorNotices(AppreciationEvent $event, Collection $rows): array
    {
        $notices = [];
        $mentors = [];
        $perMentor = [];
        $mentorOf = RecordAppreciation::mentorsFor($rows->map(fn (Appreciation $row): ?Achievement => $row->achievement)->filter());

        foreach ($rows as $row) {
            $mentor = $mentorOf[(string) $row->achievement_id] ?? null;

            if ($mentor !== null) {
                $mentors[$mentor->getKey()] = $mentor;
                $perMentor[$mentor->getKey()][] = $row;
            }
        }

        foreach ($perMentor as $id => $group) {
            $notices[] = [$mentors[$id], BinaanAppreciationNotice::cancelled($event, $group)];
        }

        return $notices;
    }
}
