<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;
use Tests\Support\WithoutStaffTwoFactor;

/*
| STH_STAFF_2FA=false (config fortify.sth.staff_2fa): two-factor authentication does not exist in the app at all, for
| every account. The switch decides which routes are registered, so this whole file runs on an application booted with
| it off: WithoutStaffTwoFactor overrides createApplication() and sets the env only around the boot (Tests\Support\
| ProcessEnv). A config() call inside a test would come too late for Fortify's routes and routes/web.php.
| The default (on) is covered unchanged by StaffTwoFactorTest, TwoFactorQa10Test, KeamananTest and the others, which
| boot with phpunit.xml's forced STH_STAFF_2FA=true. Fictional data only.
*/

uses(RefreshDatabase::class, WithoutStaffTwoFactor::class);

const STAFF_2FA_OFF_SECRET = 'JBSWY3DPEHPK3PXP';

beforeEach(function (): void {
    H::seedRoles();
    $this->withoutVite();
});

/** Kesiswaan account (username `staf.kesiswaan`) with a confirmed TOTP secret from the time 2FA was on. */
function staffWithConfirmedTotp(): User
{
    $staff = H::staff(RoleName::Kesiswaan, ['username' => 'staf.kesiswaan']);
    $staff->forceFill([
        'two_factor_secret' => encrypt(STAFF_2FA_OFF_SECRET),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $staff->fresh();
}

it('boots without the Fortify feature and without any two-factor route', function (): void {
    expect(config('fortify.sth.staff_2fa'))->toBeFalse()
        ->and(EnsureStaffTwoFactor::featureEnabled())->toBeFalse()
        ->and(config('fortify.features'))->toBe([]);

    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => (string) $route->getName());
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($names->filter(fn (string $name): bool => str_starts_with($name, 'two-factor')))->toBeEmpty()
        ->and($uris->filter(fn (string $uri): bool => str_contains($uri, 'two-factor') || $uri === 'akun/2fa'))->toBeEmpty();
});

it('signs in a staff account with a confirmed secret by username and password only, straight to its beranda', function (): void {
    $staff = staffWithConfirmedTotp();

    $this->post('/login', ['identifier' => 'staf.kesiswaan', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($staff);

    $this->get(route('home'))->assertRedirect(route('kesiswaan.beranda'));
    $this->get(route('kesiswaan.beranda'))->assertOk();
});

it('answers 404 for the challenge, the setup page and every Fortify 2FA address', function (): void {
    $this->get('/two-factor-challenge')->assertNotFound();
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertNotFound();

    $this->actingAs(staffWithConfirmedTotp());

    $this->get('/akun/2fa')->assertNotFound();
    $this->post('/user/two-factor-authentication')->assertNotFound();
    $this->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertNotFound();
    $this->delete('/user/two-factor-authentication')->assertNotFound();
    $this->get('/user/two-factor-qr-code')->assertNotFound();
    $this->get('/user/two-factor-secret-key')->assertNotFound();
    $this->get('/user/two-factor-recovery-codes')->assertNotFound();
    $this->post('/user/two-factor-recovery-codes')->assertNotFound();
});

it('lets staff without 2FA into an area even with STH_REQUIRE_STAFF_2FA=true', function (RoleName $role): void {
    config(['fortify.sth.require_staff_2fa' => true]);
    Route::middleware(['web', 'area.staff'])->get('/_test/staff-area', fn () => 'staff ok');

    $staff = H::staff($role);
    expect($staff->two_factor_confirmed_at)->toBeNull()
        ->and(EnsureStaffTwoFactor::requiresSetup($staff))->toBeFalse();

    $this->actingAs($staff)->get('/_test/staff-area')->assertOk()->assertSee('staff ok');
    $this->getJson('/_test/staff-area')->assertOk();
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::Admin]);

it('shows Keamanan akun without a 2FA section or any 2FA word', function (): void {
    config(['fortify.sth.require_staff_2fa' => true]);

    foreach ([H::staff(RoleName::Guru), staffWithConfirmedTotp()] as $staff) {
        $this->actingAs($staff)->get(route('akun.keamanan'))
            ->assertOk()
            ->assertSee('Kata sandi')
            ->assertDontSee('Verifikasi dua langkah')
            ->assertDontSee('2FA')
            ->assertDontSee('autentikator')
            ->assertDontSee('two-factor', false)
            ->assertDontSee('akun/2fa', false);
    }
});

it('shows nothing about 2FA on Admin › Status sistem', function (): void {
    H::staff(RoleName::Guru); // staff without 2FA: with the switch on this is the "belum memasang" line

    $this->actingAs(A::admin())->get(route('admin.ringkasan'))
        ->assertOk()
        ->assertDontSee('verifikasi dua langkah')
        ->assertDontSee('2FA');
});

it('words the staff reset notice without 2FA', function (): void {
    $lines = (new StaffAccountChanged(StaffAccountChanged::RESET, 'Operator Sekolah', 'Bambang Hartono, S.Pd.'))->lines();

    expect($lines[0])->toBe('Operator Sekolah mereset kata sandi akun Bambang Hartono, S.Pd.')
        ->and(implode(' ', $lines))->not->toContain('dua langkah');
});
