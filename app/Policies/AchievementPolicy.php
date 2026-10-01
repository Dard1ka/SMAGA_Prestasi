<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Achievement;
use App\Models\User;

/**
 * Prestasi (verified achievements, one row per team member). Deny by default.
 * Public pages do not use this policy: they read through their own consent-gated queries (D-16).
 *
 * Owner of viewAny/view: W2-A2 (Portofolio). W2-B adds the Kesiswaan abilities (export, …) below the marker.
 */
class AchievementPolicy
{
    /** Siswa: own portfolio. Staff with `achievement.view_all`: Database prestasi. */
    public function viewAny(User $user): bool
    {
        if ($user->can(Permission::AchievementViewAll->value)) {
            return true;
        }

        return $user->canLogIn() && $user->student()->exists();
    }

    public function view(User $user, Achievement $achievement): bool
    {
        if ($user->can(Permission::AchievementViewAll->value)) {
            return true;
        }

        $studentId = $user->student()->value('id');

        return $studentId !== null && $achievement->student_id === $studentId;
    }

    // --- W2-B: Kesiswaan abilities below this line ---
}
