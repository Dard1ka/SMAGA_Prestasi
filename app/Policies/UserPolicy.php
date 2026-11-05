<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\User;

/**
 * Accounts (Admin › Pengguna & peran). Deny by default: every ability needs its permission
 * (RoleName::defaultPermissions(): only Admin has the user.* permissions) and nobody manages their own
 * account from here (no self-reset, self-disable or self-role-change).
 */
final class UserPolicy
{
    /** Disabled/archived accounts can never act (D-29, D-30). */
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value);
    }

    /** Create a staff account (students come only from the import, D-12/D-26). */
    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value);
    }

    /** Change a staff member's role. The Kepala Sekolah role moves only through replacePrincipal. */
    public function updateRole(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value)
            && ! $actor->is($target)
            && ! $target->hasRole(RoleName::Siswa->value)
            && ! $target->hasRole(RoleName::KepalaSekolah->value);
    }

    /** Student "Reset kata sandi" (D-158: the password only) and staff temporary password. */
    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserResetPassword->value) && ! $actor->is($target);
    }

    /** Manual disable/archive with a reason (D-30). */
    public function archive(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserArchive->value) && ! $actor->is($target);
    }

    /**
     * "Ubah status" of a STUDENT account (UbahStatusSiswa and the Actions it chains; 2026-11-05, the lead: the Kelas page
     * of Kesiswaan and Tata Usaha "sama persis Admin"): `user.archive` (Admin) or `student.status` (Admin, Kesiswaan, Tata
     * Usaha), never the own account, and the target (when given) a student account. Without a target: may the actor
     * change student states at all (the buttons). `can()`: a database where RoleSeeder has not run yet answers "no".
     */
    public function changeStudentStatus(User $actor, ?User $target = null): bool
    {
        if (! $actor->can(Permission::UserArchive->value) && ! $actor->can(Permission::StudentStatus->value)) {
            return false;
        }

        return $target === null || (! $actor->is($target) && $target->student()->exists());
    }

    /** "Ganti Kepala Sekolah" (D-01, SECURITY §3m-9). */
    public function replacePrincipal(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserReplacePrincipal->value);
    }

    // --- B5: Admin › Arsip otomatis (SECURITY §3m-8) ---

    /** Archive overview (due soon, archived, history). */
    public function viewArchive(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserArchive->value);
    }

    /**
     * Restore an archived student account (with a new archive date and a reason): Admin › Arsip otomatis (`user.archive`),
     * and "Ubah status" of a student (`student.status`, changeStudentStatus(); 2026-11-05).
     */
    public function unarchive(User $actor, User $target): bool
    {
        return ($actor->can(Permission::UserArchive->value) || $actor->can(Permission::StudentStatus->value))
            && ! $actor->is($target)
            && $target->isArchived()
            && $target->student()->exists();
    }

    /** Move a not-yet-archived student's archive date (e.g. tinggal kelas). */
    public function changeArchiveDate(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserArchive->value)
            && ! $actor->is($target)
            && ! $target->isArchived()
            && $target->student()->exists();
    }

    // --- end B5 ---
}
