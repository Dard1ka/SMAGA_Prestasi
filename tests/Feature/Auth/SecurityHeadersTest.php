<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
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
        // M3-C: frames of this origin only (the preview popup); no blob:, data: or other origin.
        ->toMatch("/(^|; )frame-src 'self'(;|$)/")
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

it('keeps a frame permission that one response sets itself and still adds the other headers (M3-C inline PDF)', function (): void {
    Route::middleware('web')->get('/_test/own-frame', fn () => response('pdf')
        ->header('X-Frame-Options', 'SAMEORIGIN')
        ->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'"));

    $response = $this->get('/_test/own-frame');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->all('x-frame-options'))->toHaveCount(1);

    // The permission belongs to that answer only: the next page is locked again.
    $this->get('/_test/headers')->assertHeader('X-Frame-Options', 'DENY');
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

it('allows the Livewire style by its hash, so a wire:navigate page change brings it without a CSP error (QA8, #52)', function (): void {
    // wire:navigate puts the next page's <style data-livewire-style> into the open page with the next page's nonce,
    // which the open page's CSP does not know: one blocked style and one console error per page change. The content is
    // the same on every page, so every page's CSP allows it by its hash (the first page opened may be any page).
    Route::middleware('web')->get('/_test/livewire-styles', fn () => Blade::render('<!doctype html><html><head>@livewireStyles</head><body>ok</body></html>'));

    $page = $this->get('/_test/livewire-styles')->assertOk();
    preg_match('#<style[^>]*data-livewire-style[^>]*>(.*?)</style>#s', (string) $page->getContent(), $style);
    $hash = "'sha256-".base64_encode(hash('sha256', $style[1] ?? '', true))."'";

    expect($style)->toHaveCount(2)
        ->and((string) $page->headers->get('Content-Security-Policy'))->toMatch("/style-src 'self' 'nonce-[A-Za-z0-9]+' ".preg_quote($hash, '/').'(;|$)/')
        ->and((string) $this->get('/_test/headers')->headers->get('Content-Security-Policy'))->toContain($hash);
});
