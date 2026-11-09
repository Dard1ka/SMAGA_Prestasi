<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;

/**
 * SV8, D-199: the address of the screen "Revisi pendaftaran" for a decider. Tata Usaha opens it under `tu.`, Kesiswaan
 * under `kesiswaan.` (the same component, App\Livewire\Sekolah\RevisiPendaftaran), so each stays in its own menu, as
 * ApprovalQueue does for Persetujuan lomba. The address never grants anything: the route middleware and
 * RegistrationRevisionPolicy::viewQueue do.
 */
final class RevisionQueue
{
    public const TU = 'tu.revisi.index';

    public const KESISWAAN = 'kesiswaan.revisi.index';

    /** The badge key of the menu item in both menus (Navigation::BADGES, NavigationServiceProvider). */
    public const BADGE = 'revisi.pendaftaran';

    /** Tata Usaha when the account is Tata Usaha and not Kesiswaan, else Kesiswaan's address. */
    public static function routeName(User $user): string
    {
        return $user->hasRole(RoleName::TataUsaha->value) && ! $user->hasRole(RoleName::Kesiswaan->value)
            ? self::TU
            : self::KESISWAAN;
    }

    public static function url(User $user): string
    {
        return route(self::routeName($user));
    }
}
