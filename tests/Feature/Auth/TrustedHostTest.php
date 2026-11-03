<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| TrustHosts / TrustProxies (bootstrap/app.php, docs/SECURITY.md §3e). Laravel skips TrustHosts in local/testing,
| so these tests switch the environment to production for one request and reset Symfony's static state after.
*/

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/host', fn () => 'ok');

    config(['app.url' => 'https://prestasi.sman3-smg.sch.id', 'app.debug' => false]);
    app()->detectEnvironment(fn () => 'production');
});

afterEach(function (): void {
    Request::setTrustedHosts([]);
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('rejects a foreign Host header in production', function (): void {
    expect($this->get('https://evil.example/_test/host')->status())->toBeIn([400, 404]);
});

it('rejects subdomains of the application host', function (): void {
    expect($this->get('https://x.prestasi.sman3-smg.sch.id/_test/host')->status())->toBeIn([400, 404]);
});

it('accepts the application host', function (): void {
    $this->get('https://prestasi.sman3-smg.sch.id/_test/host')->assertOk()->assertSee('ok');
});

it('ignores X-Forwarded-Host even from the trusted proxy', function (): void {
    Route::middleware('web')->get('/_test/host-name', fn (Request $request) => $request->getHost());

    $this->withServerVariables(['REMOTE_ADDR' => '172.30.10.1'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-For' => '203.0.113.9'])
        ->get('https://prestasi.sman3-smg.sch.id/_test/host-name')
        ->assertOk()
        ->assertSeeText('prestasi.sman3-smg.sch.id');
});

it('takes the client IP from X-Forwarded-For only when it comes from the trusted proxy', function (): void {
    Route::middleware('web')->get('/_test/ip', fn (Request $request) => $request->ip());

    $this->withServerVariables(['REMOTE_ADDR' => '172.30.10.1'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('https://prestasi.sman3-smg.sch.id/_test/ip')
        ->assertSeeText('203.0.113.9');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
        ->get('https://prestasi.sman3-smg.sch.id/_test/ip')
        ->assertSeeText('198.51.100.7');
});
