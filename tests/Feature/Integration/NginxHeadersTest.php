<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| W3-A: App\Http\Middleware\SecurityHeaders is the single source of the security headers. nginx (dev + prod
| container) must not add them again (responses carried X-Frame-Options/nosniff/Referrer-Policy twice), but
| keeps server_tokens off, the body size limit and the dotfile block.
*/

it('keeps security headers out of the nginx configs', function (string $file): void {
    $conf = (string) file_get_contents(base_path($file));

    expect($conf)->not->toMatch('/^\s*add_header\s+(X-Content-Type-Options|X-Frame-Options|Referrer-Policy|Permissions-Policy|Content-Security-Policy|Cross-Origin-Opener-Policy)\b/mi')
        ->and($conf)->toMatch('/^\s*server_tokens\s+off;/m')
        ->and($conf)->toMatch('/^\s*client_max_body_size\s+\d+m;/m')
        ->and($conf)->toContain('location ~ /\.(?!well-known');
})->with(['docker/nginx/default.conf', 'docker/nginx/prod.conf']);

it('has no signed storage route for the private disk (downloads only through FileController)', function (): void {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('files.show'))->toBeTrue()
        ->and(Route::has('files.public'))->toBeTrue();
});

it('sends each security header exactly once from the app', function (): void {
    $response = $this->get('/up');

    foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy'] as $name) {
        expect($response->headers->all(strtolower($name)))->toHaveCount(1);
    }
});
