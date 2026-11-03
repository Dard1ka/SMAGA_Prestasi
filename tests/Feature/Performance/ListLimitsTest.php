<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Humas\Publikasi;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Kepsek\PrestasiBidang as KepsekPrestasiBidang;
use App\Livewire\Publik\PrestasiBidang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;
use Tests\Feature\KepalaSekolah\W2cFixtures;
use Tests\Feature\Kepsek\KepsekFixtures;
use Tests\Feature\Publik\PublikFixtures;

/*
| Page weight: long lists come in steps of 20 ("Tampilkan lebih banyak") instead of all rows at once. With a
| school-sized data set the unbounded lists made these pages 400–600 kB of HTML.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
});

it('shows 20 students per step on the public prestasi page and clamps the limit', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $f = new PublikFixtures;
    $lomba = $f->lomba('FIKSI 2026', Bidang::Riset, Level::Nasional, '2026-09-01');

    foreach (range(1, 23) as $i) {
        $f->prestasi($f->student(sprintf('Siswa Uji %02d', $i)), $lomba, ResultType::Juara1);
    }

    Livewire::test(PrestasiBidang::class)
        ->assertSet('bidang', 'riset')
        ->assertViewHas('students', fn (array $students): bool => count($students) === 20)
        ->assertViewHas('studentTotal', 23)
        ->assertViewHas('hasMore', true)
        ->assertSee('Tampilkan lebih banyak')
        ->call('tampilkanLebih')
        ->assertViewHas('students', fn (array $students): bool => count($students) === 23)
        ->assertViewHas('hasMore', false)
        ->set('limit', 99999)
        ->assertSet('limit', PrestasiBidang::MAX)
        ->set('limit', -5)
        ->assertSet('limit', PrestasiBidang::STEP);
});

it('shows 20 waiting registrations per step and bulk-selects only the visible ones', function (): void {
    AuthTestHelpers::seedRoles();
    $kepsek = W2cFixtures::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);

    foreach (range(1, 23) as $i) {
        W2cFixtures::registration(names: [sprintf('Siswa Uji %02d', $i)], competition: ['name' => sprintf('Lomba Uji %02d', $i)]);
    }

    Livewire::actingAs($kepsek)
        ->test(Persetujuan::class)
        ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 20)
        ->assertViewHas('more', 3)
        ->assertSee('Tampilkan lebih banyak (3 lagi)')
        ->call('toggleAll')
        ->assertCount('selected', 20)
        ->call('tampilkanLebih')
        ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 23)
        ->assertViewHas('more', 0)
        ->set('limit', 99999)
        ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 23)
        ->assertSet('limit', Persetujuan::MAX);
});

it('shows 20 students per step on the Kepala Sekolah prestasi page', function (): void {
    KepsekFixtures::seed();
    $this->travelTo('2026-10-01 10:00:00');
    $kepsek = KepsekFixtures::kepsek();

    foreach (range(1, 22) as $i) {
        KepsekFixtures::achievement(
            KepsekFixtures::student(sprintf('Siswa Uji %02d', $i)),
            Bidang::Riset, Level::Nasional, ResultType::Juara2, '68.00', '2026-09-24', sprintf('Lomba Uji %02d', $i),
        );
    }

    Livewire::actingAs($kepsek)
        ->test(KepsekPrestasiBidang::class)
        ->set('bidang', 'riset')
        ->assertViewHas('students', fn (array $students): bool => count($students) === 20)
        ->assertViewHas('studentTotal', 22)
        ->assertViewHas('hasMore', true)
        ->call('tampilkanLebih')
        ->assertViewHas('students', fn (array $students): bool => count($students) === 22)
        ->assertViewHas('hasMore', false);
});

it('shows 20 cards per lane on the Humas board and the real total in the chip', function (): void {
    AuthTestHelpers::seedRoles();
    $humas = HumasFixtures::humas();

    foreach (range(1, 22) as $i) {
        HumasFixtures::publication(studentName: sprintf('Siswa Uji %02d', $i), competitionName: sprintf('Lomba Uji %02d', $i));
    }

    Livewire::actingAs($humas)
        ->test(Publikasi::class)
        ->assertViewHas('lanes', fn (array $lanes): bool => $lanes[0]['count'] === 22 && count($lanes[0]['cards']) === 20 && $lanes[0]['more'] === 2)
        ->assertSee('Tampilkan lebih banyak (2 lagi)')
        ->call('tampilkanLebih')
        ->assertViewHas('lanes', fn (array $lanes): bool => count($lanes[0]['cards']) === 22 && $lanes[0]['more'] === 0);
});
