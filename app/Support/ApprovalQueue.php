<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;

/**
 * Which address an approver's Persetujuan screen has (D-150): the Kepala Sekolah opens `kepsek.persetujuan.index`,
 * Tata Usaha the same screen (`Kepsek\Persetujuan`) under `tu.persetujuan.index`, so each stays in its own area menu.
 * Links in notices to an approver use it per recipient. The address never grants anything: the route middleware and
 * RegistrationPolicy::viewApprovalQueue do.
 */
final class ApprovalQueue
{
    public const KEPSEK = 'kepsek.persetujuan.index';

    public const TU = 'tu.persetujuan.index';

    /** TU when the account is Tata Usaha and not Kepala Sekolah, else the Kepala Sekolah's address. */
    public static function routeName(User $user): string
    {
        return $user->hasRole(RoleName::TataUsaha->value) && ! $user->hasRole(RoleName::KepalaSekolah->value)
            ? self::TU
            : self::KEPSEK;
    }

    public static function url(User $user): string
    {
        return route(self::routeName($user));
    }
}
