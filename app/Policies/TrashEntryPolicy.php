<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TrashEntry;
use App\Models\User;

/**
 * The trash of Admin › Kelas ("Sampah", the lead's decision of 5 Nov 2026). Deny by default: every ability needs
 * `classroom.manage`, the permission of the Kelas page (Admin; since 2026-11-05 also Kesiswaan and Tata Usaha,
 * RoleName::defaultPermissions()), and an account that can log in. The rules of a restore (before `purge_after`, not while the class is in the trash) live in
 * App\Actions\Trash\RestoreFromTrash.
 *
 * SV6, D-185 (the lead, 5 Nov 2026): putting STUDENTS into the trash (trashStudents(), App\Actions\Trash\TrashStudents)
 * also takes `student.trash`, which Kesiswaan holds. A class into the trash (create()), the Sampah list (viewAny()) and
 * "Pulihkan" (restore()) and "Hapus permanen" (forceDelete()) stay `classroom.manage`.
 */
final class TrashEntryPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    /** See the Sampah list. */
    public function viewAny(User $actor): bool
    {
        return $this->manages($actor);
    }

    /** Put a class (with its students) into the trash. */
    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    /**
     * Put students into the trash (SV6, D-185): `student.trash` (Kesiswaan, Admin) or `classroom.manage` (Admin, also on a
     * database where RoleSeeder has not given the Admin role the new permission yet).
     */
    public function trashStudents(User $actor): bool
    {
        return $actor->can(Permission::StudentTrash->value) || $this->manages($actor);
    }

    /** "Pulihkan". */
    public function restore(User $actor, TrashEntry $entry): bool
    {
        return $this->manages($actor);
    }

    /** "Hapus permanen" now, before `purge_after` (SV6, D-186, App\Actions\Trash\PurgeFromTrash): `classroom.manage`. */
    public function forceDelete(User $actor, TrashEntry $entry): bool
    {
        return $this->manages($actor);
    }

    private function manages(User $actor): bool
    {
        // can(), not checkPermissionTo(): a database where RoleSeeder has not run yet answers "no", never a 500.
        return $actor->can(Permission::ClassroomManage->value);
    }
}
