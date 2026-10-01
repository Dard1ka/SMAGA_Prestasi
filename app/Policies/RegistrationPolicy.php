<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;

/**
 * Pendaftaran (ARCHITECTURE §6). Deny by default; not-yours → callers answer 404.
 * Owner of the file: W2-A1 (view, create, respond, cancel). W2-C adds `decide` (Kepala Sekolah only,
 * Admin denied, D-01) and other areas add their methods below the marked line.
 */
class RegistrationPolicy
{
    /** Members (ketua, anggota, invitees), the mentor, and staff who handle registrations. */
    public function view(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        if ($this->membership($user, $registration) !== null) {
            return true;
        }

        if ($registration->mentor_id !== null && (int) $registration->mentor_id === (int) $user->getKey()) {
            return true;
        }

        return $user->can(Permission::RegistrationViewAll->value)
            || $user->can(Permission::RegistrationApprove->value)
            || $user->can(Permission::LetterView->value);
    }

    /**
     * Siswa may register while the lomba is published and open and they are not in another
     * non-final registration of it. CreateRegistration re-checks everything inside its transaction.
     */
    public function create(User $user, Competition $competition): bool
    {
        $student = $this->student($user);

        if ($student === null || ! $user->canLogIn() || ! $user->can(Permission::RegistrationCreate->value)) {
            return false;
        }

        if (! $competition->isOpenForRegistration()) {
            return false;
        }

        return ! Student::query()->whereKey($student->getKey())->busyIn($competition)->exists();
    }

    /** Confirm or decline a team invite: only the invitee, only while the team waits (SECURITY §3m-3). */
    public function respond(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || $registration->status !== RegistrationStatus::MenungguKonfirmasi) {
            return false;
        }

        $member = $this->membership($user, $registration);

        return $member !== null
            && $member->role === MemberRole::Anggota
            && $member->confirmed_at === null
            && $member->declined_at === null;
    }

    /** Leader cancels before the Kepala Sekolah decides (ARCHITECTURE §6, lead 2026-10-01). */
    public function cancel(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        $student = $this->student($user);

        return $student !== null
            && $registration->leader_student_id === $student->getKey()
            && in_array($registration->status, [RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek], true);
    }

    // --- W2-C: decide (Kepala Sekolah only) and other areas' methods below ---

    /**
     * Kepala Sekolah approval queue (UI-MAP #19). `registration.approve` is held by the Kepala Sekolah only;
     * Admin has every permission except this one (D-01, SECURITY §3m-9), so Admin is denied here.
     */
    public function viewApprovalQueue(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::RegistrationApprove->value);
    }

    /** Approve or reject one registration: Kepala Sekolah only, only while it waits for the decision. */
    public function decide(User $user, Registration $registration): bool
    {
        return $this->viewApprovalQueue($user)
            && $registration->status === RegistrationStatus::MenungguKepsek;
    }

    protected function student(User $user): ?Student
    {
        return $user->loadMissing('student')->getRelation('student');
    }

    protected function membership(User $user, Registration $registration): ?RegistrationMember
    {
        $student = $this->student($user);

        if ($student === null) {
            return null;
        }

        if ($registration->relationLoaded('members')) {
            return $registration->members->firstWhere('student_id', $student->getKey());
        }

        return $registration->members()->where('student_id', $student->getKey())->first();
    }
}
