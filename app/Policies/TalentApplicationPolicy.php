<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\TalentStatus;
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
    /** Siswa: own list on Profil talenta. Staff: pipeline screens. */
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

    /** The student withdraws a choice only while it is still "Terdaftar" (before Kesiswaan maps it). */
    public function cancel(User $user, TalentApplication $application): bool
    {
        return $this->isStudentApplicant($user)
            && $this->owns($user, $application)
            && $application->status === TalentStatus::Terdaftar;
    }

    /**
     * "Minta ditinjau ulang" (lead, 2026-10-04): the student's own rejected choice, one open request per
     * rejection. The request never changes the status; Kesiswaan decides (restore).
     */
    public function requestReview(User $user, TalentApplication $application): bool
    {
        return $this->isStudentApplicant($user)
            && $this->owns($user, $application)
            && ! $application->trashed()
            && $application->status === TalentStatus::TidakLolos
            && $application->review_requested_at === null;
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

    /** Kesiswaan/Admin pipeline screen (UI-MAP #18, `talent.manage`). */
    public function manage(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::TalentManage->value);
    }

    /** Move a stage, mark tidak lolos, set pembina / assessment date / note. */
    public function move(User $user, TalentApplication $application): bool
    {
        return $this->manage($user) && ! $application->trashed();
    }

    /**
     * "Pulihkan" a tidak-lolos choice back into the pipeline (not the soft-delete restore: that is the
     * student's "Urungkan" through ApplyTalent). The status is checked under the lock in RestoreTalentApplication.
     */
    public function restore(User $user, TalentApplication $application): bool
    {
        return $this->move($user, $application);
    }
}
