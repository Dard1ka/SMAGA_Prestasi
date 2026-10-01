<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/headers', fn () => 'ok');
});

it('sends CSP with a per-request nonce and the other security headers', function (): void {
    $first = $this->get('/_test/headers')->assertOk();
    $second = $this->get('/_test/headers');

    $csp = (string) $first->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/")
        ->and($csp)->not->toBe((string) $second->headers->get('Content-Security-Policy'));

    $first->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');

    expect((string) $first->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('geolocation=()');
});

it('keeps a CSP that a response sets itself (e.g. sandboxed downloads)', function (): void {
    Route::middleware('web')->get('/_test/own-csp', fn () => response('pdf')->header('Content-Security-Policy', 'sandbox'));

    $this->get('/_test/own-csp')->assertHeader('Content-Security-Policy', 'sandbox');
});

it('hardens the session cookie in production config', function (): void {
    $original = [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];

    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';

    try {
        $config = require base_path('config/session.php');
    } finally {
        $original[0] === false ? putenv('APP_ENV') : putenv('APP_ENV='.$original[0]);
        $_ENV['APP_ENV'] = $original[1];
        $_SERVER['APP_ENV'] = $original[2];
    }

    // Values only apply when SESSION_COOKIE / SESSION_SECURE_COOKIE are not set explicitly.
    if (getenv('SESSION_COOKIE') === false) {
        expect($config['cookie'])->toBe('__Host-sth_session');
    }

    if (getenv('SESSION_SECURE_COOKIE') === false) {
        expect($config['secure'])->toBeTrue();
    }

    expect($config['same_site'])->toBe('lax')
        ->and($config['http_only'])->toBeTrue()
        ->and($config['path'])->toBe('/');
});
