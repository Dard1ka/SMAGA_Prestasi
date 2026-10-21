<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureStaffTwoFactor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ProcessEnv;

/*
| The two runtime switches of the server (env, read at boot): STH_STAFF_2FA (config fortify.sth.staff_2fa) and
| STH_DEV_PAGES (config sth.dev_pages). Both default to true, and phpunit.xml forces true, so the rest of the suite
| always runs with today's behaviour, also when a developer's .env turns them off.
|
| /_dev only exists with APP_ENV=local, which Tests\TestCase refuses on purpose. These tests therefore boot a second,
| separate application with ProcessEnv::bootApp() and look at its routes only (no request, no database through it);
| a route that does not match is exactly the NotFoundHttpException the router turns into the 404 page.
| The "off" state of STH_STAFF_2FA is tested in tests/Feature/Auth/StaffTwoFactorSwitchTest.php.
*/

function devRouteMatches(Application $app, string $uri = '/_dev/components'): bool
{
    try {
        $app['router']->getRoutes()->match(Request::create($uri, 'GET'));

        return true;
    } catch (NotFoundHttpException) {
        return false;
    }
}

it('keeps 2FA and the developer pages on by default', function (): void {
    expect(config('fortify.sth.staff_2fa'))->toBeTrue()
        ->and(EnsureStaffTwoFactor::featureEnabled())->toBeTrue()
        ->and(Features::enabled(Features::twoFactorAuthentication()))->toBeTrue()
        ->and(Route::has('two-factor.login'))->toBeTrue()
        ->and(Route::has('two-factor.enable'))->toBeTrue()
        ->and(Route::has('two-factor.setup'))->toBeTrue()
        ->and(config('sth.dev_pages'))->toBeTrue();
});

it('reads both switches from the env, "false" as off', function (): void {
    $before = [getenv('STH_STAFF_2FA'), getenv('STH_DEV_PAGES')];

    $fortify = ProcessEnv::with(['STH_STAFF_2FA' => 'false'], fn (): array => require config_path('fortify.php'));
    $sth = ProcessEnv::with(['STH_DEV_PAGES' => 'false'], fn (): array => require config_path('sth.php'));

    expect($fortify['sth']['staff_2fa'])->toBeFalse()
        ->and($fortify['features'])->toBe([])
        ->and($sth['dev_pages'])->toBeFalse()
        // put back: this process still reads the forced defaults
        ->and([getenv('STH_STAFF_2FA'), getenv('STH_DEV_PAGES')])->toBe($before)
        ->and((require config_path('fortify.php'))['sth']['staff_2fa'])->toBeTrue()
        ->and((require config_path('sth.php'))['dev_pages'])->toBeTrue();
});

it('loads /_dev in local only while STH_DEV_PAGES is not false', function (): void {
    $on = ProcessEnv::bootApp(['APP_ENV' => 'local']);
    $off = ProcessEnv::bootApp(['APP_ENV' => 'local', 'STH_DEV_PAGES' => 'false']);

    expect($on->environment())->toBe('local')
        ->and($off->environment())->toBe('local')
        ->and(devRouteMatches($on))->toBeTrue()
        ->and(devRouteMatches($off))->toBeFalse()
        ->and(devRouteMatches($off, '/_dev/components/public'))->toBeFalse()
        ->and(devRouteMatches($off, '/_dev/opcache/save'))->toBeFalse()
        // the test's own application is untouched
        ->and(app()->environment())->toBe('testing');
});

it('never loads /_dev outside local, whatever STH_DEV_PAGES says', function (): void {
    expect(devRouteMatches(ProcessEnv::bootApp(['STH_DEV_PAGES' => 'true'])))->toBeFalse()
        ->and(devRouteMatches(app()))->toBeFalse();
});
