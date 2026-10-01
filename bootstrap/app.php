<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureActivated;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

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

        // Disabled/archived accounts lose their session on the next request (D-29, D-30).
        $middleware->web(append: [EnsureAccountActive::class]);

        $middleware->alias([
            'active' => EnsureAccountActive::class,
            'activated' => EnsureActivated::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Convenience stacks for area route files: ->middleware('area.siswa') / ->middleware(['area.staff', 'role:guru']).
        $middleware->group('area.siswa', ['auth', 'active', 'activated', 'role:siswa']);
        $middleware->group('area.staff', ['auth', 'active', 'activated', 'staff.2fa']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
