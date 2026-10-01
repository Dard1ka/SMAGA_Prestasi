<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RoleChangeRequest;
use App\Models\User;

/**
 * Privileged role changes waiting for a second Admin or the 24 h delay (backlog #13, owner B5). Deny by default;
 * disabled/archived accounts can never act. Nobody approves their own request or their own promotion
 * (SECURITY §3c-10); any Admin may cancel while it is pending.
 */
final class RoleChangeRequestPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value);
    }

    public function approve(User $actor, RoleChangeRequest $request): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value)
            && $request->isPending()
            && $request->approved_at === null
            && (int) $request->requested_by !== (int) $actor->getKey()
            && (int) $request->user_id !== (int) $actor->getKey();
    }

    public function cancel(User $actor, RoleChangeRequest $request): bool
    {
        return $actor->checkPermissionTo(Permission::UserManage->value) && $request->isPending();
    }
}
