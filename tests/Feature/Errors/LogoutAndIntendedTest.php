<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Middleware\RememberLoggedOutAccount;
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
    $this->get(route('admin.beranda'))->assertForbidden()->assertSee('Tidak ada akses ke halaman ini.');
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

// The page the previous account left behind -----------------------------------------------------------

/*
| QA of 2026-10-06: after "Keluar", Back asks for the old page again; the guest is sent to the login and that
| address becomes the intended one. The Admin who logged in next on the same computer landed on /kesiswaan.
| App\Http\Middleware\RememberLoggedOutAccount: a different account goes to its beranda, the same account still
| gets its page back.
*/

it('does not send the next account to the page the previous account left behind', function (): void {
    $kesiswaan = H::staff(RoleName::Kesiswaan);
    $admin = H::staff(RoleName::Admin);

    $this->actingAs($kesiswaan)->post('/logout')->assertRedirect('/');
    expect(session(RememberLoggedOutAccount::SESSION_KEY))->toBe($kesiswaan->getKey());

    // Back: the browser asks for the old page again, now as a guest.
    $this->get(route('kesiswaan.lomba.index'))->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(route('kesiswaan.lomba.index'));

    // The Admin may open that page, but it is not the page the Admin asked for.
    $this->from('/login')->post('/login', ['identifier' => 'staf.admin@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);

    expect(session()->has('url.intended'))->toBeFalse()
        ->and(session()->has(RememberLoggedOutAccount::SESSION_KEY))->toBeFalse();
});

it('still brings the same account back to its page after it logged out', function (): void {
    $kesiswaan = H::staff(RoleName::Kesiswaan);

    $this->actingAs($kesiswaan)->post('/logout')->assertRedirect('/');
    $this->get(route('kesiswaan.lomba.index'))->assertRedirect(route('login'));

    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('kesiswaan.lomba.index'));
    expect(session()->has(RememberLoggedOutAccount::SESSION_KEY))->toBeFalse();
});

it('forgets the left-behind page when the next login ends with the 2FA challenge', function (): void {
    $kesiswaan = H::staff(RoleName::Kesiswaan);
    $admin = H::staff(RoleName::Admin);
    $admin->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($kesiswaan)->post('/logout')->assertRedirect('/');
    $this->get(route('kesiswaan.lomba.index'))->assertRedirect(route('login'));

    $this->post('/login', ['identifier' => 'staf.admin@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'aaaa-bbbb'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);
});

it('remembers an account that the app itself logged out', function (): void {
    $student = User::query()->findOrFail(H::activeStudent()->user_id);
    $admin = H::staff(RoleName::Admin);

    $this->actingAs($student);
    $student->forceFill(['status' => UserStatus::Disabled])->save();

    $this->get(route('siswa.beranda'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(session(RememberLoggedOutAccount::SESSION_KEY))->toBe($student->getKey());

    $this->get(route('siswa.beranda'))->assertRedirect(route('login'));
    $this->from('/login')->post('/login', ['identifier' => 'staf.admin@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);
});

it('keeps the intended page in a browser nobody logged out of', function (): void {
    H::staff(RoleName::Kesiswaan);

    $this->get(route('kesiswaan.lomba.index'))->assertRedirect(route('login'));
    expect(session()->has(RememberLoggedOutAccount::SESSION_KEY))->toBeFalse();

    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('kesiswaan.lomba.index'));
});

it('never redirects a form post or a JSON request after login', function (): void {
    $admin = H::staff(RoleName::Admin);

    $this->from('/login')->post('/login', ['identifier' => 'staf.admin@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($admin);

    $this->getJson(route('kepsek.persetujuan.index'))->assertForbidden();
});

// A cover picture is not a page (D-118) ------------------------------------------------------------------

/*
| UI3: lomba cards show the poster with <img src="/berkas/sampul/{file}">. A catalog left open after the session
| ended keeps asking for those pictures; the guest redirect stores the last one as the intended address. The next
| login must open the beranda, not a bare picture. Any other intended address is kept.
*/

it('never sends a login to a cover picture', function (): void {
    $kesiswaan = H::staff(RoleName::Kesiswaan);
    $cover = route('files.cover', ['file' => '01m48q6222n2w5rv56btxa3v42']);

    $this->get($cover)->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe($cover);

    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($kesiswaan);

    expect(session()->has('url.intended'))->toBeFalse();
});
