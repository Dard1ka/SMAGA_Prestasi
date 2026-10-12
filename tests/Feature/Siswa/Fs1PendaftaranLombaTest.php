<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    $this->withoutVite();
    $this->nadia = F::student();
    User::query()->whereKey($this->nadia->user_id)->update(['tour_seen_at' => now()]);
});

it('titles the registration panel "Pendaftaran lomba" (FS1 §2.1)', function (): void {
    $lomba = F::openCompetition();

    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba.show', $lomba))->assertOk()
        ->assertSee('<h2 id="h-daftar" class="title-section m-0">Pendaftaran lomba</h2>', false)
        ->assertDontSee('>Daftar lomba</h2>', false);
});

it('names "Pendaftaran lomba" in Bantuan', function (): void {
    $this->actingAs(F::user($this->nadia))->get(route('siswa.bantuan'))->assertOk()
        ->assertSee('Pendaftaran lomba')
        ->assertSee('Buka Cari lomba, pilih lomba, lalu isi panel Pendaftaran lomba.')
        ->assertDontSee('klik Daftar lomba');
});

it('keeps the tracker phase "Daftar" and the Kesiswaan tab list label "Daftar lomba"', function (): void {
    expect(RegistrationStatus::phaseLabel(1))->toBe('Daftar');

    Livewire::actingAs(KesiswaanFixtures::staff(RoleName::Kesiswaan))->test(Index::class)
        ->assertSeeHtml('aria-label="Daftar lomba"');
});
