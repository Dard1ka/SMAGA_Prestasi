<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TrashEntry;
use App\Models\User;

/**
 * The trash of Admin › Kelas ("Sampah", the lead's decision of 5 Nov 2026). Deny by default: every ability needs
 * `classroom.manage`, the permission of Admin › Kelas, which only Admin holds (RoleName::defaultPermissions()), and an
 * account that can log in. The rules of a restore (before `purge_after`, not while the class is in the trash) live in
 * App\Actions\Trash\RestoreFromTrash.
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

    /** Put a class or students into the trash. */
    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    /** "Pulihkan". */
    public function restore(User $actor, TrashEntry $entry): bool
    {
        return $this->manages($actor);
    }

    private function manages(User $actor): bool
    {
        // can(), not checkPermissionTo(): a database where RoleSeeder has not run yet answers "no", never a 500.
        return $actor->can(Permission::ClassroomManage->value);
    }
}
