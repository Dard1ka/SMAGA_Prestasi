<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Models\Registration;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An invited anggota declines (UI-MAP "RespondToInvitation", no). The member row keeps `declined_at`
 * (audit trail); the leader is notified. If every remaining member has confirmed and the team still
 * has ≥ 2 people, the registration moves on to `menunggu_kepsek`; if only the leader is left it stays
 * in `menunggu_konfirmasi` and the leader can cancel and register again.
 * QA10: like a confirm, possible until the registration deadline whatever the lomba's status
 * (RegistrationPolicy::respond, re-checked in ConfirmMembership::lockPendingInvite()).
 */
final class DeclineMembership
{
    public function __construct(private readonly ConfirmMembership $confirm) {}

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('respond', $registration);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        [$locked, $ready] = DB::transaction(function () use ($actor, $registration, $student): array {
            [$locked, $member] = $this->confirm->lockPendingInvite($registration, $student);

            $member->forceFill(['declined_at' => now()])->save();
            $ready = $this->confirm->advanceIfTeamReady($locked);

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.member_declined')
                ->withProperties(['member_id' => $member->getKey(), 'status' => $locked->status->value])
                ->log('registration.member_declined');

            return [$locked, $ready];
        });

        $this->confirm->notifyAfterResponse($locked, (string) $actor->name, false, $ready);

        return $locked;
    }
}
