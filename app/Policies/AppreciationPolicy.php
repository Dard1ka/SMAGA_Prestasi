<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Appreciation;
use App\Models\User;

/**
 * Apresiasi rows (an achievement on an appreciation event). Deny by default; `appreciation.manage`
 * (Humas, Kesiswaan, Admin) on an account that can log in.
 */
class AppreciationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::AppreciationManage->value);
    }

    public function view(User $user, Appreciation $appreciation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Appreciation $appreciation): bool
    {
        return $this->viewAny($user);
    }
}
