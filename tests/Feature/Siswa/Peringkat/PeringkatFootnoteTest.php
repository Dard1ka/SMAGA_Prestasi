<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Siswa\Peringkat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Publik\PublikFixtures;
use Tests\Support\TalentGate;

/*
| Second browser check (2026-10-31): the footnote of Siswa › Peringkat still said "Nama siswa lain hanya tampil jika mereka
| setuju dipublikasikan." There is no consent since D-162: every student in this list of active students is named, and a
| disabled or deleted account has no row at all (NamedStudent). The footnote keeps only what is true. Fictional data only
| (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $f = new PublikFixtures;
    $this->nadia = $f->student('Nadia Putri Anindya', 'XI-D');
    TalentGate::pass($this->nadia); // D-153: else the talent gate holds every siswa page
    $dimas = $f->student('Dimas Arya Nugroho', 'XI-A');

    $f->prestasi($this->nadia, $f->lomba('WICE 2026', Bidang::Riset, Level::Nasional, '2026-09-24'), ResultType::Juara2);
    $f->prestasi($dimas, $f->lomba('Lomba Esai Kota 2026', Bidang::Riset, Level::Kota, '2026-02-01'), ResultType::Juara2);
});

it('says the list holds active students and points alumni to the public ranking, without a consent sentence (D-162)', function (string $mode): void {
    Livewire::actingAs($this->nadia->user)->test(Peringkat::class)
        ->call('pilihMode', $mode)
        ->assertSee('Peringkat ini hanya memuat siswa aktif. Alumni ada di')
        ->assertSeeHtml('href="'.e(route('public.peringkat', ['status' => 'alumni'])).'" class="font-semibold">peringkat publik</a>')
        ->assertDontSee('setuju')
        ->assertDontSee('Nama siswa lain hanya tampil');
})->with(['siswa', 'kelas']);

it('shows the same footnote on the page itself', function (): void {
    $this->actingAs($this->nadia->user)->get(route('siswa.peringkat'))
        ->assertOk()
        ->assertSee('Peringkat ini hanya memuat siswa aktif.')
        // Every student of the list is named (D-162), so the page names the classmate too.
        ->assertSee('Dimas Arya Nugroho')
        ->assertDontSee('setuju dipublikasikan');
});
