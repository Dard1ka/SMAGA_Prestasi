<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Models\User;
use App\Providers\ErrorPageServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
| M2-C.
| - QA Q2-03: `GET /logout` (people type it) was a 405 that built the 870 kB debug page. It now redirects and never
|   logs anyone out; logging out stays POST + CSRF (Fortify).
| - QA Q2-17: after login the "intended" address may be a page the account may not open (a guest opened /admin,
|   then logs in as Kesiswaan). A 403/404 on that first page goes to the beranda instead.
*/

beforeEach(function (): void {
    H::seedRoles();
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();
});

// GET /logout -----------------------------------------------------------------------------------------

it('sends a guest who types /logout to the login page', function (bool $debug): void {
    config(['app.debug' => $debug]);

    $this->get('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
})->with(['debug off' => [false], 'debug on' => [true]]);

it('sends a signed-in user who types /logout to the beranda without logging out', function (): void {
    $student = User::query()->findOrFail(H::activeStudent()->user_id);

    $this->actingAs($student)->get('/logout')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student);
    $this->get(route('home'))->assertRedirect(route('siswa.beranda'));

    $staff = H::staff(RoleName::TataUsaha);
    $this->actingAs($staff)->get('/logout')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($staff);
});

it('still logs out with the POST form only', function (): void {
    $student = User::query()->findOrFail(H::activeStudent()->user_id);

    expect(route('logout.get'))->toBe(route('logout'))
        ->and(collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'logout')
            ->map(fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).':'.$route->getName())
            ->sort()->values()->all())->toBe(['GET:logout.get', 'POST:logout']);

    $this->actingAs($student)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

// First page after login ------------------------------------------------------------------------------

it('sends the first page after login to the beranda when the role may not open it', function (): void {
    H::staff(RoleName::Kesiswaan);

    // A guest opens the Admin area, is sent to the login and logs in as Kesiswaan.
    $this->get(route('admin.beranda'))->assertRedirect(route('login'));
    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('admin.beranda'));

    $this->get(route('admin.beranda'))->assertRedirect(route('home'));
    $this->get(route('home'))->assertRedirect(route('kesiswaan.beranda'));

    // Only that first page: opening the address again later is an ordinary 403.
    $this->get(route('admin.beranda'))->assertForbidden()->assertSee('Kamu tidak punya akses ke halaman ini.');
});

it('sends the first page after login to the beranda when it is not this user\'s record', function (): void {
    $student = H::activeStudent();
    $missing = '/berkas/01HZZZZZZZZZZZZZZZZZZZZZZZ';

    $this->get($missing)->assertRedirect(route('login'));
    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect($missing);

    $this->get($missing)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);

    $this->get($missing)->assertNotFound()->assertSee('Halaman tidak ditemukan.');
});

it('leaves an allowed first page and later errors alone', function (): void {
    H::staff(RoleName::Kesiswaan);

    $this->get(route('kesiswaan.lomba.index'))->assertRedirect(route('login'));
    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('kesiswaan.lomba.index'));

    $this->get(route('kesiswaan.lomba.index'))->assertOk();
    expect(session()->has(ErrorPageServiceProvider::AFTER_LOGIN))->toBeFalse();

    $this->get(route('admin.beranda'))->assertForbidden();
});

it('never redirects a form post or a JSON request after login', function (): void {
    $admin = H::staff(RoleName::Admin);

    $this->from('/login')->post('/login', ['identifier' => 'staf.admin@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);

    $this->getJson(route('kepsek.persetujuan.index'))->assertForbidden();
});
