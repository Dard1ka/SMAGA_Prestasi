<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Admin\Pengaturan\Surat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
| SV1 third browser check (2026-11-01): a staff name that ends with a degree ("…, M.Pd.") got a second full stop in
| "Ubah peran" ("Akun Dra. Contoh, M.Pd.. Pengguna perlu login ulang.") and in Surat & tanda tangan ("Akun Kepala
| Sekolah: Drs. Contoh, M.Pd.."). Both use MentorChoice::closing() now. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

it('ends the Ubah peran sentence after a degree with one full stop', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Dra. Contoh Pembina, M.Pd.']);

    $html = Livewire::actingAs($this->admin)->test(Pengguna::class)
        ->call('openStaff', (int) $guru->getKey(), 'staf-peran')
        ->html();

    expect($html)->toContain('Akun Dra. Contoh Pembina, M.Pd. Pengguna perlu login ulang.')
        ->not->toContain('M.Pd..');
});

it('names the Kepala Sekolah on Surat & tanda tangan with one full stop, also without a degree', function (): void {
    A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Contoh Kepala, M.Pd.']);

    expect(Livewire::actingAs($this->admin)->test(Surat::class)->html())
        ->toContain('Akun Kepala Sekolah: Drs. Contoh Kepala, M.Pd.')
        ->not->toContain('M.Pd..');
});

it('still closes a name without a degree with a full stop', function (): void {
    A::staff(RoleName::KepalaSekolah, ['name' => 'Contoh Kepala Sekolah']);

    expect(Livewire::actingAs($this->admin)->test(Surat::class)->html())->toContain('Akun Kepala Sekolah: Contoh Kepala Sekolah.');
});
