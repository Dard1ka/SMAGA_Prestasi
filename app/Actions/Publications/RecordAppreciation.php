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
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Puts ONE verified achievement (student with publish consent) on an upcoming appreciation event that is not
 * yet confirmed as held: one `appreciations` row, status `dijadwalkan`, unique per achievement + event.
 * Audited; the student and the guru pembimbing (D-110) are notified after commit.
 *
 * Since D-124 (lead, 2026-10-10) the work is done by RecordAppreciations (several achievements in one click,
 * one notice per guru): this is its one-row entry, used by the Publikasi panel "Masukkan ke acara apresiasi".
 * The rules, messages and notices of a single row are unchanged; the helpers below serve both Actions.
 *
 * One achievement, one open event (M2-B, QA 2026-10-05 Q2-10): an achievement that is still scheduled on
 * another event that is not confirmed, or that was already appreciated on a confirmed event, is refused with
 * a message that names that event. "Lepas dari acara" (ReleaseAppreciation) or "Hapus acara" frees it again.
 */
final class RecordAppreciation
{
    public function __construct(private readonly RecordAppreciations $many) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, AppreciationEvent $event, Achievement $achievement): Appreciation
    {
        /** @var Appreciation $appreciation */
        $appreciation = $this->many->handle($actor, $event, [$achievement])->first();

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
}
