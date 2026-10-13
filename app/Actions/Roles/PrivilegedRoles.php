<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lead decision (backlog #13, SECURITY §3m-9): giving a staff account a privileged role needs a second Admin.
 * Privileged = admin, kepala_sekolah, tata_usaha, kesiswaan, humas (2FA-mandatory roles; Humas since D-139, when it got
 * `report.verify`). `kepala_sekolah` still moves only through "Ganti Kepala Sekolah" (PrincipalReplacement, 24 h); the
 * other four go through RequestRoleChange. An account that is Humas already keeps its role and access.
 * Taking a privileged role away (e.g. kesiswaan → guru) applies at once.
 */
final class PrivilegedRoles
{
    public const DELAY_HOURS = 24;

    /** @return list<RoleName> */
    public static function all(): array
    {
        return [RoleName::Admin, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Kesiswaan, RoleName::Humas];
    }

    public static function isPrivileged(RoleName $role): bool
    {
        return in_array($role, self::all(), true);
    }

    /** True when the change gives the account a privileged role it does not hold yet. */
    public static function needsApproval(User $target, RoleName $to): bool
    {
        return self::isPrivileged($to) && ! $target->hasRole($to->value);
    }

    /**
     * Admins who could approve: can log in, hold `user.manage`, and are none of the given accounts
     * (nobody approves their own request or their own promotion, SECURITY §3c-10).
     *
     * @return Collection<int, User>
     */
    public static function approvers(User ...$except): Collection
    {
        $ids = array_map(fn (User $user) => $user->getKey(), $except);

        return User::query()
            ->role(RoleName::Admin->value)
            ->permission(Permission::UserManage->value)
            ->canLogIn()
            ->whereKeyNot($ids)
            ->get();
    }
}
