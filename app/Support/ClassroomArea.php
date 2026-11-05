<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;
use InvalidArgumentException;

/**
 * Which address family the Kelas page (App\Livewire\Admin\Kelas) is opened under (2026-11-05, the lead: Tata Usaha and
 * Kesiswaan get the tab Kelas "sama persis Admin"): `admin.kelas.index`, `kesiswaan.kelas.index` or `tu.kelas.index`.
 * Like VerificationArea, the area only decides links and return addresses (the password page, the shared dialogs), so a
 * Kesiswaan or Tata Usaha user is never sent to an `admin.*` address (403 there); it never grants anything (the
 * policies and the route middleware do).
 */
final class ClassroomArea
{
    public const ADMIN = 'admin';

    public const KESISWAAN = 'kesiswaan';

    public const TU = 'tu';

    public const AREAS = [self::ADMIN, self::KESISWAAN, self::TU];

    /** The area of the account's own menu: ADMIN for an Admin, else KESISWAAN, else TU. */
    public static function forUser(User $user): string
    {
        return match (true) {
            $user->hasRole(RoleName::Admin->value) => self::ADMIN,
            $user->hasRole(RoleName::Kesiswaan->value) => self::KESISWAAN,
            default => self::TU,
        };
    }

    /** `admin.*` / `kesiswaan.*` / `tu.*` → that area; anything else (a Livewire update, tests) → by role. */
    public static function resolve(?string $routeName, User $user): string
    {
        foreach (self::AREAS as $area) {
            if ($routeName !== null && str_starts_with($routeName, $area.'.')) {
                return $area;
            }
        }

        return self::forUser($user);
    }

    /** The route name of the Kelas page of this area. */
    public static function route(string $area): string
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new InvalidArgumentException('Unknown class area.');
        }

        return $area.'.kelas.index';
    }

    public static function indexUrl(string $area): string
    {
        return route(self::route($area));
    }
}
