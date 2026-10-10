<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| QA5 retest (2026-10-07, backlog #60, D-90 "one search box per screen"): a page with a search box of its own does not
| also show the top bar search, the top bar search finds what its label says, and the TU queue really filters.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
});

it('hides the top bar search on a page that has its own search box', function (RoleName $role, string $route): void {
    $user = F::staff($role);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route))
        ->assertOk()
        ->assertDontSee('id="cari-atas"', false);
})->with([
    'TU arsip surat' => [RoleName::TataUsaha, 'tu.surat.arsip'],
    'Kesiswaan kelola lomba' => [RoleName::Kesiswaan, 'kesiswaan.lomba.index'],
    'Kesiswaan talenta' => [RoleName::Kesiswaan, 'kesiswaan.talenta.index'],
    'Kesiswaan database prestasi' => [RoleName::Kesiswaan, 'kesiswaan.prestasi.index'],
    'Kesiswaan status aktivasi' => [RoleName::Kesiswaan, 'kesiswaan.aktivasi.index'],
    'Guru lomba' => [RoleName::Guru, 'guru.lomba.index'],
    'Kepala Sekolah surat' => [RoleName::KepalaSekolah, 'kepsek.surat.index'],
    'Admin pengguna' => [RoleName::Admin, 'admin.pengguna.index'],
    'Admin status aktivasi' => [RoleName::Admin, 'admin.aktivasi.index'],
    'Admin arsip otomatis' => [RoleName::Admin, 'admin.arsip.index'],
    'Admin log audit' => [RoleName::Admin, 'admin.audit.index'],
    'Guru siswa binaan' => [RoleName::Guru, 'guru.beranda'],
    'Admin pengaturan' => [RoleName::Admin, 'admin.pengaturan'],
    // D-124: "Tambah prestasi" on Apresiasi has a search box of its own (shared screen, two routes).
    'Humas apresiasi' => [RoleName::Humas, 'humas.apresiasi.index'],
    'Kesiswaan apresiasi' => [RoleName::Kesiswaan, 'kesiswaan.apresiasi.index'],
]);

it('keeps the top bar search on a page without a search box of its own', function (): void {
    $this->actingAs(F::staff(RoleName::Kesiswaan))
        ->get(route('kesiswaan.beranda'))
        ->assertOk()
        ->assertSee('id="cari-atas"', false)
        ->assertSee('action="'.route('kesiswaan.lomba.index').'"', false);
});

it('labels each top bar search after what its target page finds', function (RoleName $role, string $label): void {
    expect(Navigation::for(F::staff($role))['search']['placeholder'])->toBe($label);
})->with([
    'Kesiswaan: Kelola lomba finds lomba only' => [RoleName::Kesiswaan, 'Cari lomba'],
    'TU: the queue holds approved registrations' => [RoleName::TataUsaha, 'Cari lomba atau siswa'],
]);

it('filters the TU letter queue by lomba or student name from the top bar search', function (): void {
    $tu = F::staff(RoleName::TataUsaha);
    $osn = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], null, ['name' => 'OSN Tingkat Kota Semarang 2026']);
    $nsif = F::registration(RegistrationStatus::Disetujui, ['Bima Satria Wicaksono'], null, ['name' => 'National Science and Invention Fair (NSIF) 2026']);

    Livewire::actingAs($tu)->withQueryParams(['q' => 'OSN'])->test(SuratTugas::class)
        ->assertViewHas('queue', fn ($queue): bool => $queue->modelKeys() === [$osn->getKey()])
        ->assertSet('registrationId', $osn->getKey())
        ->set('q', 'Bima')
        ->assertViewHas('queue', fn ($queue): bool => $queue->modelKeys() === [$nsif->getKey()])
        ->set('q', 'tidak ada')
        ->assertViewHas('queue', fn ($queue): bool => $queue->isEmpty())
        ->assertSee('Tidak ada pendaftaran yang cocok')
        ->set('q', '')
        ->assertViewHas('queue', fn ($queue): bool => $queue->count() === 2);
});

it('counts the whole TU queue in the sidebar badge, also while searching', function (): void {
    $tu = F::staff(RoleName::TataUsaha);
    F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], null, ['name' => 'OSN Tingkat Kota Semarang 2026']);
    F::registration(RegistrationStatus::Disetujui, ['Bima Satria Wicaksono']);

    $this->actingAs($tu)->get(route('tu.beranda', ['q' => 'OSN']))
        ->assertOk()
        ->assertSee('data-sth-badge="tu.surat"', false);

    preg_match('/data-sth-badge="tu\.surat"[^>]*>\s*(\d+)\s*</', (string) $this->actingAs($tu)->get(route('tu.beranda', ['q' => 'OSN']))->getContent(), $badge);
    expect($badge[1] ?? null)->toBe('2');
});

it('selects the first registration again when a search that found nothing is cleared', function (): void {
    $tu = F::staff(RoleName::TataUsaha);
    $osn = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], null, ['name' => 'OSN Tingkat Kota Semarang 2026']);

    Livewire::actingAs($tu)->withQueryParams(['q' => 'tidak ada'])->test(SuratTugas::class)
        ->assertSet('registrationId', null)
        ->assertSee('Tidak ada pendaftaran yang cocok')
        // The queue is not empty, only the search found nothing.
        ->assertDontSee('Belum ada surat untuk dibuat')
        ->set('q', '')
        ->assertSet('registrationId', $osn->getKey())
        ->assertDontSee('Belum ada surat untuk dibuat');
});
