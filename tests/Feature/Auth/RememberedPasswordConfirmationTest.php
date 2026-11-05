<?php

declare(strict_types=1);

use App\Actions\Accounts\ChangePassword;
use App\Actions\Fortify\RememberedPasswordConfirmation as Remembered;
use App\Enums\RoleName;
use App\Http\Middleware\RestoreRememberedPasswordConfirmation;
use App\Livewire\Admin\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 | The lead's request of 2026-11-05: TU, Admin and the other staff were asked for their password again on
 | "Konfirmasi kata sandi" right after logging in. The password typed at the login (or on that page) is now
 | remembered in the browser for 30 days, bound to the account and its current password. Students keep today's rule.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    $this->withoutVite();
    // Staff without TOTP may use the staff area (as on the lead's instances, STH_STAFF_2FA=false).
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->admin = H::staff(RoleName::Admin, ['username' => 'operator']);
});

function rpcLogin(string $identifier, string $password = H::PASSWORD): TestResponse
{
    $response = test()->from('/login')->post('/login', ['identifier' => $identifier, 'password' => $password]);

    // The cookie jar lives on in the test process; in a real request it starts empty.
    app('cookie')->flushQueuedCookies();

    return $response;
}

/** The decrypted cookie value a response set (what the browser keeps), or null. */
function rpcCookie(TestResponse $response): ?string
{
    $cookie = $response->getCookie(Remembered::COOKIE);

    return $cookie !== null && $cookie->getExpiresTime() > now()->getTimestamp() ? (string) $cookie->getValue() : null;
}

it('sets an httpOnly, SameSite=Lax cookie for 30 days when staff log in with their password', function (): void {
    $response = rpcLogin('operator')->assertRedirect(route('home'));

    $cookie = $response->getCookie(Remembered::COOKIE, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->getTimestamp() + 5)
        // Laravel encrypts it: neither the account id nor anything of the password is readable in the browser.
        ->and($cookie->getValue())->not->toContain('"u"');

    expect(Remembered::validFor($this->admin, (string) rpcCookie($response)))->toBeTrue();
});

it('marks the cookie Secure on https', function (): void {
    $response = $this->from('https://localhost/login')
        ->post('https://localhost/login', ['identifier' => 'operator', 'password' => H::PASSWORD]);

    expect($response->getCookie(Remembered::COOKIE, false)?->isSecure())->toBeTrue();
});

it('never sets it for a student, at the login or on the password page', function (): void {
    $student = H::activeStudent();

    $login = rpcLogin(H::NISN)->assertRedirect(route('home'));
    expect($login->getCookie(Remembered::COOKIE))->toBeNull();
    $this->assertAuthenticatedAs($student->user);

    $confirm = $this->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => H::PASSWORD]);
    $confirm->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    expect($confirm->getCookie(Remembered::COOKIE))->toBeNull();
});

it('sets nothing for a wrong password', function (): void {
    $response = rpcLogin('operator', 'salah-sekali');

    $this->assertGuest();
    expect($response->getCookie(Remembered::COOKIE))->toBeNull();
});

it('asked for the password right after the login before, and no longer does in the browser that holds the cookie', function (): void {
    // The test client does not keep cookies, so this is a browser without it (a new device): the page still asks.
    $value = rpcCookie(rpcLogin('operator')->assertRedirect(route('home')));
    $this->get(route('admin.pengguna.index'))->assertRedirect(route('password.confirm'));

    // Keluar does not remove it; the same account logs in again in a browser that kept the cookie.
    $this->post('/logout')->assertCookieMissing(Remembered::COOKIE);
    $this->assertGuest();

    $this->withCookie(Remembered::COOKIE, (string) $value);
    rpcLogin('operator')->assertRedirect(route('home'));

    $this->get(route('admin.pengguna.index'))->assertOk();
    $this->get(route('admin.pengaturan'))->assertOk();
});

it('lets an action behind RequiresRecentPassword run long after the login (Admin › Log audit download)', function (): void {
    $value = rpcCookie(rpcLogin('operator'));

    $this->travel(2)->hours();

    // Without the cookie the 15-minute check of the action sends the user to the password page.
    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->call('export')
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    // Any request of the browser that holds the cookie (a page, a Livewire update: both run the `web` group).
    $this->withCookie(Remembered::COOKIE, (string) $value)->get(route('admin.audit.index'))->assertOk();

    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->call('export')
        ->assertNoRedirect()
        ->assertFileDownloaded();
});

it('runs inside every Livewire update', function (): void {
    expect(app('router')->getMiddlewareGroups()['web'])->toContain(RestoreRememberedPasswordConfirmation::class)
        ->and(app('router')->getRoutes()->getByName('livewire.update')?->gatherMiddleware())->toContain('web');
});

it('sets it when staff pass the password page, and the screen that asked opens', function (): void {
    $this->actingAs($this->admin)->get(route('admin.pengguna.index'))->assertRedirect(route('password.confirm'));

    $confirm = $this->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => H::PASSWORD])
        ->assertRedirect(route('admin.pengguna.index'));

    $value = rpcCookie($confirm);
    expect($value)->not->toBeNull()
        ->and(Remembered::validFor($this->admin, (string) $value))->toBeTrue();

    // A new session of the same account in that browser (after the 3 h window of `password.confirm`).
    $this->travel(4)->hours();
    session()->flush();

    $this->withCookie(Remembered::COOKIE, (string) $value)->actingAs($this->admin)
        ->get(route('admin.pengguna.index'))->assertOk();
});

it('gives another account nothing from the cookie, and removes it', function (): void {
    $value = rpcCookie(rpcLogin('operator'));
    $other = H::staff(RoleName::Admin, ['username' => 'operator.dua', 'email' => 'operator.dua@example.test']);

    $this->withCookie(Remembered::COOKIE, (string) $value)->actingAs($other)
        ->get(route('admin.pengguna.index'))
        ->assertRedirect(route('password.confirm'))
        ->assertCookieExpired(Remembered::COOKIE);

    expect(Remembered::validFor($other, (string) $value))->toBeFalse();
});

it('ends with a password change (own change or an Admin reset)', function (): void {
    $value = rpcCookie(rpcLogin('operator'));

    app(ChangePassword::class)->handle($this->admin->fresh(), H::PASSWORD, 'Kuda-Lumping-Sore-2026');

    $this->withCookie(Remembered::COOKIE, (string) $value)->actingAs($this->admin->fresh())
        ->get(route('admin.pengguna.index'))
        ->assertRedirect(route('password.confirm'))
        ->assertCookieExpired(Remembered::COOKIE);

    // An Admin reset writes a new hash the same way.
    $value = rpcCookie(rpcLogin('operator', 'Kuda-Lumping-Sore-2026'));
    $this->admin->fresh()->forceFill(['password' => Hash::make('Sementara-Baru-77')])->save();

    expect(Remembered::validFor($this->admin->fresh(), (string) $value))->toBeFalse();
});

it('asks again after 30 days', function (): void {
    $value = rpcCookie(rpcLogin('operator'));

    $this->travel(Remembered::LIFETIME_DAYS)->days();
    $this->travel(1)->minutes();
    session()->flush();

    $this->withCookie(Remembered::COOKIE, (string) $value)->actingAs($this->admin)
        ->get(route('admin.pengguna.index'))
        ->assertRedirect(route('password.confirm'))
        ->assertCookieExpired(Remembered::COOKIE);
});

it('ignores a tampered cookie', function (string $tampered): void {
    $this->withCookie(Remembered::COOKIE, $tampered)->actingAs($this->admin)
        ->get(route('admin.pengguna.index'))
        ->assertRedirect(route('password.confirm'));
})->with([
    'not json' => ['abc'],
    'another id' => [fn (): string => (string) json_encode(['u' => 999, 'e' => now()->getTimestamp() + 3600, 'p' => str_repeat('a', 64)])],
    'own id, made-up fingerprint' => [fn (): string => (string) json_encode(['u' => User::query()->where('username', 'operator')->value('id'), 'e' => now()->getTimestamp() + 86400, 'p' => str_repeat('0', 64)])],
]);

it('ignores a cookie the browser could not have got from the app (not encrypted by it)', function (): void {
    $value = rpcCookie(rpcLogin('operator'));

    $this->withUnencryptedCookie(Remembered::COOKIE, (string) $value)->actingAs($this->admin)
        ->get(route('admin.pengguna.index'))
        ->assertRedirect(route('password.confirm'));
});

it('stops working when the account becomes a student account', function (): void {
    $value = rpcCookie(rpcLogin('operator'));

    $this->admin->syncRoles([RoleName::Siswa->value]);

    expect(Remembered::validFor($this->admin->fresh(), (string) $value))->toBeFalse();
});

it('leaves the cookie alone for a guest (Keluar keeps it for the next login of the same account)', function (): void {
    $value = rpcCookie(rpcLogin('operator'));
    $this->post('/logout');

    $this->withCookie(Remembered::COOKIE, (string) $value)->get('/login')->assertOk()->assertCookieMissing(Remembered::COOKIE);
});
