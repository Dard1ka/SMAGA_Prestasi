<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WeightVersion;

/**
 * Point weights (D-08). Versions are append-only: there is no update/delete ability on purpose —
 * changing weights = create a new version (+ queued, audited recalculation).
 */
final class WeightVersionPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::WeightManage->value);
    }

    public function view(User $actor, WeightVersion $version): bool
    {
        return $actor->checkPermissionTo(Permission::WeightManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::WeightManage->value);
    }
}
