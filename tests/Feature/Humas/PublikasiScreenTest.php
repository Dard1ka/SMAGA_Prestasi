<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\FilePurpose;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Livewire\Humas\Publikasi;
use App\Models\Appreciation;
use App\Models\File;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationScheduled;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Humas · Publikasi (Livewire): lanes, consent filter, panel, schedule, publish, flyer, apresiasi.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

it('lists only verified achievements with publish consent', function (): void {
    HumasFixtures::publication(studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan', competitionName: 'O2SN Provinsi 2026');

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertOk()
        ->assertSee('OPSI 2026')
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('Fajar Ramadhan')
        ->assertSee('Pembimbing Bu Retno Wulandari')
        ->assertSee('Juara II Nasional')
        ->assertSee('Izin foto ada');
});

it('preselects the first ready card and suggests a caption', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $publication->getKey())
        ->assertSet('caption', "Selamat kepada Nadia Putri Anindya (XI-4), Juara II OPSI 2026 tingkat nasional. Terima kasih Bu Retno Wulandari atas bimbingannya.\n#SMAGASpectacular #SMAN3Semarang")
        ->assertSet('channels', ['instagram', 'website'])
        ->assertSee('Siapkan publikasi')
        ->assertSee('SELAMAT &amp; SUKSES', false)
        ->assertSee('Kelas XI-4')
        ->assertSee('Pembimbing: Bu Retno Wulandari, S.Pd.');
});

it('shows the initials note when there is no photo consent', function (): void {
    HumasFixtures::publication(['photo_consent' => false]);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Tanpa foto')
        ->assertSee('Tanpa izin foto, flyer otomatis memakai inisial.');
});

it('selects a card, and 404s for a publication outside the visible queue', function (): void {
    HumasFixtures::publication();
    $other = HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->addDay(), 'channels' => ['instagram']], studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $hidden = HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan');

    $component = Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->call('select', $other->getKey())
        ->assertSet('selectedId', $other->getKey())
        ->assertSee('Jadwalkan ulang');

    $component->call('select', $hidden->getKey())->assertNotFound();
});

it('keeps the selected id locked', function (): void {
    $publication = HumasFixtures::publication();
    $hidden = HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan');

    expect(fn () => Livewire::actingAs($this->humas)->test(Publikasi::class)->set('selectedId', $hidden->getKey()))
        ->toThrow(Exception::class);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
});

it('preselects ?pilih= and 404s when it is not visible', function (): void {
    HumasFixtures::publication();
    $second = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $hidden = HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan');

    Livewire::withQueryParams(['pilih' => $second->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $second->getKey());

    Livewire::withQueryParams(['pilih' => $hidden->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertNotFound();
});

it('schedules from the panel', function (): void {
    $publication = HumasFixtures::publication();
    $tomorrow = CarbonImmutable::now()->addDay();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('caption', 'Selamat, Nadia!')
        ->set('channels', ['instagram'])
        ->set('date', $tomorrow->format('Y-m-d'))
        ->set('time', '09:00')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Publikasi dijadwalkan.', tone: 'success');

    $fresh = $publication->fresh();
    expect($fresh->status)->toBe(PublicationStatus::Terjadwal)
        ->and($fresh->scheduled_at->format('Y-m-d H:i'))->toBe($tomorrow->format('Y-m-d').' 09:00')
        ->and($fresh->channels)->toBe(['instagram']);
});

it('shows field errors from the schedule action', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', [])
        ->set('date', now()->addDay()->format('Y-m-d'))
        ->set('time', '09:00')
        ->call('schedule')
        ->assertHasErrors(['channels']);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('date', now()->subDay()->format('Y-m-d'))
        ->set('time', '09:00')
        ->call('schedule')
        ->assertHasErrors(['date']);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('date', '')
        ->call('schedule')
        ->assertHasErrors(['date']);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
});

it('publishes from the panel and notifies the student', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', ['instagram', 'mading'])
        ->call('publish')
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertSee('Sudah terbit');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->fresh()->channels)->toBe(['instagram', 'mading']);
    Notification::assertSentTo($publication->achievement->student->user, AchievementPublished::class);
});

it('downloads a generated flyer PNG', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('template', 'emas')
        ->call('downloadFlyer')
        ->assertFileDownloaded('flyer-nadia-putri-anindya.png');

    $file = File::query()->sole();
    expect($file->purpose)->toBe(FilePurpose::Flyer)
        ->and($publication->fresh()->flyer_file_id)->toBe($file->getKey())
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue();

});

it('rejects an unknown flyer template', function (): void {
    HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('template', 'neon')
        ->call('downloadFlyer')
        ->assertHasErrors(['template'])
        ->assertNoFileDownloaded();

    expect(File::query()->count())->toBe(0);
});

it('puts the achievement on an upcoming appreciation event', function (): void {
    $publication = HumasFixtures::publication();
    $event = HumasFixtures::event();
    HumasFixtures::event('Upacara lalu', now()->subWeek()->toDateString());

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertDontSee('Upacara lalu')
        ->call('recordAppreciation')
        ->assertHasErrors(['eventId'])
        ->set('eventId', $event->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertSee('Sudah masuk daftar apresiasi Upacara Senin, 5 Oktober 2026');

    $appreciation = Appreciation::query()->sole();
    expect($appreciation->status)->toBe(AppreciationStatus::Dijadwalkan)
        ->and($appreciation->achievement_id)->toBe($publication->achievement_id);
    Notification::assertSentTo($publication->achievement->student->user, AppreciationScheduled::class);
});

// M2-B (QA Q2-10): one achievement sits on one event, so the panel offers no second "Masukkan".
it('offers no second appreciation event once the achievement is on one, and refuses a forged one', function (): void {
    $publication = HumasFixtures::publication();
    $first = HumasFixtures::event();
    $second = HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString());

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Masukkan ke acara apresiasi')
        ->assertSee('Upacara Hari Guru')
        ->set('eventId', $first->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertSee('Sudah masuk daftar apresiasi Upacara Senin, 5 Oktober 2026')
        ->assertDontSee('Masukkan ke acara apresiasi')
        ->assertDontSee('Upacara Hari Guru')
        ->set('eventId', $second->getKey())
        ->call('recordAppreciation')
        ->assertHasErrors(['eventId']);

    expect(Appreciation::query()->sole()->appreciation_event_id)->toBe($first->getKey());
    Notification::assertSentToTimes($publication->achievement->student->user, AppreciationScheduled::class, 1);
});

it('is forbidden for roles without publication.manage', function (RoleName $role): void {
    HumasFixtures::publication();

    Livewire::actingAs(AuthTestHelpers::staff($role))->test(Publikasi::class)->assertForbidden();
})->with([RoleName::Kesiswaan, RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('filters the board with the search term', function (): void {
    HumasFixtures::publication(studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    Livewire::withQueryParams(['q' => 'fiksi'])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Raka Pradipta')
        ->assertSee('Hasil pencarian untuk');
});

it('shows an empty state without publications', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Belum ada prestasi untuk dipublikasikan');
});
