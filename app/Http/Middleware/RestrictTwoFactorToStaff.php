<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the `web` group. TOTP 2FA is for staff only (docs/SECURITY.md §3a): a student account answers 404 on every
 * route that manages it, i.e. Fortify's `two-factor.*` JSON routes (enable, confirm, disable, QR code, secret key,
 * recovery codes) and the setup screen `two-factor.setup`. Fortify itself only asks for `auth` + `password.confirm`,
 * so whoever knew a student's initial password could enrol a second factor before the owner activated the account
 * and lock the owner out at /two-factor-challenge.
 *
 * Matched by route name, deny by default: a new `two-factor.*` route is covered too. Only the login challenge
 * (`two-factor.login`, `two-factor.login.store`, guests) stays open.
 */
class RestrictTwoFactorToStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isManagementRoute($request)) {
            $user = $request->user();

            // Guests go on to `auth` (login redirect / 401), as before.
            abort_if($user !== null && ! self::allows($user), 404);
        }

        return $next($request);
    }

    public static function isManagementRoute(Request $request): bool
    {
        return $request->routeIs('two-factor.*') && ! $request->routeIs('two-factor.login', 'two-factor.login.store');
    }

    /**
     * Who may have TOTP: every account `staff.2fa` asks for it (no `siswa` role, which includes a new staff
     * account whose role still waits for approval), unless it belongs to a student record. The same test as
     * LoginIdentifier uses for "staff".
     */
    public static function allows(object $user): bool
    {
        return $user instanceof User
            && EnsureStaffTwoFactor::isStaff($user)
            && $user->student()->doesntExist();
    }
}
