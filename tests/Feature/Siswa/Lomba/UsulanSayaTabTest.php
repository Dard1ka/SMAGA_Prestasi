<?php

declare(strict_types=1);

use App\Livewire\Siswa\Lomba\Katalog;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
| FS1 Paket B (spec §5.2): Cari lomba gets the tab "Usulan saya": the student's own proposals of every status, oldest
| first, each opening its Detail lomba (CompetitionPolicy::view lets the submitter in).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->nadiaUser = LombaFixtures::user(LombaFixtures::student());
});

it('lists the own lomba of every status, oldest first', function (): void {
    $a = LombaFixtures::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Pertama', 'created_at' => now()->subDays(3)]);
    $b = Competition::factory()->rejected()->create(['name' => 'Usulan Kedua', 'submitted_by' => $this->nadiaUser->getKey(), 'created_at' => now()->subDay()]);
    $c = Competition::factory()->published()->create(['name' => 'Usulan Ketiga', 'submitted_by' => $this->nadiaUser->getKey(), 'created_at' => now()->subHour()]);
    Competition::factory()->create(['name' => 'Usulan Orang Lain']);

    Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'usulan')
        ->assertSeeInOrder(['Usulan Pertama', 'Usulan Kedua', 'Usulan Ketiga'])
        ->assertSee('Menunggu verifikasi')->assertSee('Ditolak')->assertSee('Dipublikasikan')
        ->assertDontSee('Usulan Orang Lain')
        ->assertSeeHtml('href="'.route('siswa.lomba.show', $a).'"')
        ->assertSeeHtml('href="'.route('siswa.lomba.show', $b).'"');
});

it('keeps the catalog on the first tab and hides its filters on "Usulan saya"', function (): void {
    LombaFixtures::openCompetition(['name' => 'Lomba Katalog Fiktif 2026']);
    LombaFixtures::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Pertama']);

    Livewire::actingAs($this->nadiaUser)->test(Katalog::class)
        ->assertSee('Lomba Katalog Fiktif 2026')->assertDontSee('Usulan Pertama')->assertSee('Semua tingkat')
        ->call('pilihTab', 'usulan')->assertSet('tab', 'usulan')
        ->assertSee('Usulan Pertama')->assertDontSee('Lomba Katalog Fiktif 2026')->assertDontSee('Semua tingkat');
});

it('says so when the student proposed nothing yet', fn () => Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'usulan')->assertSee('Belum ada usulan lomba')->assertSee('Lomba yang kamu usulkan tampil di sini'));

it('ignores an unknown tab', fn () => Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'x')->assertSet('tab', ''));

it('opens the tab from the address', function (): void {
    LombaFixtures::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Pertama']);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index', ['tab' => 'usulan']))
        ->assertOk()
        ->assertSee('Usulan Pertama');
});
