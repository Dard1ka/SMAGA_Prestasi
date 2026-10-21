<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Classroom;
use App\Models\User;

/**
 * Classes (Admin › Kelas, `admin.kelas.index`). Deny by default: every ability needs `classroom.manage`, which only
 * Admin holds (RoleName::defaultPermissions()). Reading a class name elsewhere (import, lists) needs no policy: the
 * name is not personal data. The rules of a delete (no student row left) live in DeleteClassroom.
 */
final class ClassroomPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function create(User $actor): bool
    {
        return $this->manages($actor);
    }

    public function update(User $actor, Classroom $classroom): bool
    {
        return $this->manages($actor);
    }

    public function delete(User $actor, Classroom $classroom): bool
    {
        return $this->manages($actor);
    }

    private function manages(User $actor): bool
    {
        // can(), not checkPermissionTo(): a database where RoleSeeder has not run yet answers "no", never a 500.
        return $actor->can(Permission::ClassroomManage->value);
    }
}
