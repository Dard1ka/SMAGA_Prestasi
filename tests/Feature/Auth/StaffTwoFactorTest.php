<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Auth\TwoFactorSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    config(['fortify.sth.require_staff_2fa' => true]);

    Route::middleware(['web', 'area.staff'])->get('/_test/staff-area', fn () => 'staff ok');
    Route::middleware(['web', 'area.siswa'])->get('/_test/siswa-area', fn () => 'siswa ok');
});

it('sends staff without confirmed 2FA to the setup page', function (RoleName $role): void {
    $this->actingAs(H::staff($role));

    $this->get('/_test/staff-area')->assertRedirect(route('two-factor.setup'));
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Admin]);

it('lets staff with confirmed 2FA in', function (): void {
    $staff = H::staff(RoleName::Admin);
    $staff->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($staff)->get('/_test/staff-area')->assertOk()->assertSee('staff ok');
});

it('can be switched off locally with STH_REQUIRE_STAFF_2FA=false', function (): void {
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->actingAs(H::staff(RoleName::Guru))->get('/_test/staff-area')->assertOk();
});

it('never asks students for 2FA, but keeps them out of staff checks via role middleware', function (): void {
    $this->actingAs(H::activeStudent()->user);

    $this->get('/_test/siswa-area')->assertOk()->assertSee('siswa ok');
});

it('blocks staff from the student area', function (): void {
    $this->actingAs(H::staff(RoleName::Guru))->get('/_test/siswa-area')->assertForbidden();
});

it('confirms TOTP through the setup component', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $this->actingAs($staff);

    $component = Livewire::test(TwoFactorSetup::class)->assertSee('Pindai kode QR');

    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $component->set('code', $code)->call('confirm')->assertHasNoErrors()->assertSet('showRecoveryCodes', true);

    expect($staff->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->get('/_test/staff-area')->assertOk();
});

it('rejects a wrong TOTP code', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $this->actingAs($staff);

    Livewire::test(TwoFactorSetup::class)->set('code', '000000')->call('confirm')->assertHasErrors('code');

    expect($staff->fresh()->two_factor_confirmed_at)->toBeNull();
});

// M2-A (QA 2026-10-05, Q1-03): the session id renewed after the TOTP confirmation leaves no old row behind.
it('renews the session when TOTP is confirmed and deletes the old row; a wrong code renews nothing', function (): void {
    config(['session.driver' => 'database']);

    $staff = H::staff(RoleName::Kesiswaan);

    $this->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])->assertRedirect(route('home'));

    $old = session()->getId();
    expect(H::sessionCount($staff))->toBe(1);

    $component = Livewire::test(TwoFactorSetup::class);
    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $component->set('code', $code === '000000' ? '111111' : '000000')->call('confirm')->assertHasErrors('code');

    expect(session()->getId())->toBe($old)
        ->and(H::sessionCount($staff))->toBe(1);

    $component->set('code', $code)->call('confirm')->assertHasNoErrors()->assertSet('showRecoveryCodes', true);

    $new = session()->getId();
    expect($new)->not->toBe($old)
        ->and(DB::table('sessions')->where('id', $old)->exists())->toBeFalse();

    session()->save(); // what StartSession does when the request ends (Livewire tests skip the middleware)

    expect(H::sessionCount($staff))->toBe(1)
        ->and(H::userBehindSession($new))->toBe($staff->getKey())
        ->and(H::userBehindSession($old))->toBeNull();
});

it('asks for the password before the setup page', function (): void {
    $this->actingAs(H::staff(RoleName::Guru))->get(route('two-factor.setup'))->assertRedirect(route('password.confirm'));
});
