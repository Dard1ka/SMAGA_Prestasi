<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Arsip;
use App\Livewire\Humas\Jadwal;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Notifications\Publications\AppreciationScheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Humas · Jadwal, Apresiasi (shared with Kesiswaan), Arsip (Livewire).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

// --- Jadwal --------------------------------------------------------------------------------

it('lists scheduled publications by date and upcoming appreciation events', function (): void {
    $scheduled = HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now()->addDays(2)->setTime(9, 0),
        'channels' => ['instagram', 'website'],
    ], studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now()->subDay(),
        'channels' => ['instagram'],
    ], studentName: 'Salsabila Kirana', competitionName: 'FLS3N Provinsi 2026');
    HumasFixtures::publication(['publish_consent' => false], ['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->addDay()], studentName: 'Fajar Ramadhan');

    $event = HumasFixtures::event();
    $appreciation = new Appreciation(['achievement_id' => $scheduled->achievement_id, 'appreciation_event_id' => $event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertOk()
        ->assertSee('OPSI 2026')
        ->assertSee('Instagram, Website')
        ->assertSee('Lewat jadwal, belum ditandai terbit')
        ->assertSee('FLS3N Provinsi 2026')
        ->assertDontSee('Fajar Ramadhan')
        ->assertSee(route('humas.beranda', ['pilih' => $scheduled->getKey()]), false)
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertSee('1 prestasi');
});

it('shows empty states on Jadwal', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSee('Belum ada publikasi terjadwal')
        ->assertSee('Belum ada acara apresiasi');
});

it('forbids Jadwal without publication.manage', function (): void {
    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))->test(Jadwal::class)->assertForbidden();
});

// --- Apresiasi -----------------------------------------------------------------------------

it('creates an event from the form', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', 'Upacara Senin, 12 Oktober 2026')
        ->set('heldOn', now()->addWeek()->toDateString())
        ->call('createEvent')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Acara apresiasi dibuat.', tone: 'success')
        ->assertSet('name', '')
        ->assertSee('Upacara Senin, 12 Oktober 2026');

    expect(AppreciationEvent::query()->count())->toBe(1);
});

it('shows event form errors', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', '')
        ->set('heldOn', now()->subDay()->toDateString())
        ->call('createEvent')
        ->assertHasErrors(['name']);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', 'Upacara')
        ->set('heldOn', now()->subDay()->toDateString())
        ->call('createEvent')
        ->assertHasErrors(['heldOn']);

    expect(AppreciationEvent::query()->count())->toBe(0);
});

it('adds a verified achievement to an event and marks a held event done', function (): void {
    $achievement = HumasFixtures::achievement();
    HumasFixtures::achievement(['publish_consent' => false], studentName: 'Fajar Ramadhan');
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $event->getKey())
        ->assertSet('addingEventId', $event->getKey())
        ->assertSee('Nadia Putri Anindya (XI-4)')
        ->assertDontSee('Fajar Ramadhan')
        ->call('addAchievement')
        ->assertHasErrors(['achievementId'])
        ->set('achievementId', $achievement->getKey())
        ->call('addAchievement')
        ->assertHasNoErrors()
        ->assertSet('addingEventId', null)
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertSee('Dijadwalkan');

    Notification::assertSentTo($achievement->student->user, AppreciationScheduled::class);

    $component->call('complete', $event->getKey())
        ->assertDispatched('sth-toast', message: '1 apresiasi ditandai selesai.', tone: 'success');

    expect(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai);
});

it('keeps the adding event id locked and 404s unknown events', function (): void {
    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('addingEventId', 'x'))
        ->toThrow(Exception::class);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
});

it('works for Kesiswaan (appreciation.manage) and not for other roles', function (): void {
    HumasFixtures::event();

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))
        ->test(Apresiasi::class)
        ->assertOk()
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertSee('Buat acara apresiasi');

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Guru))->test(Apresiasi::class)->assertForbidden();
    Livewire::actingAs(AuthTestHelpers::staff(RoleName::TataUsaha))->test(Apresiasi::class)->assertForbidden();
});

// --- Arsip ---------------------------------------------------------------------------------

it('lists published publications newest first with search', function (): void {
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(9), 'channels' => ['instagram']], studentName: 'Rizky Maulana', competitionName: 'O2SN Kota 2026');
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(2), 'channels' => ['instagram', 'website']], studentName: 'Nadia Putri Anindya', competitionName: 'KRENOVA Kota 2026');
    HumasFixtures::publication(['publish_consent' => false], ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(20)], studentName: 'Hana Lestari', competitionName: 'Kimia Jateng 2026');
    HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertOk()
        ->assertSeeInOrder(['KRENOVA Kota 2026', 'O2SN Kota 2026', 'Kimia Jateng 2026'])
        ->assertSee('Instagram, Website')
        ->assertSee('Izin dicabut')
        ->assertDontSee('FIKSI 2026')
        ->set('q', 'rizky')
        ->assertSee('O2SN Kota 2026')
        ->assertDontSee('KRENOVA Kota 2026')
        ->set('q', 'tidak-ada')
        ->assertSee('Tidak ada publikasi yang cocok');
});

it('paginates the archive by 20', function (): void {
    for ($i = 1; $i <= 21; $i++) {
        HumasFixtures::publication(
            publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays($i), 'channels' => ['website']],
            studentName: 'Siswa '.$i,
            competitionName: sprintf('Lomba %02d', $i),
        );
    }

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertSee('Halaman 1 dari 2')
        ->assertSee('Lomba 01')
        ->assertDontSee('Lomba 21')
        ->call('nextPage')
        ->assertSee('Lomba 21');
});

it('forbids Arsip without publication.manage', function (): void {
    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))->test(Arsip::class)->assertForbidden();
});
