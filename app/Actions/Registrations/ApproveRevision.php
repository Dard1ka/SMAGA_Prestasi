<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\MemberRole;
use App\Enums\RevisionStatus;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\User;
use App\Notifications\Registrations\RemovedFromTeam;
use App\Notifications\Registrations\RevisionDecided;
use App\Support\StaffRoleLabel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: Tata Usaha or Kesiswaan (`registration.revise_decide`; never the Kepala Sekolah, Admin, Humas or a guru)
 * approves a pending revision once every student it brings in confirmed. The first decision wins: a revision decided
 * or cancelled meanwhile is refused with a sentence (the screen shows it as a toast), and so is a revision that changes the
 * guru when the registration's guru is no longer its `mentor_from` (changed since it was asked for, MSG_MENTOR_CHANGED).
 *
 * One transaction (the registration locked first, then the revision): the rules are checked again against the team as
 * it is now (RevisionRules::check(): the new students still active and in no other running registration of the lomba,
 * the removed ones still confirmed anggota, 2 … teamLimit() people, the guru still one MentorChoice offers), then the
 * removed anggota rows go, the new ones join as confirmed anggota (their invitation and answer times from the revision),
 * the guru changes (ApproveRegistration::EVENT_MENTOR_CHANGED with `source` = MENTOR_SOURCE) and the revision is
 * `approved`. The letters are not touched: when letters were issued, Tata Usaha is told to make a Koreksi.
 *
 * Who is told (after commit, never the actor): the ketua, the members who stay, the new members, the removed members
 * (RemovedFromTeam), the guru pembimbing the registration has now (the guru who was replaced nothing, D-134), and Tata
 * Usaha when letters exist. Audit `registration.revision_approved` with ids only.
 */
final class ApproveRevision
{
    public const EVENT = 'registration.revision_approved';

    /** `properties.source` of the guru change row (ApproveRegistration::mentorWasReplaced() reads only the approval's). */
    public const MENTOR_SOURCE = 'revisi';

    public const MSG_WAITING = 'Masih ada siswa yang belum menerima undangan revisi ini.';

    public const MSG_NOT_LISTED = 'Pendaftaran ini sudah tidak ada di daftar.';

    public const MSG_NOTHING = 'Revisi ini tidak mengubah apa pun lagi: tim dan guru pembimbingnya sudah sama.';

    /** The guru changed after the revision was asked for (Kesiswaan, D-198): the row's warning on Revisi pendaftaran. */
    public const MSG_MENTOR_STALE = 'Guru pembimbing pendaftaran ini sudah diganti sejak revisi ini diajukan.';

    /** The refusal of an approval of such a revision (2026-11-09). */
    public const MSG_MENTOR_CHANGED = self::MSG_MENTOR_STALE.' Tolak revisi ini; ketua bisa mengajukan revisi baru.';

    /**
     * @throws AuthorizationException not Tata Usaha or Kesiswaan
     * @throws ValidationException keys `revision`, `note`, `members`, `mentor`
     */
    public function handle(User $actor, RegistrationRevision $revision, ?string $note = null): RegistrationRevision
    {
        Gate::forUser($actor)->authorize('viewQueue', RegistrationRevision::class);

        $note = trim((string) $note);

        if (mb_strlen($note) > RevisionRules::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal '.RevisionRules::MAX_NOTE.' karakter.']);
        }

        /** @var array{removed: list<string>, added: list<string>, mentorChanged: bool, letters: bool} $done */
        $done = ['removed' => [], 'added' => [], 'mentorChanged' => false, 'letters' => false];

        $locked = DB::transaction(function () use ($actor, $revision, $note, &$done): RegistrationRevision {
            [$registration, $locked] = self::lock($revision);

            Gate::forUser($actor)->authorize('decide', $locked);

            if (! $registration->hasListedLeader()) {
                throw ValidationException::withMessages(['revision' => self::MSG_NOT_LISTED]);
            }

            RequestRevision::assertAfterApproval($registration);

            if (! $locked->everyoneConfirmed()) {
                throw ValidationException::withMessages(['revision' => self::MSG_WAITING]);
            }

            // 2026-11-09: never overwrite a guru Kesiswaan chose after the revision was asked for (ChangeMentor, D-198).
            if (self::mentorChangedSince($locked, $registration)) {
                throw ValidationException::withMessages(['revision' => self::MSG_MENTOR_CHANGED]);
            }

            /** @var Competition $competition */
            $competition = Competition::withTrashed()->whereKey($registration->competition_id)->lockForUpdate()->firstOrFail();
            $registration->setRelation('competition', $competition);

            RevisionRules::lockStudents($locked->additions());

            try {
                $change = RevisionRules::check($registration, $competition, $locked->additions(), $locked->removals(), $locked->mentorTo(), true, (string) $locked->getKey());
            } catch (ValidationException $e) {
                // Staff read a staff sentence (D-119), not the student's "Tambah atau keluarkan anggota".
                throw isset($e->errors()['changes']) ? ValidationException::withMessages(['revision' => self::MSG_NOTHING]) : $e;
            }

            $registration->members()->whereIn('student_id', $change['remove'])->where('role', MemberRole::Anggota->value)->get()
                ->each(fn (RegistrationMember $m) => $m->delete());

            $invitedAt = $locked->created_at ?? now();

            foreach ($change['add'] as $studentId) {
                $confirmed = $locked->confirmedAt($studentId);

                $registration->members()->make(['student_id' => $studentId, 'invited_at' => $invitedAt])
                    ->forceFill([
                        'role' => MemberRole::Anggota,
                        'confirmed_at' => $confirmed !== null ? CarbonImmutable::parse($confirmed) : now(),
                    ])
                    ->save();
            }

            $previousMentor = $registration->mentor_id !== null ? (int) $registration->mentor_id : null;

            if ($change['mentor'] !== null) {
                $registration->forceFill(['mentor_id' => $change['mentor']->getKey()])->save();

                // User ids only: the audit log never carries names (D-134).
                activity('registration')
                    ->performedOn($registration)
                    ->causedBy($actor)
                    ->event(ApproveRegistration::EVENT_MENTOR_CHANGED)
                    ->withProperties(['from' => $previousMentor, 'to' => (int) $change['mentor']->getKey(), 'source' => self::MENTOR_SOURCE])
                    ->log(ApproveRegistration::EVENT_MENTOR_CHANGED);
            }

            $locked->forceFill([
                'status' => RevisionStatus::Approved,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'note' => $note === '' ? null : $note,
            ])->save();

            // The letters stay as they are (D-108): Tata Usaha corrects them with a Koreksi.
            $letters = $registration->letters()->exists();

            activity('registration')
                ->performedOn($registration)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'revision_id' => $locked->getKey(),
                    'added' => $change['add'],
                    'removed' => $change['remove'],
                    'mentor_from' => $change['mentor'] !== null ? $previousMentor : null,
                    'mentor_to' => $change['mentor'] !== null ? (int) $change['mentor']->getKey() : null,
                    'letters_issued' => $letters,
                    'status' => $registration->status->value,
                ])
                ->log(self::EVENT);

            $done = ['removed' => $change['remove'], 'added' => $change['add'], 'mentorChanged' => $change['mentor'] !== null, 'letters' => $letters];

            return $locked->setRelation('registration', $registration);
        });

        $this->notify($actor, $locked, $done);

        return $locked;
    }

    /**
     * Lock the revision's registration, then the revision (the lock order of every revision Action), and refuse a revision
     * that no longer waits (first decision wins).
     *
     * @return array{0: Registration, 1: RegistrationRevision}
     *
     * @throws ValidationException key `revision`
     */
    public static function lock(RegistrationRevision $revision): array
    {
        /** @var Registration $registration */
        $registration = Registration::query()->whereKey($revision->registration_id)->lockForUpdate()->firstOrFail();
        /** @var RegistrationRevision $locked */
        $locked = RegistrationRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw ValidationException::withMessages(['revision' => self::decidedText($locked)]);
        }

        return [$registration, $locked->setRelation('registration', $registration)];
    }

    /**
     * The revision changes the guru pembimbing, and the registration no longer has the guru it had when the revision was
     * asked for (`mentor_from`): somebody changed the guru since (Kesiswaan, D-198), so the revision may only be rejected.
     */
    public static function mentorChangedSince(RegistrationRevision $revision, Registration $registration): bool
    {
        if ($revision->mentorTo() === null) {
            return false;
        }

        $current = $registration->mentor_id !== null ? (int) $registration->mentor_id : null;

        return $current !== $revision->mentorFrom();
    }

    /** "Revisi ini sudah disetujui oleh Sri Handayani (Tata Usaha)." — for a revision that no longer waits. */
    public static function decidedText(RegistrationRevision $revision): string
    {
        $decider = $revision->decided_by !== null ? User::query()->with('roles')->find($revision->decided_by) : null;

        return match ($revision->status) {
            RevisionStatus::Approved, RevisionStatus::Rejected => 'Revisi ini sudah '.($revision->status === RevisionStatus::Approved ? 'disetujui' : 'ditolak')
                .($decider !== null ? ' oleh '.$decider->name.' ('.StaffRoleLabel::forDecision($decider).')' : '').'.',
            default => 'Revisi ini sudah dibatalkan.',
        };
    }

    /** @param array{removed: list<string>, added: list<string>, mentorChanged: bool, letters: bool} $done */
    private function notify(User $actor, RegistrationRevision $revision, array $done): void
    {
        $registration = $revision->registration;
        $notActor = fn ($users) => $users->reject(fn (User $user): bool => $user->is($actor))->values();

        $leader = ConfirmMembership::leaderToTell($registration);

        if ($leader !== null && ! $leader->is($actor)) {
            $leader->notify(RevisionDecided::for($registration, 'ketua', true, $actor));
        }

        $members = $notActor(ApproveRegistration::memberUsers($registration)
            ->reject(fn (User $user): bool => $leader !== null && $user->is($leader)));
        $newIds = $done['added'];
        $newUsers = $members->filter(fn (User $user): bool => in_array((string) $user->loadMissing('student')->student?->getKey(), $newIds, true))->values();
        $staying = $members->reject(fn (User $user): bool => $newUsers->contains(fn (User $n): bool => $n->is($user)))->values();

        if ($staying->isNotEmpty()) {
            Notification::send($staying, RevisionDecided::for($registration, 'anggota', true, $actor));
        }

        if ($newUsers->isNotEmpty()) {
            Notification::send($newUsers, RevisionDecided::for($registration, 'baru', true, $actor));
        }

        if ($done['removed'] !== []) {
            $removed = $notActor(User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($done['removed']))->get());

            if ($removed->isNotEmpty()) {
                Notification::send($removed, new RemovedFromTeam($registration, StaffRoleLabel::forDecision($actor)));
            }
        }

        // The guru the registration has NOW; the guru who was replaced is told nothing (D-134).
        $mentor = ApproveRegistration::mentorToTell($registration);

        if ($mentor !== null && ! $mentor->is($actor)) {
            $mentor->notify(RevisionDecided::for($registration, 'guru', true, $actor, null, $done['mentorChanged']));
        }

        if ($done['letters']) {
            $tu = $notActor(User::query()->role(RoleName::TataUsaha->value)->canLogIn()->get());

            if ($tu->isNotEmpty()) {
                Notification::send($tu, RevisionDecided::for($registration, 'tu', true, $actor));
            }
        }
    }
}
