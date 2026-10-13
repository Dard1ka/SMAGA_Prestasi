<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TalentApplication;
use App\Models\User;

/**
 * Pilihan talenta (D-21…D-23, docs/TALENT-FORM.md). Deny by default.
 *
 * Owner of the siswa abilities: W2-A2. W2-B adds the Kesiswaan/pembina abilities (manage, assess, …)
 * below the marker at the end of this class.
 */
class TalentApplicationPolicy
{
    /** Siswa: own list on Profil talenta. Staff: Database talenta siswa and Guru › Siswa binaan. */
    public function viewAny(User $user): bool
    {
        return $this->isStudentApplicant($user)
            || $user->can(Permission::TalentManage->value)
            || $user->can(Permission::TalentAssess->value);
    }

    /** Every active class may apply, incl. kelas XII (D-23); archived accounts cannot log in anyway (D-29). */
    public function create(User $user): bool
    {
        return $this->isStudentApplicant($user);
    }

    public function view(User $user, TalentApplication $application): bool
    {
        if ($user->can(Permission::TalentManage->value)) {
            return true;
        }

        if ($application->mentor_id !== null && (int) $application->mentor_id === (int) $user->getKey()) {
            return true;
        }

        return $this->owns($user, $application);
    }

    /**
     * The student deletes an own choice at any time, whatever its old status (D-142, "Hapus" on Profil talenta). A
     * deleted row is not deleted twice.
     */
    public function cancel(User $user, TalentApplication $application): bool
    {
        return $this->isStudentApplicant($user)
            && $this->owns($user, $application)
            && ! $application->trashed();
    }

    private function isStudentApplicant(User $user): bool
    {
        return $user->canLogIn()
            && $user->can(Permission::TalentApply->value)
            && $user->student()->exists();
    }

    private function owns(User $user, TalentApplication $application): bool
    {
        $studentId = $user->student()->value('id');

        return $studentId !== null && $application->student_id === $studentId;
    }

    // --- W2-B: Kesiswaan / pembina abilities below this line ---

    /** Kesiswaan/Admin screen Database talenta siswa (UI-MAP #18, `talent.manage`). */
    public function manage(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::TalentManage->value);
    }

    /**
     * Record the guru pembina (optional) and the note for him (UpdateTalentAssessment, D-142). A choice the student
     * deleted is not changed.
     */
    public function update(User $user, TalentApplication $application): bool
    {
        return $this->manage($user) && ! $application->trashed();
    }
}
