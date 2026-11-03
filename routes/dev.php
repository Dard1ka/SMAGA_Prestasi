<?php

declare(strict_types=1);

use App\Support\Dev\OpcacheWarmup;
use Illuminate\Support\Facades\Route;

/*
| Local-only routes (APP_ENV=local): design-system showcase and other developer pages.
| Never loaded in production (see bootstrap/app.php).
|
| /_dev/opcache/save|warm        used by docker/php/dev-cache.sh around a php-fpm reload (App\Support\Dev\OpcacheWarmup)
| /_dev/components               every <x-sth.*> component in the app shell (?role=kesiswaan … switches the sidebar)
| /_dev/components/public        layouts::public
| /_dev/components/auth          layouts::auth (split; ?variant=simple)
| /_dev/components/mobile        layouts::mobile
*/

Route::prefix('_dev')->name('dev.')->group(function (): void {
    Route::view('components', 'dev.components')->name('components');
    Route::view('components/public', 'dev.public')->name('components.public');
    Route::view('components/auth', 'dev.auth')->name('components.auth');
    Route::view('components/mobile', 'dev.mobile')->name('components.mobile');

    // Dev page speed (docker/php/dev-cache.sh): `save` before a php-fpm reload, `warm` right after it.
    Route::get('opcache/{action}', function (string $action) {
        @set_time_limit(120);

        return response()->json($action === 'save'
            ? ['saved' => OpcacheWarmup::save()]
            : OpcacheWarmup::warm((int) request()->query('part', '0'), (int) request()->query('of', '1')));
    })->whereIn('action', ['save', 'warm'])->name('opcache');
});
