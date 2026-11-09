<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RevisionStatus;
use App\Models\Registration;
use App\Models\RegistrationRevision;
use App\Models\User;
use App\Notifications\Registrations\RevisionWithdrawn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: the ketua cancels the revision that waits for a decision (Lomba saya, "Batalkan revisi"). The approved
 * team was never changed, so nothing else moves. The students it invited who did not decline are told their
 * invitation is void (RevisionWithdrawn). Audit `registration.revision_cancelled` with ids only.
 */
final class CancelRevision
{
    public const EVENT = 'registration.revision_cancelled';

    /** @throws ValidationException key `revision` (decided or cancelled meanwhile) */
    public function handle(User $actor, RegistrationRevision $revision): RegistrationRevision
    {
        $locked = DB::transaction(function () use ($actor, $revision): RegistrationRevision {
            /** @var Registration $registration */
            $registration = Registration::query()->whereKey($revision->registration_id)->lockForUpdate()->firstOrFail();
            /** @var RegistrationRevision $locked */
            $locked = RegistrationRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();

            if (! RevisionRules::leads($actor, $registration)) {
                throw new AuthorizationException(RevisionRules::MSG_NOT_LEADER);
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['revision' => ApproveRevision::decidedText($locked)]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked->setRelation('registration', $registration));

            $locked->forceFill([
                'status' => RevisionStatus::Cancelled,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            activity('registration')
                ->performedOn($registration)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(['revision_id' => $locked->getKey(), 'by' => 'ketua'])
                ->log(self::EVENT);

            return $locked;
        });

        $invited = array_values(array_filter($locked->additions(), fn (string $id): bool => $locked->answerOf($id) !== 'declined'));

        if ($invited !== []) {
            $users = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($invited))->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, new RevisionWithdrawn($locked->registration, RevisionWithdrawn::CANCELLED));
            }
        }

        return $locked;
    }
}
