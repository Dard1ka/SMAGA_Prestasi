<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
| FS1 B3 review (2026-10-13): since D-150 a registration is approved by the Kepala Sekolah or by Tata Usaha. Status
| sistem warns that registrations cannot be approved only when no account that may approve can log in
| (CreateRegistration::principals()); a school without a Kepala Sekolah account but with an active TU gets no such
| warning. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->withoutVite();
    $this->admin = A::admin();
});

it('warns that registrations cannot be approved while no Kepala Sekolah or Tata Usaha can log in', function (): void {
    // A frozen-out TU account does not count.
    A::staff(RoleName::TataUsaha)->forceFill(['status' => UserStatus::Disabled])->save();

    $this->actingAs($this->admin)->get(route('admin.ringkasan'))
        ->assertOk()
        ->assertSee('Belum ada akun Kepala Sekolah atau Tata Usaha yang aktif. Pendaftaran lomba tidak bisa disetujui.')
        ->assertDontSee('Belum ada akun Kepala Sekolah.');
});

it('does not warn about approvals while an active Tata Usaha can approve', function (): void {
    A::staff(RoleName::TataUsaha);

    $this->actingAs($this->admin)->get(route('admin.ringkasan'))
        ->assertOk()
        ->assertDontSee('Pendaftaran lomba tidak bisa disetujui')
        ->assertDontSee('Belum ada akun Kepala Sekolah');
});

it('does not warn about approvals while an active Kepala Sekolah can approve', function (): void {
    A::staff(RoleName::KepalaSekolah);

    $this->actingAs($this->admin)->get(route('admin.ringkasan'))
        ->assertOk()
        ->assertDontSee('Pendaftaran lomba tidak bisa disetujui');
});
