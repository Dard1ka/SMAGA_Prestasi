<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\MemberResponded;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An invited anggota joins the team (UI-MAP "RespondToInvitation", yes). Only the invitee, only while the
 * registration is `menunggu_konfirmasi` and the registration window is open (SECURITY §3m-3). When every
 * remaining member has confirmed (and the team still has ≥ 2 people) the registration moves to
 * `menunggu_kepsek` and the Kepala Sekolah is notified.
 */
final class ConfirmMembership
{
    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('respond', $registration);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        [$locked, $ready] = DB::transaction(function () use ($actor, $registration, $student): array {
            [$locked, $member] = $this->lockPendingInvite($registration, $student);

            if (! $locked->competition->isOpenForRegistration()) {
                throw ValidationException::withMessages(['registration' => 'Batas pendaftaran sudah lewat.']);
            }

            $elsewhere = RegistrationMember::query()
                ->where('student_id', $student->getKey())
                ->where('registration_id', '!=', $locked->getKey())
                ->whereNull('declined_at')
                ->whereHas('registration', fn (Builder $r) => $r
                    ->where('competition_id', $locked->competition_id)
                    ->whereIn('status', RegistrationStatus::nonFinal()))
                ->exists();

            if ($elsewhere) {
                throw ValidationException::withMessages(['registration' => 'Kamu sudah terdaftar di tim lain untuk lomba ini.']);
            }

            $member->forceFill(['confirmed_at' => now()])->save();
            $ready = $this->advanceIfTeamReady($locked);

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.member_confirmed')
                ->withProperties(['member_id' => $member->getKey(), 'status' => $locked->status->value])
                ->log('registration.member_confirmed');

            return [$locked, $ready];
        });

        $this->notifyAfterResponse($locked, (string) $actor->name, true, $ready);

        return $locked;
    }

    /**
     * Lock the registration + the actor's pending anggota row; re-check the policy conditions.
     *
     * @return array{0: Registration, 1: RegistrationMember}
     *
     * @throws ValidationException
     */
    public function lockPendingInvite(Registration $registration, Student $student): array
    {
        /** @var Registration $locked */
        $locked = Registration::query()->with('competition')->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

        /** @var RegistrationMember|null $member */
        $member = $locked->members()->where('student_id', $student->getKey())->lockForUpdate()->first();

        if (
            $locked->status !== RegistrationStatus::MenungguKonfirmasi
            || $member === null
            || $member->role !== MemberRole::Anggota
            || $member->confirmed_at !== null
            || $member->declined_at !== null
        ) {
            throw ValidationException::withMessages(['registration' => 'Undangan ini sudah tidak berlaku.']);
        }

        return [$locked, $member];
    }

    /**
     * All remaining (not declined) members confirmed and at least 2 people → menunggu_kepsek.
     * Call inside the transaction with the registration locked.
     */
    public function advanceIfTeamReady(Registration $locked): bool
    {
        $remaining = $locked->members()->whereNull('declined_at')->get();
        $pending = $remaining->whereNull('confirmed_at');

        if ($pending->isNotEmpty() || $remaining->count() < 2) {
            return false;
        }

        $this->machine->assertTransition($locked->status, RegistrationStatus::MenungguKepsek);
        $locked->forceFill(['status' => RegistrationStatus::MenungguKepsek])->save();

        return true;
    }

    /** Leader hears about the answer; the Kepala Sekolah hears when the team is complete. */
    public function notifyAfterResponse(Registration $registration, string $memberName, bool $accepted, bool $ready): void
    {
        $leaderUser = $registration->loadMissing('leader.user')->leader?->user;

        if ($leaderUser !== null && $leaderUser->canLogIn()) {
            $leaderUser->notify(new MemberResponded($registration, $memberName, $accepted));
        }

        if ($ready) {
            Notification::send(CreateRegistration::principals(), new RegistrationAwaitingPrincipal($registration));
        }
    }
}
