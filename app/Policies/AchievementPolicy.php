<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Achievement;
use App\Models\User;

/**
 * Prestasi (verified achievements, one row per team member). Deny by default.
 * Public pages do not use this policy: they read through their own queries (PublicAchievements, D-16).
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

    // --- B4: Database prestasi (backlog #4, UI-MAP §4a `kesiswaan.prestasi.index`) ---

    /** Every verified achievement with all filters, totals and the detail drawer (`achievement.view_all`). */
    public function viewDatabase(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::AchievementViewAll->value);
    }

    /** Excel export of the filtered list (`achievement.export`; audited by ExportAchievements, SECURITY §3f). */
    public function export(User $user): bool
    {
        return $this->viewDatabase($user) && $user->can(Permission::AchievementExport->value);
    }

    // --- end B4 ---

    // --- B5: Kepala Sekolah prestasi per bidang + laporan (backlog #6, UI-MAP §4b) ---

    /** Internal drill-down and period report: all names, no NIS/NISN (`achievement.view_all` + `dashboard.view`). */
    public function viewSchoolReport(User $user): bool
    {
        return $user->canLogIn()
            && $user->can(Permission::AchievementViewAll->value)
            && $user->can(Permission::DashboardView->value);
    }

    /** Watermarked, audited PDF/XLSX export of the period report (`achievement.export`, SECURITY §3m-9). */
    public function exportSchoolReport(User $user): bool
    {
        return $this->viewSchoolReport($user) && $user->can(Permission::AchievementExport->value);
    }

    // --- end B5 ---
}
