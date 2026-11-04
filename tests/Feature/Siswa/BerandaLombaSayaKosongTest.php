<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Livewire\Siswa\Beranda;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| Second browser check (2026-10-31): Siswa › Beranda said "Belum ada lomba yang kamu ikuti" to a student whose lomba were
| all over, because its "Lomba saya" lists running registrations only (Beranda::myRegistrations(), as Lomba saya ›
| Berjalan). Such a student now reads "Belum ada lomba berjalan"; a student who never registered keeps the old sentence.
| Fictional data only (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->withoutVite();
    $this->nadia = F::student();
    User::query()->whereKey($this->nadia->user_id)->update(['tour_seen_at' => now()]);
});

/** A registration of $leader for a fresh open lomba called $name, in $status (the factory adds the ketua row). */
function r3BerandaRegistration(Student $leader, string $name, RegistrationStatus $status, array $attributes = []): Registration
{
    return Registration::factory()->status($status)->create([
        'competition_id' => F::openCompetition(['name' => $name])->getKey(),
        'leader_student_id' => $leader->getKey(),
        ...$attributes,
    ]);
}

it('keeps "Belum ada lomba yang kamu ikuti" for a student who never registered', function (): void {
    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('mine', [])
        ->assertViewHas('pastOnly', false)
        ->assertSee('Belum ada lomba yang kamu ikuti')
        ->assertSee('Pilih lomba di katalog, lalu daftar sendiri atau bersama tim.')
        ->assertDontSee('Belum ada lomba berjalan');
});

it('says "Belum ada lomba berjalan" to a student whose lomba are all over', function (RegistrationStatus $status): void {
    r3BerandaRegistration($this->nadia, 'Kompetisi Biologi Nasional 2026', $status);

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('mine', [])
        ->assertViewHas('pastOnly', true)
        ->assertSee('Belum ada lomba berjalan')
        ->assertSee('Riwayat lombamu ada di Lomba saya, tab Selesai.')
        ->assertDontSee('Belum ada lomba yang kamu ikuti');

    // The same words as Lomba saya, whose "Berjalan" tab is empty for this student too.
    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba-saya.index'))
        ->assertOk()
        ->assertSee('Belum ada lomba berjalan');
})->with([
    'selesai' => [RegistrationStatus::Selesai],
    'ditolak' => [RegistrationStatus::Ditolak],
    'dibatalkan' => [RegistrationStatus::Dibatalkan],
]);

it('lists a running registration next to a finished one and shows no empty state', function (): void {
    r3BerandaRegistration($this->nadia, 'OPSI 2025', RegistrationStatus::Selesai);
    r3BerandaRegistration($this->nadia, 'Kompetisi Biologi Nasional 2026', RegistrationStatus::MenungguKepsek);

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('mine', fn (array $mine): bool => array_column($mine, 'title') === ['Kompetisi Biologi Nasional 2026'])
        ->assertViewHas('pastOnly', false)
        ->assertDontSee('Belum ada lomba berjalan')
        ->assertDontSee('Belum ada lomba yang kamu ikuti');
});

it('counts a declined invitation and one not sent yet as no lomba, as Lomba saya does', function (): void {
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');

    // Nadia said no to Dimas's team, whose lomba is over now: that registration is not hers.
    $declined = r3BerandaRegistration($dimas, 'OPSI 2025', RegistrationStatus::Selesai, ['type' => RegistrationType::Tim]);
    RegistrationMember::factory()->declined()->create(['registration_id' => $declined->getKey(), 'student_id' => $this->nadia->getKey()]);

    // D-148: Dimas's team for his own lomba still waits for Kesiswaan, so Nadia was not invited yet and knows nothing of it.
    $waiting = Registration::factory()->status(RegistrationStatus::MenungguLomba)->create([
        'competition_id' => F::pendingCompetition(F::user($dimas), ['name' => 'ISPO 2027'])->getKey(),
        'leader_student_id' => $dimas->getKey(),
        'type' => RegistrationType::Tim,
    ]);
    RegistrationMember::factory()->create(['registration_id' => $waiting->getKey(), 'student_id' => $this->nadia->getKey(), 'invited_at' => null]);

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('mine', [])
        ->assertViewHas('pastOnly', false)
        ->assertSee('Belum ada lomba yang kamu ikuti')
        ->assertDontSee('ISPO 2027');
});

it('points "Lihat semua" at the Selesai tab when every lomba of the student is over (SV1 review)', function (): void {
    r3BerandaRegistration($this->nadia, 'Kompetisi Biologi Nasional 2026', RegistrationStatus::Selesai);
    $selesai = route('siswa.lomba-saya.index', ['tab' => 'selesai']);

    // The hint names the tab Selesai; the link of the section opens it (the tab Berjalan is empty for this student).
    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('pastOnly', true)
        ->assertViewHas('links', fn (array $links): bool => $links['lombaSaya'] === $selesai)
        ->assertSee('Riwayat lombamu ada di Lomba saya, tab Selesai.')
        ->assertSeeHtml('href="'.e($selesai).'"');

    $this->actingAs(F::user($this->nadia))->get($selesai)
        ->assertOk()
        ->assertSee('Kompetisi Biologi Nasional 2026')
        ->assertDontSee('Belum ada lomba berjalan');
});

it('keeps "Lihat semua" on the Berjalan tab for a running lomba and for a student who never registered', function (): void {
    $berjalan = route('siswa.lomba-saya.index');

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('pastOnly', false)
        ->assertViewHas('links', fn (array $links): bool => $links['lombaSaya'] === $berjalan);

    r3BerandaRegistration($this->nadia, 'OPSI 2025', RegistrationStatus::Selesai);
    r3BerandaRegistration($this->nadia, 'Kompetisi Biologi Nasional 2026', RegistrationStatus::MenungguKepsek);

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertViewHas('pastOnly', false)
        ->assertViewHas('links', fn (array $links): bool => $links['lombaSaya'] === $berjalan)
        ->assertSeeHtml('href="'.e($berjalan).'"')
        ->assertDontSeeHtml('tab=selesai');
});
