<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\RoleChangeRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Role rules for staff accounts managed in Admin › Pengguna & peran:
 * - `siswa` never (students come from the import, D-12/D-26);
 * - `kepala_sekolah` only through the "Ganti Kepala Sekolah" flow (D-01, SECURITY §3m-9);
 * - `admin` at most MAX_ADMINS accounts (2 named + 1 break-glass, SECURITY §3m-9).
 * Since 21 Okt 2026 (the lead: no second Admin, no waiting) every assignable role is given at once by CreateStaffAccount
 * and ChangeStaffRole; RoleChangeRequest rows made before that day may still be pending (assertAdminRoom() counts them).
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

    /**
     * The 3-Admin limit for giving `$locked` the Admin role (D-41). Call inside the transaction that gives the role:
     * it row-locks the Admin accounts. A pending Admin request of another account made before 21 Okt 2026 still holds
     * a place (it may still be approved or apply by itself).
     *
     * @throws ValidationException
     */
    public static function assertAdminRoom(User $locked): void
    {
        User::query()->role(RoleName::Admin->value)->lockForUpdate()->get();

        $pending = RoleChangeRequest::query()->pending()
            ->where('to_role', RoleName::Admin->value)
            ->where('user_id', '!=', $locked->getKey())
            ->count();

        if (self::adminCount($locked) + $pending >= self::MAX_ADMINS) {
            throw ValidationException::withMessages(['role' => $pending > 0
                ? 'Sudah ada '.self::MAX_ADMINS.' akun Admin (termasuk yang menunggu).'
                : 'Sudah ada '.self::MAX_ADMINS.' akun Admin.']);
        }
    }

    /** Username rule for staff logins (W1-C LoginIdentifier: username or email). */
    public const USERNAME_PATTERN = '/^[a-z][a-z0-9._-]{2,49}$/';
}
