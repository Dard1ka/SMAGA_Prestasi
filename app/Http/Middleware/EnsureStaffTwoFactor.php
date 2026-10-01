<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `staff.2fa`. Staff (every role except `siswa`) must confirm TOTP 2FA before entering a role area
 * (docs/SECURITY.md §3a, §3m-9). Switch off locally with STH_REQUIRE_STAFF_2FA=false.
 */
class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! config('fortify.sth.require_staff_2fa', true)) {
            return $next($request);
        }

        if (! self::requiresSetup($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Aktifkan verifikasi dua langkah dulu.');
        }

        return redirect()->route('two-factor.setup');
    }

    public static function isStaff(object $user): bool
    {
        return ! (method_exists($user, 'hasRole') && $user->hasRole(RoleName::Siswa->value));
    }

    public static function requiresSetup(object $user): bool
    {
        return self::isStaff($user) && $user->two_factor_confirmed_at === null;
    }
}
