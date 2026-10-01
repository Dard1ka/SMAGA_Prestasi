<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\User;

/**
 * Role rules for staff accounts managed in Admin › Pengguna & peran:
 * - `siswa` never (students come from the import, D-12/D-26);
 * - `kepala_sekolah` only through the "Ganti Kepala Sekolah" flow (D-01, SECURITY §3m-9);
 * - `admin` at most MAX_ADMINS accounts (2 named + 1 break-glass, SECURITY §3m-9).
 */
final class StaffRoles
{
    public const MAX_ADMINS = 3;

    /** @return list<RoleName> */
    public static function assignable(): array
    {
        return [RoleName::Guru, RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::Humas, RoleName::Admin];
    }

    public static function isAssignable(RoleName $role): bool
    {
        return in_array($role, self::assignable(), true);
    }

    public static function adminCount(?User $except = null): int
    {
        return User::query()
            ->role(RoleName::Admin->value)
            ->canLogIn()
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->count();
    }

    /** Username rule for staff logins (W1-C LoginIdentifier: username or email). */
    public const USERNAME_PATTERN = '/^[a-z][a-z0-9._-]{2,49}$/';
}
