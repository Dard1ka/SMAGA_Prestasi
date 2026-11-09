<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\User;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RemovedFromTeam;
use App\Notifications\Registrations\TeamInvitation;
use App\Policies\RegistrationPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: "Ubah pendaftaran" BEFORE the approval (menunggu_lomba, menunggu_konfirmasi, menunggu_kepsek): the ketua
 * adds and removes anggota and changes the guru pembimbing, and it applies at once, without an extra approval. The
 * rules are RevisionRules::check().
 *
 * - New anggota are invited and confirm as today (TeamInvitation, ConfirmMembership); while the lomba waits for
 *   Kesiswaan (D-148) they are not invited yet (`invited_at` null), as CreateRegistration does. An invitation needs the
 *   team window (RegistrationPolicy::teamWindowOpen(), QA10): after the registration deadline nobody could answer it.
 * - A registration that waited for the approval (menunggu_kepsek) goes back to menunggu_konfirmasi when it now waits for
 *   new anggota; with only removals or a guru change it stays in the approval queue.
 * - Removing anggota deletes their rows (a removed student may join another team or be invited again), then the D-36
 *   rule runs as after a withdrawal: every remaining person confirmed and ≥ 2 → menunggu_kepsek (approvers told).
 * - Who is told: the new anggota (invitation), the removed anggota who had been invited (RemovedFromTeam; nobody of a
 *   team that waits for its lomba ever heard of it), the approvers when the team goes on to them. A guru change before
 *   the approval tells nobody, as the student's first choice of a guru does (the guru hears with the approval, D-132).
 * - Audit `registration.changed_by_leader` (student and user ids only, statuses); a guru change also writes
 *   ApproveRegistration::EVENT_MENTOR_CHANGED with `source` = MENTOR_SOURCE.
 */
final class ChangeRegistration
{
    public const EVENT = 'registration.changed_by_leader';

    /** `properties.source` of the guru change row (ApproveRegistration::mentorWasReplaced() reads only the approval's). */
    public const MENTOR_SOURCE = 'ketua';

    public function __construct(
        private readonly RegistrationStateMachine $machine,
        private readonly ConfirmMembership $confirm,
    ) {}

    /**
     * @param  list<string>  $add  student ULIDs to invite
     * @param  list<string>  $remove  student ULIDs of anggota to take out
     * @param  int|string|null  $mentorId  the new guru; null, '' or the current guru = the guru stays
     * @return array{added: list<string>, removed: list<string>, mentor_changed: bool, status: RegistrationStatus, ready: bool}
     *
     * @throws AuthorizationException not the ketua
     * @throws ValidationException keys `registration` (status), `changes`, `members`, `mentor`
     */
    public function handle(User $actor, Registration $registration, array $add, array $remove, int|string|null $mentorId): array
    {
        // Who may change it at all; the status is checked under the lock below, so a screen that went stale (approved
        // meanwhile) gets a sentence, never a 403.
        if (! RevisionRules::leads($actor, $registration)) {
            throw new AuthorizationException(RevisionRules::MSG_NOT_LEADER);
        }

        /** @var array{removedTold: list<string>, invited: list<string>} $tell */
        $tell = ['removedTold' => [], 'invited' => []];

        $result = DB::transaction(function () use ($actor, $registration, $add, $remove, $mentorId, &$tell): array {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            self::assertBeforeApproval($locked);
            Gate::forUser($actor)->authorize('change', $locked);

            /** @var Competition $competition */
            $competition = Competition::withTrashed()->whereKey($locked->competition_id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('competition', $competition);

            RevisionRules::lockStudents(RevisionRules::ids($add));
            $change = RevisionRules::check($locked, $competition, $add, $remove, $mentorId, false);

            $from = $locked->status;
            $waiting = $from === RegistrationStatus::MenungguLomba;

            if ($change['add'] !== [] && ! $waiting && ! RegistrationPolicy::teamWindowOpen($locked)) {
                throw ValidationException::withMessages(['members' => RevisionRules::MSG_WINDOW]);
            }

            // Removed: the anggota rows go (they may be invited again, or join another team).
            $removedRows = $locked->members()->whereIn('student_id', $change['remove'])->where('role', MemberRole::Anggota->value)->get();
            $tell['removedTold'] = $waiting ? [] : $removedRows
                ->filter(fn (RegistrationMember $m): bool => $m->invited_at !== null)
                ->map(fn (RegistrationMember $m): string => (string) $m->student_id)
                ->values()
                ->all();
            $removedRows->each(fn (RegistrationMember $m) => $m->delete());

            $now = now();

            foreach ($change['add'] as $studentId) {
                // Not invited while the lomba waits (D-148): ResumeWaitingRegistrations sends the invitation.
                $locked->members()->make(['student_id' => $studentId, 'invited_at' => $waiting ? null : $now])
                    ->forceFill(['role' => MemberRole::Anggota])
                    ->save();
            }

            $tell['invited'] = $waiting ? [] : $change['add'];
            $previousMentor = $locked->mentor_id !== null ? (int) $locked->mentor_id : null;

            if ($change['mentor'] !== null) {
                $locked->forceFill(['mentor_id' => $change['mentor']->getKey()]);
            }

            if ($from === RegistrationStatus::MenungguKepsek && $change['add'] !== []) {
                // It waits for the answers of the new anggota again before it goes back to the approvers.
                $this->machine->assertTransition($from, RegistrationStatus::MenungguKonfirmasi);
                $locked->forceFill(['status' => RegistrationStatus::MenungguKonfirmasi]);
            }

            $locked->save();

            $ready = $locked->status === RegistrationStatus::MenungguKonfirmasi && $this->confirm->advanceIfTeamReady($locked);

            if ($change['mentor'] !== null) {
                // User ids only: the audit log never carries names (D-134).
                activity('registration')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event(ApproveRegistration::EVENT_MENTOR_CHANGED)
                    ->withProperties(['from' => $previousMentor, 'to' => (int) $change['mentor']->getKey(), 'source' => self::MENTOR_SOURCE])
                    ->log(ApproveRegistration::EVENT_MENTOR_CHANGED);
            }

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'added' => $change['add'],
                    'removed' => $change['remove'],
                    'mentor_from' => $change['mentor'] !== null ? $previousMentor : null,
                    'mentor_to' => $change['mentor'] !== null ? (int) $change['mentor']->getKey() : null,
                    'from' => $from->value,
                    'status' => $locked->status->value,
                ])
                ->log(self::EVENT);

            return [
                'registration' => $locked,
                'added' => $change['add'],
                'removed' => $change['remove'],
                'mentor_changed' => $change['mentor'] !== null,
                'status' => $locked->status,
                'ready' => $ready,
            ];
        });

        /** @var Registration $locked */
        $locked = $result['registration'];
        $registration->setRawAttributes($locked->getAttributes(), true);
        $registration->unsetRelation('mentor');
        $registration->unsetRelation('members');

        $this->notify($locked, $tell['invited'], $tell['removedTold'], $result['ready']);

        unset($result['registration']);

        return $result;
    }

    /**
     * The refusal for a registration that is no longer before its approval: approved meanwhile (revision now), or
     * closed (report sent, cancelled, rejected).
     *
     * @throws ValidationException key `registration`
     */
    public static function assertBeforeApproval(Registration $registration): void
    {
        if (RevisionRules::isBeforeApproval($registration)) {
            return;
        }

        throw ValidationException::withMessages([
            'registration' => RevisionRules::closedReason($registration) ?? RevisionRules::MSG_NOW_APPROVED,
        ]);
    }

    /**
     * @param  list<string>  $invited
     * @param  list<string>  $removed
     */
    private function notify(Registration $registration, array $invited, array $removed, bool $ready): void
    {
        $users = fn (array $ids) => $ids === []
            ? collect()
            : User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($ids))->get();

        $invitees = $users($invited);

        if ($invitees->isNotEmpty()) {
            Notification::send($invitees, new TeamInvitation($registration));
        }

        $removedUsers = $users($removed);

        if ($removedUsers->isNotEmpty()) {
            Notification::send($removedUsers, new RemovedFromTeam($registration));
        }

        if ($ready) {
            Notification::send(CreateRegistration::principals(), new RegistrationAwaitingPrincipal($registration));
        }
    }
}
