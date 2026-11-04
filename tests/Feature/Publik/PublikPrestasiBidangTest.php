<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\PrestasiBidang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $f = $this->f = new PublikFixtures;
    $this->raka = $f->student('Raka Pradipta', 'XII-2');
    $this->keisha = $f->student('Keisha Larasati', 'XI-6');
    $this->citra = $f->student('Citra Ayu Lestari', 'XI-2');
    $this->fajar = $f->student('Fajar Ramadhan', 'XII-1');

    // Riset has the most achievements → default bidang.
    $f->prestasi($this->raka, $f->lomba('FIKSI 2026', Bidang::Riset, Level::Nasional, '2026-09-01'), ResultType::Juara1);
    $f->prestasi($this->raka, $f->lomba('KRENOVA Kota Semarang 2025', Bidang::Riset, Level::Kota, '2025-08-15'), ResultType::Juara1);
    $f->prestasi($this->keisha, $f->lomba('OPSI 2025', Bidang::Riset, Level::Nasional, '2025-09-10'), ResultType::Juara2);
    $f->prestasi($this->citra, $f->lomba('FLS3N Provinsi Jateng 2025', Bidang::SeniBudaya, Level::Provinsi, '2025-10-01'), ResultType::Juara1);
    $f->prestasi($this->fajar, $f->lomba('O2SN Kota Semarang 2024', Bidang::Olahraga, Level::Kota, '2024-09-01'), ResultType::Semifinalis);
});

it('opens on the bidang with the most achievements and lists its students by points', function (): void {
    Livewire::test(PrestasiBidang::class)
        ->assertSet('bidang', 'riset')
        ->assertSeeInOrder(['Raka Pradipta', 'Keisha Larasati'])
        ->assertDontSee('Citra Ayu Lestari')
        ->assertSee('Juara I')
        ->assertSee('Nasional');

    $students = app(PublicAchievements::class)->studentsInBidang(Bidang::Riset);
    expect($students[0])->toMatchArray(['name' => 'Raka Pradipta', 'count' => 2, 'points' => '120.00', 'best' => 'Juara I', 'best_tingkat' => 'Nasional'])
        ->and($students[0])->not->toHaveKeys(['sid', 'student_id', 'report', 'nis', 'nisn']);
});

it('narrows the lists with the bidang, tahun and tingkat filters', function (): void {
    $this->get(route('public.prestasi', ['bidang' => 'seni_budaya']))
        ->assertOk()
        ->assertSee('Citra Ayu Lestari')
        ->assertDontSee('Raka Pradipta');

    $this->get(route('public.prestasi', ['bidang' => 'riset', 'tahun' => '2026/2027']))
        ->assertOk()
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Keisha Larasati');

    $this->get(route('public.prestasi', ['bidang' => 'riset', 'tingkat' => 'kota']))
        ->assertOk()
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Keisha Larasati');

    $summary = app(PublicAchievements::class)->bidangSummary('2025/2026');
    expect(collect($summary['bidang'])->pluck('count', 'value')->all())
        ->toBe(['sains' => 0, 'riset' => 2, 'seni_budaya' => 1, 'olahraga' => 0])
        ->and($summary['total'])->toBe(3);

    $olahraga = app(PublicAchievements::class)->studentsInBidang(Bidang::Olahraga, null, 'kota');
    expect(array_column($olahraga, 'name'))->toBe(['Fajar Ramadhan']);
});

it('switches bidang and student through Livewire actions', function (): void {
    $slug = $this->f->slug($this->keisha);

    Livewire::test(PrestasiBidang::class)
        ->call('pilihSiswa', $slug)
        ->assertSet('siswa', $slug)
        ->assertSee('Buka halaman siswa')
        ->assertSee('OPSI 2025')
        ->call('pilihBidang', 'olahraga')
        ->assertSet('bidang', 'olahraga')
        ->assertSet('siswa', '')
        ->assertSee('Fajar Ramadhan')
        ->call('pilihBidang', 'bukan-bidang')
        ->assertSet('bidang', 'riset')
        ->call('pilihSiswa', 'ffffffffffffffffffff')
        ->assertSet('siswa', '')
        ->set('tahun', '1999/2000')
        ->assertSet('tahun', '')
        ->set('tingkat', 'galaksi')
        ->assertSet('tingkat', '')
        ->set('tingkat', 'nasional')
        ->assertSet('tingkat', 'nasional');
});

it('falls back to defaults for invalid query values', function (array $query): void {
    $this->get(route('public.prestasi', $query))->assertOk()->assertSee('Prestasi per bidang');
})->with([
    'bidang' => [['bidang' => 'nope']],
    'tahun' => [['tahun' => '1999/2000']],
    'tahun text' => [['tahun' => '"><script>']],
    'tingkat' => [['tingkat' => 'galaksi']],
    'siswa' => [['siswa' => 'zzz']],
    'arrays' => [['bidang' => ['x'], 'tahun' => ['y'], 'siswa' => ['z']]],
]);

it('shows the per-year chart for the selected bidang', function (): void {
    $summary = app(PublicAchievements::class)->bidangSummary();

    expect(array_column($summary['years']['riset'], 'tahun'))->toBe(['2024/2025', '2025/2026', '2026/2027'])
        ->and(array_column($summary['years']['riset'], 'count'))->toBe([0, 2, 1]);

    $this->get(route('public.prestasi', ['bidang' => 'riset']))
        ->assertOk()
        ->assertSee('Perkembangan prestasi Riset per tahun ajaran: 2024/2025 0, 2025/2026 2, 2026/2027 1 (masih berjalan)', false);
});
