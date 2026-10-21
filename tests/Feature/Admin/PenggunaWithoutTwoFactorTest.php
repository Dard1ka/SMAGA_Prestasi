<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Livewire\Admin\Pengaturan\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Support\WithoutStaffTwoFactor;

/*
| Admin › Pengguna & peran with STH_STAFF_2FA=false: no 2FA words on the screen (the "2FA" chip of the staff list, the
| descriptions of "Tambah pengguna" and "Reset akun staf", the toast after a reset). The whole file boots with the switch
| off (WithoutStaffTwoFactor); the same texts with the switch on are checked in PenggunaTwoFactorTextTest. Fictional data.
*/

uses(RefreshDatabase::class, WithoutStaffTwoFactor::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

it('shows no 2FA chip and no 2FA step in the dialogs when 2FA is switched off', function (): void {
    expect(EnsureStaffTwoFactor::featureEnabled())->toBeFalse();

    // A::staff() confirms a TOTP secret, as an account from the time 2FA was on would have.
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->assertSee('Pak Hendra Kusuma')
        ->assertDontSee('2FA')
        ->assertSee('lalu wajib mengganti kata sandi sementara.')
        ->call('openStaff', $guru->id, 'staf-reset')
        ->assertSee('Akun Pak Hendra Kusuma: kata sandi sementara, email diverifikasi ulang.')
        ->assertDontSee('2FA');
});

it('says nothing about 2FA after a staff reset when 2FA is switched off', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->call('openStaff', $guru->id, 'staf-reset')
        ->set('tempPassword', 'Sementara-2026!y')
        ->set('tempPassword_confirmation', 'Sementara-2026!y')
        ->set('reason', 'Lupa kata sandi.')
        ->call('resetStaff')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Akun direset. Pengguna mengganti kata sandi saat login.');
});
