<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PrincipalReplacement;
use App\Models\User;

/**
 * "Ganti akun Kepala Sekolah" requests (SECURITY §3m-9): Admins see and cancel them; the current
 * Kepala Sekolah (the account being replaced) may cancel a pending one too.
 */
final class PrincipalReplacementPolicy
{
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserReplacePrincipal->value);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::UserReplacePrincipal->value);
    }

    public function cancel(User $actor, PrincipalReplacement $replacement): bool
    {
        if (! $replacement->isPending()) {
            return false;
        }

        return $actor->checkPermissionTo(Permission::UserReplacePrincipal->value)
            || ($replacement->from_user_id !== null && (int) $replacement->from_user_id === (int) $actor->getKey());
    }
}
