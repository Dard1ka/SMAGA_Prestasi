<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use App\Notifications\Publications\AppreciationReleased;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Lepas dari acara" (M2-B, QA 2026-10-05 Q2-10): takes one scheduled achievement off an appreciation event
 * while the event is not confirmed as held, e.g. it was put on the wrong event. The row is deleted, which
 * releases the achievement (RecordAppreciation accepts it on another event again); the event itself stays.
 * A confirmed event, or a row that is already `selesai`, is the record and stays (same rule as
 * DeleteAppreciationEvent). The student was told "Kamu masuk daftar apresiasi" when the row was made and is
 * now told it is off, by the rule RecordAppreciation used (an account that can log in), after commit.
 * Audited: actor, event, achievement id; no names.
 */
final class ReleaseAppreciation
{
    public const COMPLETED_MESSAGE = 'Prestasi di acara yang sudah dilaksanakan tidak bisa dilepas.';

    public const GONE_MESSAGE = 'Prestasi ini sudah tidak ada di acara tersebut.';

    /**
     * @return bool true when the student was told
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Appreciation $appreciation): bool
    {
        $gate = Gate::forUser($actor);
        // Permission first (403 for other roles); the state is answered below with a message.
        $gate->authorize('update', $appreciation);

        /** @var array{0: User, 1: AppreciationReleased}|null $notice */
        $notice = DB::transaction(function () use ($actor, $appreciation, $gate): ?array {
            // Same lock order as RecordAppreciation and DeleteAppreciationEvent: the event, then its rows.
            /** @var AppreciationEvent|null $event */
            $event = AppreciationEvent::query()->whereKey($appreciation->appreciation_event_id)->lockForUpdate()->first();

            /** @var Appreciation|null $locked */
            $locked = $event === null ? null : Appreciation::query()
                ->whereKey($appreciation->getKey())
                ->where('appreciation_event_id', $event->getKey())
                ->with(['achievement.student.user', 'achievement.competition'])
                ->lockForUpdate()
                ->first();

            if ($event === null || $locked === null) {
                throw ValidationException::withMessages(['appreciation' => self::GONE_MESSAGE]);
            }

            if ($event->isCompleted() || $locked->status === AppreciationStatus::Selesai) {
                throw ValidationException::withMessages(['appreciation' => self::COMPLETED_MESSAGE]);
            }

            $locked->setRelation('event', $event);
            $gate->authorize('delete', $locked);

            $user = $locked->achievement?->student?->user;
            $notice = $user !== null && $user->canLogIn() ? [$user, AppreciationReleased::for($event, $locked)] : null;

            $locked->delete();

            activity('appreciation')
                ->performedOn($event)
                ->causedBy($actor)
                ->event('appreciation.released')
                ->withProperties([
                    'appreciation_id' => $locked->getKey(),
                    'achievement_id' => $locked->achievement_id,
                    'name' => $event->name,
                    'held_on' => $event->held_on->toDateString(),
                    'notified' => $notice !== null,
                ])
                ->log('appreciation.released');

            return $notice;
        });

        if ($notice !== null) {
            $notice[0]->notify($notice[1]);
        }

        return $notice !== null;
    }
}
