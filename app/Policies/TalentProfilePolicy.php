<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TalentProfile;
use App\Models\User;

/**
 * Riwayat talenta (talent_profiles, one per student). Deny by default.
 * Owner: W2-A2. Staff reading the profile during pemetaan: `talent.manage` / `talent.assess`.
 */
class TalentProfilePolicy
{
    public function view(User $user, TalentProfile $profile): bool
    {
        if ($user->can(Permission::TalentManage->value) || $user->can(Permission::TalentAssess->value)) {
            return true;
        }

        return $this->owns($user, $profile);
    }

    /** The student creates or edits their own profile (create when none exists yet). */
    public function save(User $user, ?TalentProfile $profile = null): bool
    {
        if (! $user->canLogIn() || ! $user->can(Permission::TalentApply->value) || ! $user->student()->exists()) {
            return false;
        }

        return $profile === null || ! $profile->exists || $this->owns($user, $profile);
    }

    private function owns(User $user, TalentProfile $profile): bool
    {
        $studentId = $user->student()->value('id');

        return $studentId !== null && $profile->student_id === $studentId;
    }
}
