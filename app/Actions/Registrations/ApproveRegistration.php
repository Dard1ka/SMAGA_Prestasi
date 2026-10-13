<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Kepala Sekolah or Tata Usaha (D-150) approves one registration (D-05, UI-MAP #19): menunggu_kepsek → disetujui.
 * The team roster is locked from here on. Notifies the members, the guru pembimbing and Tata Usaha (queued, after
 * commit; never the approver itself). Bulk approve = one call per row, each authorized on its own.
 *
 * D-132 (lead, 2026-10-11): with the approval the Kepala Sekolah may put another guru in the place of the one the
 * student chose ($mentorId). It is one decision in one transaction, so no registration ever waits with a guru nobody
 * was told about: the members read the final guru in their approval notice (also when nothing changed) and the new
 * guru gets the approval notice as an appointment. The change is audited as `registration.mentor_changed` (user ids
 * only). After the approval nobody changes the guru: the letters name that person.
 *
 * D-134 (lead, same day): the guru who was replaced is told nothing. A new registration is told to the Kepala Sekolah
 * and the team only, so that account never heard that a student had chosen it; "guru pembimbing diganti" would be
 * the first and only thing it hears about this registration. It never gets the approval notice either.
 */
final class ApproveRegistration
{
    public const MAX_NOTE = 500;

    public const EVENT_MENTOR_CHANGED = 'registration.mentor_changed';

    public const MSG_MENTOR = 'Guru ini tidak bisa dipilih. Pilih guru lain.';

    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    /**
     * @param  int|string|null  $mentorId  D-132: the guru the Kepala Sekolah puts in the place of the student's choice;
     *                                     null, '' or the guru the registration already has = the choice stays
     *
     * @throws AuthorizationException when the actor may not decide (not the Kepala Sekolah or Tata Usaha)
     * @throws ValidationException when the registration was already decided or cancelled, or (key `mentor`) the
     *                             chosen account is not a guru who can log in
     */
    public function handle(User $actor, Registration $registration, ?string $note = null, int|string|null $mentorId = null): Registration
    {
        Gate::forUser($actor)->authorize('viewApprovalQueue', Registration::class);

        $note = trim((string) $note);

        if (mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 500 karakter.']);
        }

        /** @var array{changed: bool, replaced: ?User} $change what the transaction did to the guru */
        $change = ['changed' => false, 'replaced' => null];

        $locked = DB::transaction(function () use ($actor, $registration, $note, $mentorId, &$change): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            self::assertWaiting($locked);
            Gate::forUser($actor)->authorize('decide', $locked);
            $this->stateMachine->assertTransition($locked->status, RegistrationStatus::Disetujui);

            $newMentor = self::replacement($actor, $locked, $mentorId);
            $previousId = $locked->mentor_id !== null ? (int) $locked->mentor_id : null;

            $locked->forceFill([
                'status' => RegistrationStatus::Disetujui,
                'principal_decision_by' => $actor->getKey(),
                'principal_decided_at' => now(),
                'principal_note' => $note === '' ? null : $note,
            ]);

            if ($newMentor !== null) {
                $locked->forceFill(['mentor_id' => $newMentor->getKey()]);
            }

            $locked->save();

            if ($newMentor !== null) {
                $change = [
                    'changed' => true,
                    'replaced' => $previousId !== null ? User::query()->with('staffProfile')->find($previousId) : null,
                ];

                // User ids only: the audit log never carries names.
                activity('registration')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event(self::EVENT_MENTOR_CHANGED)
                    ->withProperties(['from' => $previousId, 'to' => (int) $newMentor->getKey()])
                    ->log(self::EVENT_MENTOR_CHANGED);
            }

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.approved')
                ->withProperties(['from' => RegistrationStatus::MenungguKepsek->value, 'to' => RegistrationStatus::Disetujui->value])
                ->log('registration.approved');

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);
        // A caller that had the old guru loaded must not go on showing that person.
        $registration->unsetRelation('mentor');

        $this->notify($actor, $locked, $change['changed'], $change['replaced']);

        return $registration;
    }

    /** Shared guard for approve and reject: only rows still waiting for the Kepala Sekolah. */
    public static function assertWaiting(Registration $registration): void
    {
        if ($registration->status !== RegistrationStatus::MenungguKepsek) {
            throw ValidationException::withMessages([
                'registration' => $registration->status === RegistrationStatus::Dibatalkan
                    ? 'Pendaftaran ini sudah dibatalkan siswa.'
                    : 'Pendaftaran ini sudah diputuskan.',
            ]);
        }
    }

    /**
     * Team members who confirmed (and did not decline) and can still sign in.
     *
     * @return Collection<int, User>
     */
    public static function memberUsers(Registration $registration): Collection
    {
        return $registration->members()
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->with('student.user')
            ->get()
            ->map(fn ($member) => $member->student?->user)
            ->filter(fn (?User $user): bool => $user !== null && $user->canLogIn())
            ->values();
    }

    /**
     * The guru pembimbing who hears about a decision or a cancel of this registration: an active account that still
     * holds the guru role, else null (QA10: the rule of RequestReportRevision::mentorUser(), D-110). A mentor moved to
     * another role (e.g. Humas) would get binaan details and a guru link that answers 403.
     */
    public static function mentorToTell(Registration $registration): ?User
    {
        return $registration->mentor_id === null
            ? null
            : User::query()->gurus()->canLogIn()->whereKey($registration->mentor_id)->first();
    }

    /**
     * Did the Kepala Sekolah put another guru in the place of the student's choice when approving (D-132)? Read from
     * the audit row of that decision: a screen says "ditetapkan Kepala Sekolah" only then.
     */
    public static function mentorWasReplaced(Registration $registration): bool
    {
        return $registration->principal_decided_at !== null
            && Activity::query()
                ->where('event', self::EVENT_MENTOR_CHANGED)
                ->where('subject_type', $registration->getMorphClass())
                ->where('subject_id', $registration->getKey())
                ->exists();
    }

    /**
     * The guru the Kepala Sekolah chose instead of the student's (D-132), or null when the guru stays: no id given,
     * or the id of the guru the registration already has.
     *
     * @throws AuthorizationException
     * @throws ValidationException key `mentor`
     */
    private static function replacement(User $actor, Registration $registration, int|string|null $mentorId): ?User
    {
        if ($mentorId === null || $mentorId === '') {
            return null;
        }

        Gate::forUser($actor)->authorize('changeMentor', $registration);

        $mentor = MentorChoice::find($mentorId);

        if ($mentor === null) {
            throw ValidationException::withMessages(['mentor' => self::MSG_MENTOR]);
        }

        return $registration->mentor_id !== null && (int) $registration->mentor_id === (int) $mentor->getKey() ? null : $mentor;
    }

    /**
     * @param  bool  $changed  D-132: the Kepala Sekolah replaced the guru with this approval
     * @param  User|null  $replaced  the guru who was replaced (null: the guru stayed, or there was none before); only
     *                               the name is used, in the notices of the students and the new guru: that account
     *                               itself is told nothing (D-134)
     */
    private function notify(User $actor, Registration $registration, bool $changed, ?User $replaced): void
    {
        $members = self::memberUsers($registration);
        $replacedName = MentorChoice::name($replaced);

        if ($members->isNotEmpty()) {
            Notification::send($members, RegistrationApproved::for($registration, 'siswa', $changed, $replacedName));
        }

        // The guru the registration has NOW: after a change the new one, never the one who was replaced, and never
        // the actor (a Kepala Sekolah who is also a guru is not told about a decision they just made, as in
        // RejectRegistration).
        $mentor = self::mentorToTell($registration);

        if ($mentor !== null && ! $mentor->is($actor)) {
            $mentor->notify(RegistrationApproved::for($registration, 'guru', $changed, $replacedName));
        }

        // Tata Usaha makes the letters; a Tata Usaha account that approved itself (D-150) is not told about its own
        // decision, its colleagues are.
        $tu = User::query()->role(RoleName::TataUsaha->value)->canLogIn()->get()
            ->reject(fn (User $user): bool => $user->is($actor))
            ->values();

        if ($tu->isNotEmpty()) {
            Notification::send($tu, RegistrationApproved::for($registration, 'tu'));
        }
    }
}
