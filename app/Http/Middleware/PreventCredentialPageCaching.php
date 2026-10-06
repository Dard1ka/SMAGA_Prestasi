<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No copy of a credential page may stay in the browser (QA of 2026-10-05, Q2-01: after "Keluar", Back brought the
 * old login page back with NIS and password still filled in, on a shared lab computer).
 *
 * Every answer for the paths below, GET and POST alike, carries `Cache-Control: no-store` (and `Pragma: no-cache`
 * for old proxies), so the browser neither keeps the page for Back/Forward nor restores what was typed into it.
 *
 * Why this is needed: Laravel answers an HTML page with Symfony's default `no-cache, private`, which still lets
 * the browser keep the page in its back/forward cache. Livewire adds `no-store` by itself, but only to a response
 * that rendered a Livewire component (DisableBackButtonCacheMiddleware). `/login`, `/user/confirm-password` and
 * `/two-factor-challenge` are plain Blade views of Fortify, so they never got it; `/aktivasi`, `/lupa-kata-sandi`
 * and `/akun/2fa` are Livewire pages and are listed so the rule does not depend on that side effect.
 *
 * Global (bootstrap/app.php, `$middleware->append()`): Fortify registers its own routes, and a redirect or an error
 * page for these paths must not be stored either. resources/js/app.js empties the password fields as a second line.
 */
final class PreventCredentialPageCaching
{
    /** Request paths (no leading slash) that show or take a password, a one-time code or a 2FA secret. */
    public const PATHS = [
        'login',
        'aktivasi',
        'lupa-kata-sandi',
        'user/confirm-password',
        'two-factor-challenge',
        'akun/2fa',
    ];

    public const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0, private';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->is(...self::PATHS)) {
            $response->headers->set('Cache-Control', self::CACHE_CONTROL);
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
