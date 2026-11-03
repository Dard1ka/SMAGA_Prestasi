<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Peringkat;
use App\Models\User;
use App\Support\Leaderboard\PersonalRank;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Publik\PublikFixtures;
use Tests\Support\TalentGate;

/*
| B4 · backlog #2: siswa.peringkat = public ranking data (named rule, no consent since D-162) for active students only, own row
| marked "Kamu", own rank identical to PersonalRank (D-37, Beranda/Portofolio).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $f = $this->f = new PublikFixtures;
    $raka = $f->student('Raka Pradipta', 'XII-B');
    $salsa = $f->student('Salsabila Kirana', 'XI-E');
    $aurelia = $f->student('Aurelia Maharani', 'X-C');
    $ardi = $f->student('Ardiansyah Nur Hakim', 'XII-C', archived: true, attributes: ['grade12_start_date' => '2024-07-01']);
    $this->nadia = $f->student('Nadia Putri Anindya', 'XI-D', attributes: ['publish_consent' => false]); // old column: ignored (D-162)
    TalentGate::pass($this->nadia); // D-153: else the talent gate holds every siswa page
    $this->dimas = $f->student('Dimas Arya Nugroho', 'XI-A', attributes: ['publish_consent' => false]);

    $fiksi = $f->lomba('FIKSI 2026', Bidang::Riset, Level::Nasional, '2026-09-01');
    $opsi = $f->lomba('OPSI 2025', Bidang::Riset, Level::Nasional, '2025-09-01');
    $fls3n = $f->lomba('FLS3N Nasional 2025', Bidang::SeniBudaya, Level::Nasional, '2025-11-01');
    $bio = $f->lomba('Kompetisi Biologi Nasional 2026', Bidang::Sains, Level::Nasional, '2026-03-01');
    $wice = $f->lomba('WICE 2026', Bidang::Riset, Level::Nasional, '2026-09-24');
    $kota = $f->lomba('Lomba Esai Kota 2026', Bidang::Riset, Level::Kota, '2026-02-01');
    $osn = $f->lomba('OSN Internasional 2024', Bidang::Sains, Level::Internasional, '2024-10-05');

    $f->prestasi($raka, $fiksi, ResultType::Juara1);          // 80
    $f->prestasi($raka, $opsi, ResultType::Juara1);           // 80 → 160
    $f->prestasi($salsa, $fls3n, ResultType::Juara1);         // 80
    $f->prestasi($salsa, $opsi, ResultType::Juara3);          // 56 → 136
    $f->prestasi($aurelia, $bio, ResultType::Juara1);         // 80
    $f->prestasi($this->nadia, $wice, ResultType::Juara2);    // 68
    $f->prestasi($this->dimas, $kota, ResultType::Juara2);    // 34
    $f->prestasi($ardi, $osn, ResultType::Juara1);            // 100 (alumni: never in this list)
    $f->prestasi($ardi, $fiksi, ResultType::Juara1);          // 80 → 180
});

it('lets only students open the page', function (): void {
    $this->get(route('siswa.peringkat'))->assertRedirect(route('login'));

    $guru = User::factory()->staff(RoleName::Guru)->create();
    $this->actingAs($guru)->get(route('siswa.peringkat'))->assertForbidden();

    $this->actingAs($this->nadia->user)->get(route('siswa.peringkat'))
        ->assertOk()
        ->assertSee('Posisimu')
        ->assertSee('Kamu');
});

it('marks the own row with the own name and names the other students too (D-162)', function (): void {
    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Kamu')
        ->assertSee('Dimas Arya Nugroho')
        ->assertDontSee('Siswa kelas XI')
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->assertSeeInOrder(['Raka Pradipta', 'Salsabila Kirana', 'Aurelia Maharani', 'Nadia Putri Anindya'])
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 4 && $me['me'] === true && $me['points_label'] === '68');
});

it('shows the same personal rank as Beranda (PersonalRank, active students only)', function (): void {
    $personal = app(PersonalRank::class)->rank($this->nadia);

    expect($personal)->toBe(4);

    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->assertViewHas('summary', fn (array $s): bool => $s['rank'] === $personal && $s['points'] === '68' && $s['count'] === 1)
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === $personal)
        ->assertSee('#4')
        ->assertSee('dari siswa aktif');
});

it('finds the own row by the public slug, whatever the old consent column says (D-162)', function (): void {
    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 4 && $me['named'] === true && $me['slug'] !== null);
});

it('leaves a disabled classmate out of the list', function (): void {
    $laras = $this->f->student('Laras Wening Pangestu', 'XI-G', disabled: true);
    $this->f->prestasi($laras, $this->f->lomba('Olimpiade Riset Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-01'), ResultType::Juara1);

    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->assertDontSee('Laras Wening Pangestu')
        ->assertDontSee('Siswa kelas XI')
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 4);
});

it('recomputes the own rank under the bidang and angkatan filters', function (): void {
    $page = Livewire::actingAs($this->nadia->user)->test(Peringkat::class);

    // Riset: Raka 160 · Nadia 68 · Salsabila 56 · Dimas 34
    $page->set('bidang', Bidang::Riset->value)
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 2 && $me['points_label'] === '68')
        ->assertSee('Di filter ini kamu di peringkat');

    // Olahraga: nothing of Nadia's.
    $page->set('bidang', Bidang::Olahraga->value)
        ->assertViewHas('me', fn (?array $me): bool => $me === null)
        ->assertSee('Belum ada data untuk filter ini.');

    // Angkatan 2025 (kelas XI): Salsabila 136 · Nadia 68 · Dimas 34
    $page->set('bidang', '')->set('angkatan', '2025')
        ->assertViewHas('me', fn (array $me): bool => $me['rank'] === 2);

    $page->call('resetFilter')->assertSet('angkatan', '')->assertSet('bidang', '');
});

it('offers only the angkatan of active students and links to the public alumni ranking (M1-C)', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->withQueryParams(['angkatan' => '2022']) // the alumni year: not a choice here
        ->test(Peringkat::class)
        ->assertSet('angkatan', '')
        ->assertViewHas('angkatanOptions', fn (array $options): bool => array_keys($options) === [2026, 2025, 2024])
        ->assertViewHas('publicUrl', route('public.peringkat', ['status' => 'alumni']))
        ->assertDontSee('Ardiansyah Nur Hakim')
        ->assertDontSee('XII-C');
});

it('ignores unknown filter values', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->withQueryParams(['bidang' => 'sulap', 'angkatan' => '1999', 'mode' => 'x', 'limit' => 9999])
        ->test(Peringkat::class)
        ->assertSet('bidang', '')
        ->assertSet('angkatan', '')
        ->assertSet('mode', 'siswa')
        ->assertSet('limit', Peringkat::MAX);
});

it('marks the own class in kelas mode', function (): void {
    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->call('pilihMode', 'kelas')
        ->assertSee('Kelasmu')
        ->assertViewHas('me', fn (array $me): bool => $me['kelas'] === 'XI-D' && $me['me'] === true);
});

it('keeps the own row on screen when it is below the shown part', function (): void {
    $lomba = $this->f->lomba('Olimpiade Riset Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-01');

    foreach (range(1, Peringkat::STEP) as $i) {
        $this->f->prestasi($this->f->student('Siswa Contoh '.$i, 'X-A'), $lomba, ResultType::Juara1); // 80 each
    }

    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->assertViewHas('me', fn (array $me): bool => $me['position'] > Peringkat::STEP)
        ->assertViewHas('meExtra', fn (?array $row): bool => $row !== null && $row['me'] === true)
        ->assertSee('Nadia Putri Anindya');
});

it('shows an empty personal card for a student without verified achievements', function (): void {
    $hana = $this->f->student('Hana Safira', 'X-H');

    Livewire::actingAs($hana->user)->test(Peringkat::class)
        ->assertSee('Belum ada prestasi terverifikasi.')
        ->assertViewHas('me', fn (?array $me): bool => $me === null);
});

it('points the siswa menu and tab bar to the in-app ranking', function (): void {
    $user = $this->nadia->user;
    $menu = Navigation::for($user, 'siswa', currentRoute: 'siswa.peringkat');
    $item = collect($menu['sections'])->flatMap(fn (array $s): array => $s['items'])->firstWhere('key', 'peringkat');
    $tab = collect($menu['tabbar'])->firstWhere('key', 'peringkat');

    expect($item['href'])->toBe(route('siswa.peringkat'))
        ->and($item['current'])->toBeTrue()
        ->and($tab['href'])->toBe(route('siswa.peringkat'));
});
