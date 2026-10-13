<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;

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
     * D-148: the submitter may also register for the own lomba while Kesiswaan has not decided it (same date window);
     * that registration waits as `menunggu_lomba` (Competition::isOpenForRegistrationBy()).
     */
    public function create(User $user, Competition $competition): bool
    {
        $student = $this->student($user);

        if ($student === null || ! $user->canLogIn() || ! $user->can(Permission::RegistrationCreate->value)) {
            return false;
        }

        if (! $competition->isOpenForRegistrationBy($user)) {
            return false;
        }

        return ! Student::query()->whereKey($student->getKey())->busyIn($competition)->exists();
    }

    /**
     * Confirm or decline a team invite: only the invitee, only while the team waits (SECURITY §3m-3) and until the
     * registration deadline (QA10: the same window ConfirmMembership checks, so "Ikut tim" only shows while it works).
     */
    public function respond(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || $registration->status !== RegistrationStatus::MenungguKonfirmasi) {
            return false;
        }

        $member = $this->membership($user, $registration);

        return $member !== null
            && $member->role === MemberRole::Anggota
            && $member->confirmed_at === null
            && $member->declined_at === null
            && self::teamWindowOpen($this->competition($registration));
    }

    /**
     * The window of an EXISTING team registration (QA10): its invitations may be answered, and the ketua may invite or
     * withdraw, until the lomba's registration deadline (end of that day), whatever the lomba's status. CloseCompetition
     * only takes the lomba out of the catalog: registrations that exist keep running, while a NEW registration still needs
     * an open, published lomba (create()). ExpirePendingInvitations expires the open invitations at the same deadline;
     * a lomba without a deadline never expires them.
     */
    public static function teamWindowOpen(?Competition $competition, ?DateTimeInterface $now = null): bool
    {
        if ($competition === null) {
            return false;
        }

        $closes = $competition->registration_closes_at;

        return $closes === null || $closes >= ($now ?? now());
    }

    /**
     * Leader cancels before the Kepala Sekolah decides (ARCHITECTURE §6, lead 2026-10-01), and while the registration
     * waits for its lomba to be verified (`menunggu_lomba`, D-148 widens D-33).
     */
    public function cancel(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        $student = $this->student($user);

        return $student !== null
            && $registration->leader_student_id === $student->getKey()
            && in_array($registration->status, [RegistrationStatus::MenungguLomba, RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek], true);
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

    /**
     * Put another guru pembimbing in the place of the one the student chose (D-132): part of the approval, so the
     * same people at the same moment as decide(): the Kepala Sekolah, while the registration waits for the decision.
     * Nobody changes the guru before that (the student's choice stands) or after it (the letters name that guru).
     */
    public function changeMentor(User $user, Registration $registration): bool
    {
        return $this->decide($user, $registration);
    }

    // --- B3: invite (replacement) members, backlog #9 ---

    /**
     * The ketua invites more anggota (e.g. a replacement for one who declined, D-36) or withdraws an unanswered
     * invitation (D-115) while the team still waits for confirmations, until the registration deadline (QA10:
     * teamWindowOpen(), also after Kesiswaan closed the lomba early). InviteMembers / WithdrawInvitation re-check under a lock.
     */
    public function invite(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || ! $user->can(Permission::RegistrationCreate->value)) {
            return false;
        }

        if ($registration->status !== RegistrationStatus::MenungguKonfirmasi || ! $registration->isTeam()) {
            return false;
        }

        $student = $this->student($user);

        if ($student === null || $registration->leader_student_id !== $student->getKey()) {
            return false;
        }

        return self::teamWindowOpen($this->competition($registration));
    }

    // --- end B3 ---

    // --- B4: guru pembimbing area (backlog #3, UI-MAP §4e; own binaan only, callers answer 404) ---

    /** Guru area (Siswa binaan): an active guru account. The list itself is scoped to mentor_id = the user. */
    public function viewBinaan(User $user): bool
    {
        return $user->canLogIn() && $user->hasRoleName(RoleName::Guru);
    }

    /**
     * One registration in the guru area: only its own guru pembimbing (SECURITY §3c "R binaan"). Staff with
     * registration.view_all use their own screens; this ability deliberately ignores that permission.
     */
    public function viewAsMentor(User $user, Registration $registration): bool
    {
        return $this->viewBinaan($user)
            && $registration->mentor_id !== null
            && (int) $registration->mentor_id === (int) $user->getKey();
    }

    // --- end B4 ---

    protected function student(User $user): ?Student
    {
        return $user->loadMissing('student')->getRelation('student');
    }

    protected function competition(Registration $registration): ?Competition
    {
        return $registration->relationLoaded('competition') && $registration->competition !== null
            ? $registration->competition
            : Competition::withTrashed()->find($registration->competition_id);
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
