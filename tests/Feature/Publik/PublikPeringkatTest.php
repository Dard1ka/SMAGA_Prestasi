<?php

declare(strict_types=1);

use App\Domain\Leaderboard\RankingScope;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Peringkat;
use App\Models\Competition;
use App\Models\Student;
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

// --- M1-C (lead, 2026-10-04): three-way filter Siswa aktif | Alumni | Semua instead of "Tampilkan alumni" ---

it('shows only alumni who have achievements with the Alumni filter, ranked among themselves', function (): void {
    // An alumnus without any verified achievement and one without publish consent.
    $this->f->student('Bagas Wirayudha', 'XII-5', archived: true, attributes: ['grade12_start_date' => '2024-07-01']);
    $tiara = $this->f->student('Tiara Anjani Lestari', 'XII-8', consent: false, archived: true, attributes: ['grade12_start_date' => '2024-07-01']);
    $this->f->prestasi($tiara, $this->f->lomba('LKS Provinsi 2024', Bidang::Sains, Level::Provinsi, '2024-11-01'), ResultType::Juara1); // 60
    PublicAchievements::flush();

    $this->get(route('public.peringkat', ['status' => 'alumni']))
        ->assertOk()
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertSee('Menampilkan 2 dari 2 alumni')
        ->assertSee('Hanya alumni yang punya prestasi.')
        ->assertDontSee('Raka Pradipta')
        ->assertDontSee('Salsabila Kirana')
        ->assertDontSee('Bagas Wirayudha')
        ->assertDontSee('Tiara Anjani Lestari');

    $rows = app(PublicAchievements::class)->ranking('siswa', scope: RankingScope::Alumni);

    expect(array_column($rows, 'name'))->toBe(['Ardiansyah Nur Hakim', 'Alumni'])
        ->and(array_column($rows, 'rank'))->toBe([1, 2])
        ->and(array_column($rows, 'points'))->toBe(['140.00', '60.00'])
        ->and(array_column($rows, 'alumni'))->toBe([true, true]);
});

it('mixes active students and alumni with the Semua filter', function (): void {
    $this->get(route('public.peringkat', ['status' => 'semua']))
        ->assertOk()
        ->assertSeeInOrder(['Raka Pradipta', 'Ardiansyah Nur Hakim', 'Salsabila Kirana'])
        ->assertSee('Siswa aktif dan alumni digabung dalam satu peringkat.')
        ->assertSee('Menampilkan 5 dari 5 siswa');

    $rows = app(PublicAchievements::class)->ranking('siswa', scope: RankingScope::All);

    expect(array_column($rows, 'name'))->toBe(['Raka Pradipta', 'Ardiansyah Nur Hakim', 'Salsabila Kirana', 'Aurelia Maharani', 'Fajar Ramadhan'])
        ->and(array_column($rows, 'rank'))->toBe([1, 2, 3, 4, 5]);
});

it('switches between the three groups and keeps the choice in the URL', function (): void {
    Livewire::test(Peringkat::class)
        ->assertSet('status', 'aktif')
        ->assertSee('Siswa aktif')
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->call('pilihStatus', 'alumni')
        ->assertSet('status', 'alumni')
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertDontSee('Raka Pradipta')
        ->call('pilihStatus', 'semua')
        ->assertSet('status', 'semua')
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertSee('Raka Pradipta')
        ->call('pilihStatus', 'aktif')
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->call('pilihStatus', 'hack')
        ->assertSet('status', 'aktif');

    Livewire::withQueryParams(['status' => 'alumni'])->test(Peringkat::class)
        ->assertSet('status', 'alumni')
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertDontSee('Raka Pradipta');

    // The old checkbox parameter means nothing any more: the default list (active students) is shown.
    $this->get(route('public.peringkat', ['alumni' => 1]))->assertOk()->assertDontSee('Ardiansyah Nur Hakim');
});

it('shows "Alumni" where the class stands and never the last class of an alumnus', function (): void {
    $slug = $this->f->slug(Student::query()->whereHas('user', fn ($q) => $q->where('name', 'Ardiansyah Nur Hakim'))->sole());
    $fiksi = Competition::query()->where('name', 'FIKSI 2026')->sole();

    foreach ([
        route('public.home'),
        route('public.prestasi', ['bidang' => 'sains']),
        route('public.prestasi', ['bidang' => 'sains', 'siswa' => $slug]),
        route('public.prestasi.siswa', $slug),
        route('public.lomba.pemenang', $fiksi->id),
        route('public.peringkat', ['status' => 'alumni']),
        route('public.peringkat', ['status' => 'semua']),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Ardiansyah Nur Hakim')
            ->assertSee('Alumni')
            ->assertDontSee('XII-3')
            ->assertDontSee('Kelas terakhir');
    }

    $row = collect(app(PublicAchievements::class)->ranking('siswa', scope: RankingScope::All))->firstWhere('name', 'Ardiansyah Nur Hakim');
    $active = collect(app(PublicAchievements::class)->ranking('siswa'))->firstWhere('name', 'Raka Pradipta');

    expect($row)->toMatchArray(['kelas' => null, 'kelas_label' => 'Alumni', 'alumni' => true])
        ->and($active)->toMatchArray(['kelas' => 'XII-2', 'kelas_label' => 'XII-2', 'alumni' => false]);
});

it('offers only the angkatan of the shown group and clears a choice that no longer exists', function (): void {
    $data = app(PublicAchievements::class);

    // (PHP turns the numeric option keys into integers.)
    expect(array_keys($data->angkatanOptions()))->toBe([2026, 2025, 2024])
        ->and($data->angkatanOptions(RankingScope::Alumni))->toBe([2022 => 'Angkatan 2022'])
        ->and(array_keys($data->angkatanOptions(RankingScope::All)))->toBe([2026, 2025, 2024, 2022])
        ->and(array_keys($data->angkatanOptions(RankingScope::All, 'kelas')))->toBe([2026, 2025, 2024]);

    // An alumni year is not a choice while alumni are hidden: no empty list, the filter goes back to "Semua angkatan".
    $this->get(route('public.peringkat', ['angkatan' => '2022']))
        ->assertOk()
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Belum ada data untuk filter ini.');

    Livewire::withQueryParams(['status' => 'semua', 'angkatan' => '2022'])->test(Peringkat::class)
        ->assertSet('angkatan', '2022')
        ->assertSee('Ardiansyah Nur Hakim')
        ->assertDontSee('Raka Pradipta')
        ->call('pilihStatus', 'aktif')
        ->assertSet('angkatan', '')
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Belum ada data untuk filter ini.');
});

it('explains an empty alumni list instead of showing other students', function (): void {
    $this->get(route('public.peringkat', ['status' => 'alumni', 'bidang' => 'olahraga']))
        ->assertOk()
        ->assertSee('Belum ada data untuk filter ini.')
        ->assertSee('atau pilih Semua')
        ->assertDontSee('Fajar Ramadhan');
});

it('ranks classes in kelas mode (active students only)', function (): void {
    $this->get(route('public.peringkat', ['mode' => 'kelas']))
        ->assertOk()
        ->assertSeeInOrder(['XII-2', 'XI-5', 'X-3', 'XII-1'])
        ->assertSee('Siswa berprestasi')
        ->assertSee('Peringkat kelas hanya menghitung siswa aktif.')
        ->assertDontSee('XII-3');

    $rows = app(PublicAchievements::class)->ranking('kelas');
    expect($rows[0])->toMatchArray(['kelas' => 'XII-2', 'angkatan' => 2024, 'students' => 1, 'count' => 2, 'points' => '160.00'])
        ->and(app(PublicAchievements::class)->ranking('kelas', scope: RankingScope::All))->toBe($rows)
        ->and(app(PublicAchievements::class)->ranking('kelas', scope: RankingScope::Alumni))->toBe([]);

    Livewire::test(Peringkat::class)
        ->call('pilihMode', 'kelas')
        ->assertSet('mode', 'kelas')
        ->assertSee('Kelas XII-2');
});

it('has no class ranking for alumni and keeps the status choice while classes are shown', function (): void {
    // Alumni only: the Kelas button is disabled and a crafted request or URL stays on the student list.
    Livewire::withQueryParams(['status' => 'alumni', 'mode' => 'kelas'])->test(Peringkat::class)
        ->assertSet('mode', 'siswa')
        ->assertSet('status', 'alumni')
        ->assertSeeHtml('disabled aria-describedby="peringkat-catatan"')
        ->call('pilihMode', 'kelas')
        ->assertSet('mode', 'siswa')
        ->assertSee('Ardiansyah Nur Hakim');

    // "Semua" → Kelas → Siswa: the choice is still there (it used to be dropped with the old checkbox).
    Livewire::withQueryParams(['status' => 'semua'])->test(Peringkat::class)
        ->assertDontSeeHtml('disabled aria-describedby="peringkat-catatan"')
        ->call('pilihMode', 'kelas')
        ->assertSet('mode', 'kelas')
        ->assertSet('status', 'semua')
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->assertDontSeeHtml('pilihStatus')
        ->call('pilihMode', 'siswa')
        ->assertSet('status', 'semua')
        ->assertSee('Ardiansyah Nur Hakim');
});

it('applies a filter through the throttled Livewire endpoint and again after a 429', function (): void {
    config(['livewire.sth_throttle.guest_per_minute' => 1]);
    $uri = app('livewire')->getUpdateUri();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $this->get(route('public.peringkat'))->assertOk()->getContent(), $m);
    $payload = ['components' => [[
        'snapshot' => html_entity_decode($m[1] ?? '', ENT_QUOTES),
        'updates' => (object) [],
        'calls' => [['path' => '', 'method' => 'pilihStatus', 'params' => ['alumni']]],
    ]]];

    $first = $this->postJson($uri, $payload, ['X-Livewire' => '1'])->assertOk();

    expect($first->json('components.0.effects.html'))->toContain('Ardiansyah Nur Hakim')->not->toContain('Raka Pradipta')
        ->and($first->json('components.0.snapshot'))->toContain('"status":"alumni"');

    // Too many requests: a short text, nothing changes; the same click works once the window is over.
    $this->postJson($uri, $payload, ['X-Livewire' => '1'])->assertStatus(429);
    $this->travel(61)->seconds();

    $again = $this->postJson($uri, $payload, ['X-Livewire' => '1'])->assertOk();

    expect($again->json('components.0.effects.html'))->toContain('Ardiansyah Nur Hakim')->not->toContain('Raka Pradipta');
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
    'status' => [['status' => 'lulusan']],
    'arrays' => [['mode' => ['x'], 'bidang' => ['y'], 'limit' => ['z'], 'status' => ['q']]],
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
