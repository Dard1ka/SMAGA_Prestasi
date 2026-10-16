<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureActivated;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\EnsureTalentChosen;
use App\Http\Middleware\PreventCredentialPageCaching;
use App\Http\Middleware\RememberLoggedOutAccount;
use App\Http\Middleware\RestrictTwoFactorToStaff;
use App\Http\Middleware\SecurityHeaders;
use App\Providers\ErrorPageServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // One route file per area so teams/agents do not edit the same file (see docs/ARCHITECTURE.md §3).
            Route::middleware('web')->group(base_path('routes/siswa.php'));
            Route::middleware('web')->group(base_path('routes/staff.php'));
            Route::middleware('web')->group(base_path('routes/admin.php'));

            if (app()->environment('local')) {
                Route::middleware('web')->group(base_path('routes/dev.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Owner: W1-C (auth). Aliases and groups are documented in docs/dev-notes/W1-C.md.

        // Apache (host) → 127.0.0.1:8080 → docker-proxy → nginx → php-fpm: REMOTE_ADDR = gateway of the compose
        // network `edge` (docs/dev-notes/W1-E.md). Never trust X-Forwarded-Host/-Prefix (SECURITY §3e).
        $middleware->trustProxies(
            at: [env('STH_TRUSTED_PROXY', '172.30.10.1')],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Only the APP_URL host (prestasi.sman3-smg.sch.id in production). Laravel skips this in local/testing.
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'],
            subdomains: false,
        );

        $middleware->append(SecurityHeaders::class);

        // M2-A (QA Q2-01): `Cache-Control: no-store` on the login, activation, password and 2FA pages, so Back
        // never shows a page with typed credentials again. Global because Fortify registers its own routes.
        $middleware->append(PreventCredentialPageCaching::class);

        // Disabled/archived accounts lose their session on the next request (D-29, D-30).
        // TOTP management (Fortify's two-factor.* routes, /akun/2fa) is staff only: 404 for student accounts.
        // RememberLoggedOutAccount comes first so it also sees a logout done by the middleware after it: the next
        // account that logs in on this browser never lands on the previous account's page.
        $middleware->web(append: [RememberLoggedOutAccount::class, EnsureAccountActive::class, RestrictTwoFactorToStaff::class]);

        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'activated' => EnsureActivated::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
            'talent.chosen' => EnsureTalentChosen::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Convenience stacks for area route files: ->middleware('area.siswa') / ->middleware(['area.staff', 'role:guru']).
        // D-144: `talent.chosen` last, after `activated` and `role:siswa` (a kelas X student without a talent is held on
        // Profil talenta; it never runs for staff or before the activation).
        $middleware->group('area.siswa', ['auth', 'active', 'activated', 'role:siswa', 'talent.chosen']);
        $middleware->group('area.staff', ['auth', 'active', 'activated', 'staff.2fa']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // M2-C: HTTP errors render resources/views/errors/<status>.blade.php (also with APP_DEBUG=true, 405 included).
        // Only exception: a 403/404 on the first page after login goes to the beranda (QA Q2-17).
        $exceptions->render(
            fn (HttpExceptionInterface $e, Request $request) => ErrorPageServiceProvider::afterLogin($e, $request),
        );
    })->create();
