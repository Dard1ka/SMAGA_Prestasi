<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Livewire\Admin\Pengaturan\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
| Admin › Pengguna & peran with 2FA switched on (phpunit.xml's STH_STAFF_2FA=true): the 2FA texts stay as they were.
| The switched-off texts: PenggunaWithoutTwoFactorTest. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

it('keeps the 2FA chip and the 2FA step in the dialogs while 2FA is on', function (): void {
    expect(EnsureStaffTwoFactor::featureEnabled())->toBeTrue();

    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->assertSeeHtml('>2FA<')
        ->assertSee('lalu wajib mengganti kata sandi sementara dan memasang 2FA.')
        ->call('openStaff', $guru->id, 'staf-reset')
        ->assertSee('Akun Pak Hendra Kusuma: kata sandi sementara, email diverifikasi ulang, 2FA dipasang ulang.');
});

it('names the 2FA setup in the toast after a staff reset while 2FA is on', function (): void {
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
        ->assertDispatched('sth-toast', message: 'Akun direset. Pengguna mengganti kata sandi dan memasang 2FA lagi saat login.');
});
