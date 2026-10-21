<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Enums\RoleName;

/**
 * Privileged staff roles (SECURITY §3m-9): admin, kepala_sekolah, tata_usaha, kesiswaan, humas (Humas since D-139, when
 * it got `report.verify`). `kepala_sekolah` still moves only through "Ganti Kepala Sekolah" (PrincipalReplacement, 24 h).
 * Since 21 Okt 2026 (the lead) the other four are given at once by an Admin, like Guru (CreateStaffAccount,
 * RequestRoleChange → ChangeStaffRole): the second-Admin approval and the 24 h delay of backlog #13 / D-46 / D-61 are
 * gone for new grants; requests made before that day are still handled by Approve/Cancel/ApplyRoleChange.
 */
final class PrivilegedRoles
{
    /** @return list<RoleName> */
    public static function all(): array
    {
        return [RoleName::Admin, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Kesiswaan, RoleName::Humas];
    }

    public static function isPrivileged(RoleName $role): bool
    {
        return in_array($role, self::all(), true);
    }
}
