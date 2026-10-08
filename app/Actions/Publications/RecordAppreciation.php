<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Puts a verified achievement (student with publish consent) on an upcoming appreciation event that is not
 * yet confirmed as held: one `appreciations` row, status `dijadwalkan`, unique per achievement + event.
 * Audited; the student and the guru pembimbing (D-110) are notified after commit.
 *
 * One achievement, one open event (M2-B, QA 2026-10-05 Q2-10): an achievement that is still scheduled on
 * another event that is not confirmed, or that was already appreciated on a confirmed event, is refused with
 * a message that names that event. "Lepas dari acara" (ReleaseAppreciation) or "Hapus acara" frees it again.
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

                // One achievement, one open event (M2-B, Q2-10). The achievement row is locked so two events
                // cannot take it at the same moment; every row it already has decides the answer.
                Achievement::query()->whereKey($achievement->getKey())->lockForUpdate()->first();

                $existing = Appreciation::query()
                    ->where('achievement_id', $achievement->getKey())
                    ->with('event')
                    ->get();

                if ($existing->contains(fn (Appreciation $a): bool => $a->appreciation_event_id === $lockedEvent->getKey())) {
                    throw ValidationException::withMessages(['achievement' => 'Prestasi ini sudah ada di daftar acara tersebut.']);
                }

                if (($blocking = self::blocking($existing)) !== null) {
                    throw ValidationException::withMessages(['achievement' => self::blockedMessage($blocking)]);
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

    /**
     * The row that keeps an achievement off other events: one that is already appreciated (a `selesai` row or
     * a confirmed event) before one that is still scheduled on an event not yet confirmed. Null = free.
     *
     * @param  Collection<int, Appreciation>  $existing  rows of one achievement, `event` loaded
     */
    public static function blocking(Collection $existing): ?Appreciation
    {
        return $existing->first(fn (Appreciation $a): bool => self::isAppreciated($a)) ?? $existing->first();
    }

    /** "… sudah diapresiasi di acara X (5 Okt 2026)." / "… sudah dijadwalkan di acara X (5 Okt 2026). Lepas dulu …" */
    public static function blockedMessage(Appreciation $blocking): string
    {
        $event = $blocking->event;
        $where = $event === null
            ? 'acara lain'
            : 'acara '.$event->name.' ('.$event->held_on->locale('id')->translatedFormat('j M Y').')';

        return self::isAppreciated($blocking)
            ? 'Prestasi ini sudah diapresiasi di '.$where.'.'
            : 'Prestasi ini sudah dijadwalkan di '.$where.'. Lepas dulu dari acara itu.';
    }

    private static function isAppreciated(Appreciation $appreciation): bool
    {
        return $appreciation->status === AppreciationStatus::Selesai || $appreciation->event?->isCompleted() === true;
    }

    /** The guru pembimbing who hears about a binaan's appreciation (D-110), or null; see mentorsFor(). */
    public static function mentorUser(Achievement $achievement): ?User
    {
        return self::mentorsFor([$achievement])[(string) $achievement->getKey()] ?? null;
    }

    /**
     * The guru pembimbing per achievement (D-110): the mentor of the achievement's registration while that account
     * can log in and still holds the guru role. Three queries for any number of achievements, so deleting a big event
     * does not look them up row by row while it holds its locks.
     *
     * @param  iterable<Achievement>  $achievements
     * @return array<string, User> achievement id => guru (achievements without one are left out)
     */
    public static function mentorsFor(iterable $achievements): array
    {
        $reportOf = [];

        foreach ($achievements as $achievement) {
            if ($achievement->report_id !== null) {
                $reportOf[(string) $achievement->getKey()] = (string) $achievement->report_id;
            }
        }

        if ($reportOf === []) {
            return [];
        }

        $registrationOf = Report::withTrashed()->whereKey(array_values(array_unique($reportOf)))->pluck('registration_id', 'id')->all();
        $mentorOf = Registration::withTrashed()->whereKey(array_values(array_unique($registrationOf)))
            ->whereNotNull('mentor_id')->pluck('mentor_id', 'id')->all();
        $gurus = $mentorOf === [] ? [] : User::query()->gurus()->canLogIn()->whereKey(array_values(array_unique($mentorOf)))
            ->get()->keyBy(fn (User $user): string => (string) $user->getKey())->all();

        $result = [];

        foreach ($reportOf as $achievementId => $reportId) {
            $registrationId = $registrationOf[$reportId] ?? null;
            $mentorId = $registrationId !== null ? ($mentorOf[$registrationId] ?? null) : null;

            if ($mentorId !== null && isset($gurus[(string) $mentorId])) {
                $result[$achievementId] = $gurus[(string) $mentorId];
            }
        }

        return $result;
    }

    /** Is the student told when this achievement is scheduled? Not an alumnus or a disabled account (QA8: the toast asks). */
    public static function notifiesStudent(Achievement $achievement): bool
    {
        $achievement->loadMissing('student.user');

        return $achievement->student?->user?->canLogIn() === true;
    }

    private function notify(Appreciation $appreciation, Achievement $achievement): void
    {
        $achievement->loadMissing(['student.user', 'competition', 'report']);
        $appreciation->setRelation('achievement', $achievement);
        $appreciation->loadMissing('event');

        if (self::notifiesStudent($achievement)) {
            $achievement->student->user->notify(AppreciationScheduled::for($appreciation));
        }

        self::mentorUser($achievement)?->notify(BinaanAppreciationNotice::scheduled($appreciation));
    }
}
