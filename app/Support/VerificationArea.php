<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RoleName;
use App\Models\User;
use InvalidArgumentException;

/**
 * Which address family a verification screen (Verifikasi hasil) is opened under (D-139): Kesiswaan and Admin use
 * `kesiswaan.verifikasi.*`, Humas `humas.verifikasi.*`. The area decides the links, the address after a change of the
 * selected report and the badge keys; it never grants anything (the policy and the route middleware do).
 */
final class VerificationArea
{
    public const KESISWAAN = 'kesiswaan';

    public const HUMAS = 'humas';

    /** KESISWAAN when the account holds kesiswaan or admin, else HUMAS. */
    public static function forUser(User $user): string
    {
        return $user->hasAnyRole([RoleName::Kesiswaan->value, RoleName::Admin->value]) ? self::KESISWAAN : self::HUMAS;
    }

    /** `humas.*` → HUMAS, `kesiswaan.*` → KESISWAAN, anything else (no route, tests) → by role. */
    public static function resolve(?string $routeName, User $user): string
    {
        return match (true) {
            $routeName !== null && str_starts_with($routeName, 'humas.') => self::HUMAS,
            $routeName !== null && str_starts_with($routeName, 'kesiswaan.') => self::KESISWAAN,
            default => self::forUser($user),
        };
    }

    public static function indexUrl(string $area): string
    {
        return route(self::check($area).'.verifikasi.index');
    }

    public static function showUrl(string $area, string $reportId): string
    {
        return route(self::check($area).'.verifikasi.show', $reportId);
    }

    private static function check(string $area): string
    {
        if ($area !== self::KESISWAAN && $area !== self::HUMAS) {
            throw new InvalidArgumentException('Unknown verification area.');
        }

        return $area;
    }
}
