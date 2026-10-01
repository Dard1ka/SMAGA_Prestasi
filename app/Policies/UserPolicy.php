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

    /** Student "Reset kata sandi & email" and staff temporary password. */
    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserResetPassword->value) && ! $actor->is($target);
    }

    /** Manual disable/archive with a reason (D-30). */
    public function archive(User $actor, User $target): bool
    {
        return $actor->checkPermissionTo(Permission::UserArchive->value) && ! $actor->is($target);
    }

    /** "Ganti Kepala Sekolah" (D-01, SECURITY §3m-9). */
    public function replacePrincipal(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserReplacePrincipal->value);
    }
}
