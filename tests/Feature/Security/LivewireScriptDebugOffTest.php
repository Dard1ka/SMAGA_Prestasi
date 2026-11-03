<?php

declare(strict_types=1);

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Support\LivewireScript;
use Tests\Support\TalentGate;
use Tests\Support\WithAppDebugOff;

uses(RefreshDatabase::class, WithAppDebugOff::class);

/*
 | SV1 (2026-10-30): with APP_DEBUG=false (the school's server, production) every page asked for livewire.js while
 | Livewire had registered livewire.min.js, so the script answered 404, Livewire never started and no tab, filter,
 | dialog, download or save reacted (Status aktivasi › "Daftar siswa", Import siswa › "Download template Excel").
 | These tests boot the app with debug off and load the script the way a browser does: the address the page printed.
 */

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
});

it('boots with debug off', function (): void {
    expect(config('app.debug'))->toBeFalse();
});

it('registers only the minified script route when debug is off', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): string => '/'.ltrim($route->uri(), '/'))
        ->filter(fn (string $uri): bool => preg_match('#/livewire-[^/]+/livewire(\.min)?\.js$#', $uri) === 1)
        ->values()
        ->all();

    expect($uris)->toBe([EndpointResolver::scriptPath(minified: true)]);
});

it('prints the script address Livewire registered and serves the CSP build there', function (string $routeName, ?string $role): void {
    if ($role === 'siswa') {
        $student = F::student('Nadia Putri Anindya');
        TalentGate::pass($student); // D-153: else the talent gate holds every siswa page
        $this->actingAs($student->user);
    } elseif ($role !== null) {
        $this->actingAs(F::staff(RoleName::from($role)))->withSession(['auth.password_confirmed_at' => time()]);
    }

    $page = $this->get(route($routeName));
    $page->assertOk();

    $src = LivewireScript::src((string) $page->getContent());

    expect($src)->not->toBeNull()
        ->and(parse_url((string) $src, PHP_URL_PATH))->toBe(EndpointResolver::scriptPath(minified: true))
        ->and((string) $src)->toContain('build=csp');

    $script = $this->get((string) $src);

    $script->assertOk();
    expect(LivewireScript::body($script))->toContain('prohibited in the CSP build');
})->with([
    'publik peringkat (guest)' => ['public.peringkat', null],
    'login (auth layout)' => ['login', null],
    'kesiswaan status aktivasi' => ['kesiswaan.aktivasi.index', 'kesiswaan'],
    'kesiswaan import siswa' => ['kesiswaan.import', 'kesiswaan'],
    'admin pengaturan' => ['admin.pengaturan', 'admin'],
    'siswa beranda' => ['siswa.beranda', 'siswa'],
]);
