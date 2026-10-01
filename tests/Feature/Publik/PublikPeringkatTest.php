<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Peringkat;
use App\Models\WeightVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $f = $this->f = new PublikFixtures;
    // Kelas XII in 2026/2027 → angkatan 2024; XI → 2025; X → 2026.
    $raka = $f->student('Raka Pradipta', 'XII-2');
    $salsa = $f->student('Salsabila Kirana', 'XI-5');
    $aurelia = $f->student('Aurelia Maharani', 'X-3');
    $fajar = $f->student('Fajar Ramadhan', 'XII-1');
    $ardi = $f->student('Ardiansyah Nur Hakim', 'XII-3', archived: true, attributes: ['grade12_start_date' => '2024-07-01']);

    $fiksi = $f->lomba('FIKSI 2026', Bidang::Riset, Level::Nasional, '2026-09-01');
    $opsi = $f->lomba('OPSI 2025', Bidang::Riset, Level::Nasional, '2025-09-01');
    $fls3n = $f->lomba('FLS3N Nasional 2025', Bidang::SeniBudaya, Level::Nasional, '2025-11-01');
    $bio = $f->lomba('Kompetisi Biologi Nasional 2026', Bidang::Sains, Level::Nasional, '2026-03-01', WeightCategory::B);
    $o2sn = $f->lomba('O2SN Provinsi Jateng 2026', Bidang::Olahraga, Level::Provinsi, '2026-05-01');
    $osn = $f->lomba('OSN Nasional 2024 Kimia', Bidang::Sains, Level::Internasional, '2024-10-05');

    $f->prestasi($raka, $fiksi, ResultType::Juara1);         // 80
    $f->prestasi($raka, $opsi, ResultType::Juara1);          // 80 → 160
    $f->prestasi($salsa, $fls3n, ResultType::Juara1);        // 80
    $f->prestasi($salsa, $opsi, ResultType::Juara3);         // 56 → 136
    $f->prestasi($aurelia, $bio, ResultType::Juara1);        // 64
    $f->prestasi($fajar, $o2sn, ResultType::Juara1);         // 60
    $f->prestasi($ardi, $osn, ResultType::Juara1);           // 100 (alumni)
    $f->prestasi($ardi, $fiksi, ResultType::Finalis);        // 40 → 140
});

it('ranks students by cumulative points and hides alumni by default', function (): void {
    $this->get(route('public.peringkat'))
        ->assertOk()
        ->assertSeeInOrder(['Raka Pradipta', 'Salsabila Kirana', 'Aurelia Maharani', 'Fajar Ramadhan'])
        ->assertSee('160')
        ->assertDontSee('Ardiansyah Nur Hakim');

    $rows = app(PublicAchievements::class)->ranking('siswa');
    expect(array_column($rows, 'name'))->toBe(['Raka Pradipta', 'Salsabila Kirana', 'Aurelia Maharani', 'Fajar Ramadhan'])
        ->and(array_column($rows, 'rank'))->toBe([1, 2, 3, 4])
        ->and($rows[0])->not->toHaveKeys(['sid', 'student_id', 'id', 'nis', 'nisn', 'email']);
});

it('shows alumni with the chip when the alumni toggle is on', function (): void {
    $this->get(route('public.peringkat', ['alumni' => 1]))
        ->assertOk()
        ->assertSeeInOrder(['Raka Pradipta', 'Ardiansyah Nur Hakim', 'Salsabila Kirana'])
        ->assertSee('Alumni');

    Livewire::test(Peringkat::class)
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->set('alumni', true)
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertSee('Alumni');
});

it('ranks classes in kelas mode (active students only)', function (): void {
    $this->get(route('public.peringkat', ['mode' => 'kelas']))
        ->assertOk()
        ->assertSeeInOrder(['XII-2', 'XI-5', 'X-3', 'XII-1'])
        ->assertSee('Siswa berprestasi')
        ->assertDontSee('XII-3');

    $rows = app(PublicAchievements::class)->ranking('kelas');
    expect($rows[0])->toMatchArray(['kelas' => 'XII-2', 'angkatan' => 2024, 'students' => 1, 'count' => 2, 'points' => '160.00']);

    Livewire::test(Peringkat::class)
        ->call('pilihMode', 'kelas')
        ->assertSet('mode', 'kelas')
        ->assertSet('alumni', false)
        ->assertSee('Kelas XII-2');
});

it('filters the ranking by bidang and angkatan', function (): void {
    $this->get(route('public.peringkat', ['bidang' => 'olahraga']))
        ->assertOk()
        ->assertSee('Fajar Ramadhan')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('Salsabila Kirana');

    $this->get(route('public.peringkat', ['angkatan' => '2025']))
        ->assertOk()
        ->assertSee('Salsabila Kirana')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('Fajar Ramadhan');

    expect(app(PublicAchievements::class)->angkatanOptions())->toMatchArray([
        '2026' => 'Angkatan 2026 (kelas X)',
        '2025' => 'Angkatan 2025 (kelas XI)',
        '2024' => 'Angkatan 2024 (kelas XII)',
    ]);

    // Bidang filter counts only that bidang's points (Raka riset 160, Salsabila riset 56).
    $riset = app(PublicAchievements::class)->ranking('siswa', Bidang::Riset);
    expect(array_column($riset, 'points'))->toBe(['160.00', '56.00']);
});

it('shows the empty state when a filter has no data', function (): void {
    $this->get(route('public.peringkat', ['bidang' => 'olahraga', 'angkatan' => '2025']))
        ->assertOk()
        ->assertSee('Belum ada data untuk filter ini.');
});

it('falls back to defaults for invalid query values', function (array $query): void {
    $this->get(route('public.peringkat', $query))->assertOk()->assertSee('Peringkat prestasi');
})->with([
    'mode' => [['mode' => 'hack']],
    'bidang' => [['bidang' => 'ilmu-hitam']],
    'angkatan' => [['angkatan' => '1900']],
    'angkatan text' => [['angkatan' => '<script>']],
    'limit' => [['limit' => '-5']],
    'limit huge' => [['limit' => '999999']],
    'limit text' => [['limit' => 'abc']],
    'alumni' => [['alumni' => 'maybe']],
    'arrays' => [['mode' => ['x'], 'bidang' => ['y'], 'limit' => ['z']]],
]);

it('shows 20 rows first and 20 more per click (max 200)', function (): void {
    foreach (range(1, 22) as $i) {
        $student = $this->f->student('Siswa Contoh '.$i, 'X-5');
        $this->f->prestasi($student, $this->f->lomba('Lomba Contoh '.$i, Bidang::Sains, Level::Sekolah, '2026-08-01'), ResultType::Peserta);
    }
    PublicAchievements::flush();

    Livewire::test(Peringkat::class)
        ->assertSee('Tampilkan lebih banyak')
        ->assertSee('Menampilkan 20 dari 26 siswa')
        ->call('tampilkanLebih')
        ->assertSet('limit', 40)
        ->assertSee('Menampilkan 26 dari 26 siswa')
        ->assertDontSee('Tampilkan lebih banyak');

    Livewire::withQueryParams(['limit' => 5000])->test(Peringkat::class)->assertSet('limit', 200);
});

it('explains the points with the active weight version', function (): void {
    $this->get(route('public.peringkat'))
        ->assertOk()
        ->assertSee('Cara poin dihitung')
        ->assertSee('Juara I / Medali emas')
        ->assertSee('Semifinalis')
        ->assertSee('Penghargaan khusus')
        ->assertSee('0,30')
        ->assertSee('Kategori E')
        ->assertSee('80 × 1,00 × 1,00 = 80 poin');

    expect(WeightVersion::active())->not->toBeNull();
});
