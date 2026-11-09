<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RevisionStatus;
use App\Models\RegistrationRevision;
use App\Models\User;
use App\Notifications\Registrations\RevisionDecided;
use App\Notifications\Registrations\RevisionWithdrawn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: Tata Usaha or Kesiswaan rejects a pending revision, with a note of 5–500 characters (the rule of
 * RejectRegistration). Also before every invited student answered, and also when the registration moved on meanwhile
 * (then it can no longer be approved, and the rejection takes it out of the queue). The approved team stays. The ketua
 * reads the note (RevisionDecided); the invited students who did not decline are told they do not join
 * (RevisionWithdrawn, without the note). First decision wins (ApproveRevision::lock()). Audit
 * `registration.revision_rejected` with ids only, never the note.
 */
final class RejectRevision
{
    public const EVENT = 'registration.revision_rejected';

    public const MSG_NOTE = 'Tulis alasan penolakan dulu.';

    /**
     * @throws AuthorizationException not Tata Usaha or Kesiswaan
     * @throws ValidationException keys `revision`, `note`
     */
    public function handle(User $actor, RegistrationRevision $revision, ?string $note): RegistrationRevision
    {
        Gate::forUser($actor)->authorize('viewQueue', RegistrationRevision::class);

        $note = trim((string) $note);

        if (mb_strlen($note) < RevisionRules::MIN_NOTE) {
            throw ValidationException::withMessages(['note' => self::MSG_NOTE]);
        }

        if (mb_strlen($note) > RevisionRules::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Alasan maksimal '.RevisionRules::MAX_NOTE.' karakter.']);
        }

        $locked = DB::transaction(function () use ($actor, $revision, $note): RegistrationRevision {
            [$registration, $locked] = ApproveRevision::lock($revision);

            Gate::forUser($actor)->authorize('decide', $locked);

            $locked->forceFill([
                'status' => RevisionStatus::Rejected,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'note' => $note,
            ])->save();

            activity('registration')
                ->performedOn($registration)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(['revision_id' => $locked->getKey(), 'status' => $registration->status->value])
                ->log(self::EVENT);

            return $locked;
        });

        $registration = $locked->registration;
        $leader = ConfirmMembership::leaderToTell($registration);

        if ($leader !== null && ! $leader->is($actor)) {
            $leader->notify(RevisionDecided::for($registration, 'ketua', false, $actor, $note));
        }

        $invited = array_values(array_filter($locked->additions(), fn (string $id): bool => $locked->answerOf($id) !== 'declined'));

        if ($invited !== []) {
            $users = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($invited))->get()
                ->reject(fn (User $user): bool => $user->is($actor))
                ->values();

            if ($users->isNotEmpty()) {
                Notification::send($users, new RevisionWithdrawn($registration, RevisionWithdrawn::REJECTED));
            }
        }

        return $locked;
    }
}
