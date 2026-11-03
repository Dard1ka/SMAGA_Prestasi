<?php

declare(strict_types=1);

use App\Support\Dev\OpcacheWarmup;
use Illuminate\Support\Facades\Route;

// The test process runs on the command line, where opcache is off: both steps must then do nothing and never
// fail, and the routes must exist only through routes/dev.php (loaded when APP_ENV=local, bootstrap/app.php).

it('does nothing when opcache is off or no list was saved', function (): void {
    $before = is_file(OpcacheWarmup::listPath()) ? (string) file_get_contents(OpcacheWarmup::listPath()) : null;

    expect(OpcacheWarmup::save())->toBe(0);

    $after = is_file(OpcacheWarmup::listPath()) ? (string) file_get_contents(OpcacheWarmup::listPath()) : null;
    expect($after)->toBe($before);

    $result = OpcacheWarmup::warm();
    expect($result)->toHaveKeys(['compiled', 'skipped'])
        ->and($result['compiled'])->toBe(0);
});

it('answers save and warm on the local-only route and nothing else', function (): void {
    Route::middleware('web')->group(base_path('routes/dev.php'));

    $this->get('/_dev/opcache/save')->assertOk()->assertExactJson(['saved' => 0]);
    $this->get('/_dev/opcache/warm')->assertOk()->assertJsonStructure(['compiled', 'skipped']);
    $this->get('/_dev/opcache/reset')->assertNotFound();
});

it('has no dev routes outside the local environment', function (): void {
    expect(Route::has('dev.opcache'))->toBeFalse();
    $this->get('/_dev/opcache/warm')->assertNotFound();
});
