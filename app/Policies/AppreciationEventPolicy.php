<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AppreciationEvent;
use App\Models\User;

/**
 * Appreciation events ("Upacara Senin, 5 Oktober 2026"). Deny by default; `appreciation.manage`
 * (Humas, Kesiswaan, Admin) on an account that can log in. Authorized by permission, not role, because
 * Kesiswaan mounts the same screen (kesiswaan.apresiasi.index).
 */
class AppreciationEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::AppreciationManage->value);
    }

    public function view(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Add achievements to the event, mark it done. */
    public function update(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user);
    }
}
