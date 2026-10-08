<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Publik\Data\PublicAchievements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
});

/** Small fictional data set (DESIGN-SPEC-v2 §5 names). @return array<string, mixed> */
function publikDataSet(): array
{
    $f = new PublikFixtures;
    $nadia = $f->student('Nadia Putri Anindya', 'XI-4');
    $raka = $f->student('Raka Pradipta', 'XII-2');
    $opsi = $f->lomba('OPSI 2026', Bidang::Riset, Level::Nasional, '2026-09-20');
    $fiksi = $f->lomba('FIKSI 2025', Bidang::Riset, Level::Nasional, '2025-10-12');
    $o2sn = $f->lomba('O2SN Kota Semarang 2026', Bidang::Olahraga, Level::Kota, '2026-02-14');

    $f->prestasi($nadia, $opsi, ResultType::Juara2);
    $f->prestasi($raka, $fiksi, ResultType::Juara1);
    $f->prestasi($raka, $o2sn, ResultType::Juara1);

    return compact('f', 'nadia', 'raka', 'opsi', 'fiksi', 'o2sn');
}

it('renders every public page with data', function (): void {
    $data = publikDataSet();

    $this->get(route('public.home'))
        ->assertOk()
        ->assertSee('Prestasi siswa SMAGA, dalam satu tempat.')
        ->assertSee('Prestasi terverifikasi')
        ->assertSee('Lomba diikuti 2026/2027')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('OPSI 2026')
        ->assertSee('Peringkat teratas');

    $this->get(route('public.prestasi'))
        ->assertOk()
        ->assertSee('Prestasi per bidang')
        ->assertSee('Siswa berprestasi')
        ->assertSee('Raka Pradipta');

    $slug = $data['f']->slug($data['nadia']);

    $this->get(route('public.prestasi.siswa', $slug))
        ->assertOk()
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('OPSI 2026')
        ->assertSee('80 × 0,85 × 1,00 = 68')
        ->assertSee('Terverifikasi');

    $this->get(route('public.lomba.pemenang', $data['fiksi']->id))
        ->assertOk()
        ->assertSee('FIKSI 2025')
        ->assertSee('Juara I')
        ->assertSee('Raka Pradipta');

    $this->get(route('public.peringkat'))
        ->assertOk()
        ->assertSee('Peringkat prestasi')
        ->assertSee('Cara poin dihitung')
        ->assertSee('Anggota tim masing-masing mendapat poin penuh.')
        ->assertSee('Raka Pradipta');
});

it('renders the aggregate pages with an empty database', function (string $route): void {
    $this->get(route($route))->assertOk();
})->with(['public.home', 'public.prestasi', 'public.peringkat']);

it('shows friendly empty states when nothing is verified yet', function (): void {
    $this->get(route('public.home'))->assertOk()->assertSee('Belum ada prestasi terverifikasi');
    $this->get(route('public.prestasi'))->assertOk()->assertSee('Belum ada prestasi untuk filter ini.');
    $this->get(route('public.peringkat'))->assertOk()->assertSee('Belum ada data untuk filter ini.');
});

it('has exactly one h1 on every public page', function (): void {
    $data = publikDataSet();
    $slug = $data['f']->slug($data['nadia']);

    foreach ([route('public.home'), route('public.prestasi'), route('public.prestasi', ['siswa' => $slug]), route('public.prestasi.siswa', $slug), route('public.lomba.pemenang', $data['opsi']->id), route('public.peringkat')] as $url) {
        expect(substr_count($this->get($url)->assertOk()->getContent(), '<h1'))->toBe(1, $url);
    }
});

it('links the student page from the drill-down and opens level 3 via ?siswa=', function (): void {
    $data = publikDataSet();
    $slug = $data['f']->slug($data['nadia']);

    $this->get(route('public.prestasi', ['bidang' => 'riset', 'siswa' => $slug]))
        ->assertOk()
        ->assertSee('Buka halaman siswa')
        ->assertSee(route('public.prestasi.siswa', $slug), false)
        ->assertSee('80 × 0,85 × 1,00 = 68');
});

it('makes new data visible after PublicAchievements::flush()', function (): void {
    $f = new PublikFixtures;
    $this->get(route('public.home'))->assertOk()->assertDontSee('Keisha Larasati');

    $keisha = $f->student('Keisha Larasati', 'XI-6');
    $f->prestasi($keisha, $f->lomba('NSIF 2026', Bidang::Riset, Level::Nasional, '2026-08-20'), ResultType::MedaliEmas);

    $this->get(route('public.home'))->assertOk()->assertDontSee('Keisha Larasati');

    PublicAchievements::flush();

    $this->get(route('public.home'))->assertOk()->assertSee('Keisha Larasati')->assertSee('NSIF 2026');
});

// QA7: explore round after UI3 (2026-10-08) ---------------------------------------------------------

it('lets a long lomba name or penyelenggara wrap on the public cards instead of widening the page (QA7)', function (): void {
    $data = publikDataSet();

    $this->get(route('public.home'))
        ->assertOk()
        ->assertSee('class="flex flex-1 flex-col gap-1 px-[18px] pt-3.5 pb-4 [overflow-wrap:anywhere]"', false);

    $this->get(route('public.prestasi.siswa', $data['f']->slug($data['nadia'])))
        ->assertOk()
        ->assertSee('class="flex min-w-0 flex-col gap-1 [overflow-wrap:anywhere]"', false);
});
