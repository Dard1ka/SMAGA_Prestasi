<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RegistrationStatus;
use App\Enums\RevisionStatus;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RevisionAnswered;
use App\Notifications\Registrations\RevisionAwaitingDecision;
use App\Notifications\Registrations\RevisionWithdrawn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199: a student a revision invites answers it (Siswa › Undangan revisi). Only that student, once, while the
 * revision waits (RegistrationRevisionPolicy::respond, checked again under the locks: the registration first, then the
 * revision, the lock order of every revision Action).
 *
 * - confirm(): the answer is kept in the revision (`payload.answers`); the student joins the team only when the revision
 *   is approved. Refused while the student is in another running registration of the lomba. When every invited
 *   student confirmed, the revision is ready (`ready_at`) and Tata Usaha and Kesiswaan are told.
 * - decline(): the revision no longer holds what the ketua asked for, so it ends as `cancelled`; the other invited
 *   students are told their invitation is void. The approved team stays.
 * The ketua hears every answer (RevisionAnswered). Audit `registration.revision_member_confirmed` /
 * `registration.revision_member_declined` with ids only.
 */
final class RespondToRevision
{
    public const EVENT_CONFIRMED = 'registration.revision_member_confirmed';

    public const EVENT_DECLINED = 'registration.revision_member_declined';

    public const MSG_NOT_VALID = 'Undangan ini sudah tidak berlaku.';

    public const MSG_ELSEWHERE = 'Kamu sudah terdaftar di tim lain untuk lomba ini.';

    /** @throws ValidationException key `revision` */
    public function confirm(User $actor, RegistrationRevision $revision): RegistrationRevision
    {
        [$locked, $ready] = $this->answer($actor, $revision, true);

        $registration = $locked->registration;
        ConfirmMembership::leaderToTell($registration)
            ?->notify(new RevisionAnswered($registration, (string) $actor->name, true, $ready));

        if ($ready) {
            $deciders = RequestRevision::deciders();

            if ($deciders->isNotEmpty()) {
                Notification::send($deciders, new RevisionAwaitingDecision($registration));
            }
        }

        return $locked;
    }

    /** @throws ValidationException key `revision` */
    public function decline(User $actor, RegistrationRevision $revision): RegistrationRevision
    {
        [$locked] = $this->answer($actor, $revision, false);

        $registration = $locked->registration;
        ConfirmMembership::leaderToTell($registration)
            ?->notify(new RevisionAnswered($registration, (string) $actor->name, false));

        $student = $actor->loadMissing('student')->getRelation('student');
        $others = array_values(array_filter(
            $locked->additions(),
            fn (string $id): bool => $id !== (string) $student?->getKey() && $locked->answerOf($id) !== 'declined',
        ));

        if ($others !== []) {
            $users = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($others))->get();

            if ($users->isNotEmpty()) {
                Notification::send($users, new RevisionWithdrawn($registration, RevisionWithdrawn::DECLINED));
            }
        }

        return $locked;
    }

    /**
     * @return array{0: RegistrationRevision, 1: bool} the locked revision (with its registration) and whether it became ready
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function answer(User $actor, RegistrationRevision $revision, bool $accepted): array
    {
        /** @var Student|null $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        if ($student === null || ! $actor->canLogIn()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $revision, $accepted, $student): array {
            /** @var Registration $registration */
            $registration = Registration::query()->whereKey($revision->registration_id)->lockForUpdate()->firstOrFail();
            /** @var RegistrationRevision $locked */
            $locked = RegistrationRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
            $studentId = (string) $student->getKey();

            if (! in_array($studentId, $locked->additions(), true)) {
                throw new AuthorizationException;
            }

            if (! Gate::forUser($actor)->allows('respond', $locked) || ! RevisionRules::isAfterApproval($registration)
                || ! $registration->hasListedLeader()) {
                throw ValidationException::withMessages(['revision' => self::MSG_NOT_VALID]);
            }

            if ($accepted && self::busyElsewhere($studentId, $registration)) {
                throw ValidationException::withMessages(['revision' => self::MSG_ELSEWHERE]);
            }

            $payload = $locked->payload;
            $answers = (array) ($payload['answers'] ?? []);
            $answers[$studentId] = [$accepted ? 'confirmed_at' : 'declined_at' => now()->toIso8601String()];
            $payload['answers'] = $answers;
            $locked->forceFill(['payload' => $payload]);

            $ready = false;

            if (! $accepted) {
                // The revision no longer holds what the ketua asked for: it ends here, the approved team stays.
                $locked->forceFill(['status' => RevisionStatus::Cancelled, 'decided_at' => now()]);
            } elseif ($locked->everyoneConfirmed()) {
                $locked->forceFill(['ready_at' => now()]);
                $ready = true;
            }

            $locked->save();

            activity('registration')
                ->performedOn($registration)
                ->causedBy($actor)
                ->event($accepted ? self::EVENT_CONFIRMED : self::EVENT_DECLINED)
                ->withProperties([
                    'revision_id' => $locked->getKey(),
                    'student_id' => $studentId,
                    'revision_status' => $locked->status->value,
                ])
                ->log($accepted ? self::EVENT_CONFIRMED : self::EVENT_DECLINED);

            return [$locked->setRelation('registration', $registration), $ready];
        });
    }

    /** The student is a member (not declined) of another running registration of the same lomba. */
    public static function busyElsewhere(string $studentId, Registration $registration): bool
    {
        return RegistrationMember::query()
            ->where('student_id', $studentId)
            ->where('registration_id', '!=', $registration->getKey())
            ->whereNull('declined_at')
            ->whereHas('registration', fn (Builder $r) => $r
                ->where('competition_id', $registration->competition_id)
                ->whereIn('status', RegistrationStatus::nonFinal()))
            ->exists();
    }
}
