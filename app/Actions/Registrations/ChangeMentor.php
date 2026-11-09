<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Registrations\MentorAssigned;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * SV8, D-198 (the lead, item 4 "Keduanya"): the student still chooses the guru pembimbing, and Kesiswaan (or Admin,
 * `registration.mentor`) may fill it in or put another guru in its place on Kelola lomba, apart from the approval
 * (the approver's own "Ganti guru" with the approval, D-132 / D-150, is unchanged). Allowed from the registration
 * until the laporan hasil is sent: STATUSES = RevisionRules::BEFORE_APPROVAL + AFTER_APPROVAL.
 *
 * Fixed 2026-11-09: an issued letter no longer blocks the change (Tata Usaha has no separate "cabut" step: a Koreksi
 * revokes and issues the next version at once, built from the registration's data of that moment). The letters are
 * never touched (D-108); as with an approved revision (ApproveRevision, D-199) Tata Usaha is told to make a Koreksi when
 * a GURU letter that is not revoked existed at the change: only that letter prints the guru pembimbing (name, NIP,
 * pangkat, jabatan), the student letter does not. That is read under the registration lock, which IssueLetters takes
 * too, so a letter issued at the same moment is seen. A guru letter needs a guru, so it only exists when the
 * registration already had one: Tata Usaha is told about a replaced guru, never about one filled in.
 *
 * Who is told (the rules of the approval's mentor change, D-132 / D-134): the confirmed members who can log in read who
 * their guru is now; the new guru is told as an appointment, but only for a registration a guru may know of
 * (Registration::isKnownToMentor(): not while it waits for its lomba, D-148); Tata Usaha (every account that can log
 * in) when a guru letter existed; the guru who was replaced is told nothing; nobody is told about their own action.
 * Audit `registration.mentor_changed` with user ids only, `source` = SOURCE and `guru_letter_issued`
 * (ApproveRegistration::mentorWasReplaced() reads only the approval's rows).
 */
final class ChangeMentor
{
    /** From the registration until the report is sent: the statuses of RevisionRules (laporan_dikirim is MSG_REPORTED). */
    public const STATUSES = [...RevisionRules::BEFORE_APPROVAL, ...RevisionRules::AFTER_APPROVAL];

    /** `properties.source` of the audit row: the change made on Kelola lomba, not with an approval. */
    public const SOURCE = 'kesiswaan';

    /** The label of that audit row on Admin › Log audit (the approval's row keeps "… saat persetujuan"). */
    public const AUDIT_LABEL = 'Guru pembimbing diganti di Kelola lomba';

    public const MSG_REPORTED = 'Laporan hasil pendaftaran ini sudah dikirim, jadi guru pembimbing tidak bisa diganti lagi.';

    public const MSG_CANCELLED = 'Pendaftaran ini sudah dibatalkan.';

    public const MSG_REJECTED = 'Pendaftaran ini tidak disetujui.';

    public const MSG_FINISHED = 'Pendaftaran ini sudah selesai.';

    /** The ketua went to the trash of Admin › Kelas: the registration is in no staff list (Registration::hasListedLeader()). */
    public const MSG_NOT_LISTED = 'Pendaftaran ini sudah tidak ada di daftar.';

    public const MSG_CHOOSE = 'Pilih guru pembimbing.';

    public const MSG_SAME = 'Guru ini sudah menjadi guru pembimbing pendaftaran ini. Pilih guru lain.';

    /** The line of the dialog and of the toast when Tata Usaha is told (a guru letter exists). */
    public const TU_TOLD = 'Tata Usaha diberi tahu untuk membuat Koreksi surat tugas.';

    /**
     * @throws AuthorizationException when the actor may not set a guru (no `registration.mentor`)
     * @throws ValidationException key `registration` (problem(): the report sent, a final status, the ketua in the
     *                             trash) or key `mentor` (no choice, not a guru who can log in, the guru it already has)
     */
    public function handle(User $actor, Registration $registration, int|string|null $mentorId): Registration
    {
        Gate::forUser($actor)->authorize('manageMentors', Registration::class);

        /** @var array{replaced: ?User, guruLetter: bool} $change */
        $change = ['replaced' => null, 'guruLetter' => false];

        $locked = DB::transaction(function () use ($actor, $registration, $mentorId, &$change): Registration {
            // The lock IssueLetters takes too: a letter issued at the same moment is either seen here or waits for this one.
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            $problem = self::problem($locked);

            if ($problem !== null) {
                throw ValidationException::withMessages(['registration' => $problem]);
            }

            Gate::forUser($actor)->authorize('assignMentor', $locked);

            if ($mentorId === null || trim((string) $mentorId) === '') {
                throw ValidationException::withMessages(['mentor' => self::MSG_CHOOSE]);
            }

            $mentor = MentorChoice::find($mentorId);

            if ($mentor === null) {
                throw ValidationException::withMessages(['mentor' => ApproveRegistration::MSG_MENTOR]);
            }

            $previousId = $locked->mentor_id !== null ? (int) $locked->mentor_id : null;

            if ($previousId === (int) $mentor->getKey()) {
                throw ValidationException::withMessages(['mentor' => self::MSG_SAME]);
            }

            // The letters stay as they are (D-108): Tata Usaha corrects the guru letter, the only one that prints the guru,
            // with a Koreksi. The student letter names no guru, so it alone asks for nothing.
            $guruLetter = $locked->guruLetter()->exists();

            $locked->forceFill(['mentor_id' => $mentor->getKey()])->save();

            // User ids only: the audit log never carries names (D-134).
            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(ApproveRegistration::EVENT_MENTOR_CHANGED)
                ->withProperties(['from' => $previousId, 'to' => (int) $mentor->getKey(), 'source' => self::SOURCE, 'guru_letter_issued' => $guruLetter])
                ->log(ApproveRegistration::EVENT_MENTOR_CHANGED);

            $change = [
                'replaced' => $previousId !== null ? User::query()->with('staffProfile')->find($previousId) : null,
                'guruLetter' => $guruLetter,
            ];

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);
        // A caller that had the old guru loaded must not go on showing that person.
        $registration->unsetRelation('mentor');

        $this->notify($actor, $locked, MentorChoice::name($change['replaced']), $change['guruLetter']);

        return $registration;
    }

    /** Why the guru of this registration cannot be set now, or null when it can. An issued letter is no reason (2026-11-09). */
    public static function problem(Registration $registration): ?string
    {
        $problem = match ($registration->status) {
            RegistrationStatus::Dibatalkan => self::MSG_CANCELLED,
            RegistrationStatus::Ditolak => self::MSG_REJECTED,
            RegistrationStatus::Selesai => self::MSG_FINISHED,
            RegistrationStatus::LaporanDikirim => self::MSG_REPORTED,
            default => in_array($registration->status, self::STATUSES, true) ? null : self::MSG_REPORTED,
        };

        if ($problem !== null) {
            return $problem;
        }

        return $registration->hasListedLeader() ? null : self::MSG_NOT_LISTED;
    }

    /**
     * The guru who is told about the appointment: the new guru of a registration a guru may know of (D-148), when that
     * account can log in as a guru and is not the actor; null otherwise.
     */
    public static function mentorToTell(Registration $registration, ?User $actor = null): ?User
    {
        if (! $registration->isKnownToMentor()) {
            return null;
        }

        $mentor = ApproveRegistration::mentorToTell($registration);

        return $mentor !== null && ($actor === null || ! $mentor->is($actor)) ? $mentor : null;
    }

    /**
     * The Tata Usaha accounts told to make a Koreksi when a guru letter existed at the change: every one that can log
     * in, not the actor.
     *
     * @return Collection<int, User>
     */
    public static function tataUsahaToTell(?User $actor = null): Collection
    {
        return User::query()->role(RoleName::TataUsaha->value)->canLogIn()->get()
            ->reject(fn (User $user): bool => $actor !== null && $user->is($actor))
            ->values();
    }

    /**
     * Did the latest guru change of this registration, made on Kelola lomba, find an issued guru letter (its audit
     * row's `guru_letter_issued`)? What the Action wrote, for the toast after it (D-109).
     */
    public static function guruLetterIssuedAtLastChange(Registration $registration): bool
    {
        $latest = self::latestChange($registration);

        return $latest !== null
            && ($latest->properties['source'] ?? null) === self::SOURCE
            && ($latest->properties['guru_letter_issued'] ?? false) === true;
    }

    /**
     * Where the latest guru change of this registration came from: its audit row's `source` (SOURCE,
     * ChangeRegistration::MENTOR_SOURCE, ApproveRevision::MENTOR_SOURCE); null for the approval's own change or when
     * the guru never changed.
     */
    public static function latestSource(Registration $registration): ?string
    {
        $source = self::latestChange($registration)?->properties['source'] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }

    private static function latestChange(Registration $registration): ?Activity
    {
        return Activity::query()
            ->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)
            ->where('subject_type', $registration->getMorphClass())
            ->where('subject_id', $registration->getKey())
            ->latest('id')
            ->first();
    }

    /** The guru who was replaced is told nothing (D-134); only the name is used, in the notices of the others. */
    private function notify(User $actor, Registration $registration, ?string $replacedName, bool $guruLetter): void
    {
        $members = ApproveRegistration::memberUsers($registration)
            ->reject(fn (User $user): bool => $user->is($actor))
            ->values();

        if ($members->isNotEmpty()) {
            Notification::send($members, MentorAssigned::for($registration, 'siswa', $replacedName, $actor));
        }

        self::mentorToTell($registration, $actor)?->notify(MentorAssigned::for($registration, 'guru', $replacedName, $actor));

        // The guru letter still names the guru it printed (D-108): Tata Usaha makes a Koreksi.
        if ($guruLetter) {
            $tu = self::tataUsahaToTell($actor);

            if ($tu->isNotEmpty()) {
                Notification::send($tu, MentorAssigned::for($registration, 'tu', $replacedName, $actor));
            }
        }
    }
}
