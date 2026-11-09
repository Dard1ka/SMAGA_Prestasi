<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\Permission;
use App\Enums\RevisionStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationRevision;
use App\Models\User;
use App\Notifications\Registrations\RevisionAwaitingDecision;
use App\Notifications\Registrations\RevisionInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: "Ajukan revisi" AFTER the approval (disetujui, surat_terbit, berlangsung, menunggu_laporan; *sementara*
 * not once the report was sent). The ketua asks to add or remove anggota and/or change the guru pembimbing, with a
 * reason of 10–500 characters. Nothing of the team changes now: the revision is stored as `pending`, one per
 * registration (checked under the registration's lock), and the approved team stays valid until Tata Usaha or Kesiswaan
 * decides (ApproveRevision / RejectRevision); the ketua may cancel it meanwhile (CancelRevision).
 *
 * Students it brings in confirm first (RevisionInvitation → RespondToRevision); a revision without new students is
 * ready at once and the deciders are told (RevisionAwaitingDecision, never the actor). Audit
 * `registration.revision_requested` on the registration: ids and counts only, never the reason.
 */
final class RequestRevision
{
    public const EVENT = 'registration.revision_requested';

    /**
     * @param  list<string>  $add  student ULIDs to bring in
     * @param  list<string>  $remove  student ULIDs of confirmed anggota to take out
     * @param  int|string|null  $mentorId  the new guru; null, '' or the current guru = the guru stays
     *
     * @throws AuthorizationException not the ketua
     * @throws ValidationException keys `registration`, `reason`, `changes`, `members`, `mentor`
     */
    public function handle(User $actor, Registration $registration, array $add, array $remove, int|string|null $mentorId, ?string $reason): RegistrationRevision
    {
        if (! RevisionRules::leads($actor, $registration)) {
            throw new AuthorizationException(RevisionRules::MSG_NOT_LEADER);
        }

        $reason = RevisionRules::reason($reason);

        $revision = DB::transaction(function () use ($actor, $registration, $add, $remove, $mentorId, $reason): RegistrationRevision {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            self::assertAfterApproval($locked);

            if (RegistrationRevision::query()->where('registration_id', $locked->getKey())->pending()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['registration' => RevisionRules::MSG_PENDING]);
            }

            /** @var Competition $competition */
            $competition = Competition::withTrashed()->whereKey($locked->competition_id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('competition', $competition);

            RevisionRules::lockStudents(RevisionRules::ids($add));
            $change = RevisionRules::check($locked, $competition, $add, $remove, $mentorId, true);

            $revision = new RegistrationRevision([
                'registration_id' => $locked->getKey(),
                'requested_by' => $actor->getKey(),
                'reason' => $reason,
            ]);
            $revision->forceFill([
                'status' => RevisionStatus::Pending,
                'payload' => [
                    'add' => $change['add'],
                    'remove' => $change['remove'],
                    'mentor_from' => $change['mentor'] !== null && $locked->mentor_id !== null ? (int) $locked->mentor_id : null,
                    'mentor_to' => $change['mentor'] !== null ? (int) $change['mentor']->getKey() : null,
                    'answers' => (object) [],
                ],
                'ready_at' => $change['add'] === [] ? now() : null,
            ])->save();

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'revision_id' => $revision->getKey(),
                    'added' => $change['add'],
                    'removed' => $change['remove'],
                    'mentor_to' => $change['mentor'] !== null ? (int) $change['mentor']->getKey() : null,
                    'status' => $locked->status->value,
                ])
                ->log(self::EVENT);

            return $revision->setRelation('registration', $locked);
        });

        $this->notify($actor, $revision);

        return $revision;
    }

    /**
     * The refusal for a registration that is not (or no longer) between its approval and its report: not approved yet
     * (the change is direct then), or closed.
     *
     * @throws ValidationException key `registration`
     */
    public static function assertAfterApproval(Registration $registration): void
    {
        if (RevisionRules::isAfterApproval($registration)) {
            return;
        }

        throw ValidationException::withMessages([
            'registration' => RevisionRules::closedReason($registration) ?? RevisionRules::MSG_NOT_APPROVED,
        ]);
    }

    /**
     * Tata Usaha and Kesiswaan accounts that can log in and decide revisions (`registration.revise_decide`), without
     * $except (nobody is told about their own action).
     *
     * @return Collection<int, User>
     */
    public static function deciders(?User $except = null): Collection
    {
        return User::query()->canLogIn()->permission(Permission::RegistrationReviseDecide->value)->get()
            ->reject(fn (User $user): bool => $except !== null && $user->is($except))
            ->values();
    }

    private function notify(User $actor, RegistrationRevision $revision): void
    {
        /** @var Registration $registration */
        $registration = $revision->registration;
        $additions = $revision->additions();

        if ($additions !== []) {
            $invitees = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($additions))->get();

            if ($invitees->isNotEmpty()) {
                Notification::send($invitees, new RevisionInvitation($registration, (string) $revision->getKey()));
            }

            return;
        }

        $deciders = self::deciders($actor);

        if ($deciders->isNotEmpty()) {
            Notification::send($deciders, new RevisionAwaitingDecision($registration));
        }
    }
}
