<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\InvitationWithdrawn;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Policies\RegistrationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The ketua withdraws an invitation nobody answered yet (QA6, backlog #66 Q1-22): before, an invitee who never
 * answered (for instance a classmate who has not activated the account) held a place in the team until the
 * registration deadline, and the team could not reach the Kepala Sekolah earlier.
 *
 * Same window as InviteMembers (RegistrationPolicy::invite: the ketua, `menunggu_konfirmasi`, until the registration
 * deadline whatever the lomba's status, QA10). Only an anggota row without an answer goes; a confirmed or declined row stays (audit trail, D-36). The row
 * is deleted, so the student may be invited again later. Then the D-36 rule runs as after a decline: every
 * remaining member confirmed and ≥ 2 people → `menunggu_kepsek` (Kepala Sekolah notified). The student hears that
 * the invitation is withdrawn (InvitationWithdrawn). Audited `registration.invitation_withdrawn`. Errors use the
 * key `members`.
 */
final class WithdrawInvitation
{
    public function __construct(private readonly ConfirmMembership $confirm) {}

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration, string $studentId): Registration
    {
        Gate::forUser($actor)->authorize('invite', $registration);

        [$locked, $ready] = DB::transaction(function () use ($actor, $registration, $studentId): array {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RegistrationStatus::MenungguKonfirmasi || ! $locked->isTeam()) {
                throw ValidationException::withMessages(['members' => 'Undangan hanya bisa dibatalkan selama tim menunggu konfirmasi.']);
            }

            $competition = Competition::withTrashed()->find($locked->competition_id);

            // QA10: the same window as the policy, re-checked under the lock (the deadline, not the published status).
            if (! RegistrationPolicy::teamWindowOpen($competition)) {
                throw ValidationException::withMessages(['members' => 'Batas pendaftaran sudah lewat.']);
            }

            /** @var RegistrationMember|null $member */
            $member = $locked->members()->where('student_id', $studentId)->lockForUpdate()->first();

            if ($member === null || $member->role !== MemberRole::Anggota) {
                throw ValidationException::withMessages(['members' => 'Undangan tidak ditemukan.']);
            }

            $name = (string) Student::query()->whereKey($studentId)->with('user:id,name')->first()?->user?->name;

            if ($member->confirmed_at !== null) {
                throw ValidationException::withMessages(['members' => $name.' sudah konfirmasi, undangannya tidak bisa dibatalkan.']);
            }

            if ($member->declined_at !== null) {
                throw ValidationException::withMessages(['members' => $name.' sudah menolak undangan ini.']);
            }

            $member->delete();
            $ready = $this->confirm->advanceIfTeamReady($locked);

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.invitation_withdrawn')
                ->withProperties(['student_id' => $studentId, 'status' => $locked->status->value])
                ->log('registration.invitation_withdrawn');

            return [$locked->setRelation('competition', $competition), $ready];
        });

        $invitee = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($studentId))->first();

        if ($invitee !== null) {
            $invitee->notify(new InvitationWithdrawn($locked));
        }

        if ($ready) {
            Notification::send(CreateRegistration::principals(), new RegistrationAwaitingPrincipal($locked));
        }

        $registration->setRawAttributes($locked->getAttributes(), true);

        return $registration;
    }
}
