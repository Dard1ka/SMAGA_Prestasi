<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\Publikasi;
use App\Models\Appreciation;
use App\Models\File;
use App\Models\Publication;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationScheduled;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Humas · Publikasi (Livewire): lanes, consent filter, panel, data for the flyer (M3-E: no generator),
| schedule, publish, apresiasi.
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
        ->assertSee('Siapkan publikasi');
});

// --- M3-E: "Data untuk flyer" (Humas designs the flyer itself; STH only hands over the facts) ------------

it('shows the data for the flyer as one fact per line, with a copy button for the whole block', function (): void {
    $publication = HumasFixtures::publication();
    $publication->achievement->competition->forceFill([
        'organizer_name' => 'Pusat Prestasi Nasional',
        'starts_on' => '2026-11-05',
        'final_on' => '2026-11-07',
    ])->save();
    $publication->achievement->report->forceFill(['research_title' => 'Biofilter Limbah Batik dari Sabut Kelapa'])->save();

    $html = Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Data untuk flyer')
        ->assertSeeInOrder([
            'Nama:', 'Nadia Putri Anindya',
            'Kelas:', 'XI-4',
            'Capaian:', 'Juara II',
            'Lomba:', 'OPSI 2026',
            'Tingkat:', 'Nasional',
            'Penyelenggara:', 'Pusat Prestasi Nasional',
            'Bidang:', 'Riset',
            'Tanggal lomba:', '5–7 November 2026',
            'Guru pembimbing:', 'Bu Retno Wulandari, S.Pd.',
            'Judul penelitian:', 'Biofilter Limbah Batik dari Sabut Kelapa',
        ])
        // The contract of the copy hook (app.js): a plain button that names the element to copy.
        ->assertSeeHtml('data-sth-copy="#data-flyer"')
        ->assertSeeHtml('<dl id="data-flyer"')
        ->assertSee('Salin data')
        ->assertDontSee('tidak mengizinkan fotonya dipakai')
        ->html();

    // What "Salin data" copies is the text of that one element: facts only, no points, no helper text.
    $start = strpos($html, '<dl id="data-flyer"');
    $block = substr($html, $start, strpos($html, '</dl>', $start) - $start);
    $text = html_entity_decode(strip_tags($block));

    expect($start)->not->toBeFalse()
        ->and($block)->toContain('<dt class="inline text-muted">Capaian:</dt> <dd class="m-0 inline font-semibold">Juara II</dd>')
        ->and($text)->not->toContain('Flyer dibuat')
        ->not->toContain('Salin data')
        ->not->toContain('poin')
        ->not->toContain('68')
        ->not->toContain($publication->achievement->student->nis)
        ->not->toContain($publication->achievement->student->nisn);
});

it('lists every team member with class in the data for the flyer', function (): void {
    $publication = HumasFixtures::publication();
    HumasFixtures::teammate($publication->achievement, 'Raka Pradipta', 'XII-2');
    HumasFixtures::teammate($publication->achievement, 'Salsabila Kirana', 'X-3', ['photo_consent' => false]);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSeeInOrder(['Anggota tim:', 'Nadia Putri Anindya (XI-4)', 'Raka Pradipta (XII-2)', 'Salsabila Kirana (X-3)', 'Capaian:', 'Juara II'])
        ->assertDontSee('Nama:')
        ->assertDontSee('Kelas:')
        ->assertSee('Tidak mengizinkan fotonya dipakai: Salsabila Kirana.')
        ->assertDontSee('tidak mengizinkan namanya dipublikasikan');
});

it('never names a team member without publish consent in the data for the flyer', function (): void {
    $publication = HumasFixtures::publication();
    HumasFixtures::teammate($publication->achievement, 'Raka Pradipta', 'XII-2');
    HumasFixtures::teammate($publication->achievement, 'Fajar Ramadhan', 'XII-5', ['publish_consent' => false]);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSeeInOrder(['Anggota tim:', 'Nadia Putri Anindya (XI-4)', 'Raka Pradipta (XII-2)'])
        ->assertSee('1 anggota tim tidak mengizinkan namanya dipublikasikan, jadi tidak ditampilkan di sini.')
        ->assertDontSee('Fajar Ramadhan')
        ->assertDontSee('XII-5');
});

it('says that the student does not allow a photo, and offers none', function (): void {
    HumasFixtures::publication(['photo_consent' => false]);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Tanpa foto')
        ->assertSee('Siswa ini tidak mengizinkan fotonya dipakai.')
        ->assertDontSeeHtml('<img')
        ->assertDontSee('inisial');
});

it('closes the panel, data for the flyer included, once publish consent is withdrawn', function (): void {
    $publication = HumasFixtures::publication();
    $component = Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Data untuk flyer')
        ->assertSee('Nadia Putri Anindya');

    $publication->achievement->student->forceFill(['publish_consent' => false])->save();

    $component->call('tampilkanLebih')
        ->assertSet('selectedId', null)
        ->assertDontSee('Data untuk flyer')
        ->assertDontSee('Nadia Putri Anindya')
        ->assertDontSeeHtml('data-sth-copy');

    Livewire::withQueryParams(['pilih' => $publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertNotFound();
});

it('keeps the data for the flyer on a published publication', function (): void {
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDay(), 'channels' => ['instagram'], 'caption' => 'Selamat, Nadia!']);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->call('select', Publication::query()->sole()->getKey())
        ->assertSee('Sudah terbit')
        ->assertSee('Data untuk flyer')
        ->assertSeeHtml('data-sth-copy="#data-flyer"');
});

it('no longer offers a flyer mock-up, a template or a PNG, and has nothing left that could make one', function (): void {
    $publication = HumasFixtures::publication();

    $component = Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertDontSee('SELAMAT')
        ->assertDontSee('Template e-flyer')
        ->assertDontSee('Download PNG')
        ->assertDontSee('Biru lavender')
        ->assertDontSeeHtml('downloadFlyer')
        ->assertDontSeeHtml('wire:model.live="template"');

    expect(method_exists(Publikasi::class, 'downloadFlyer'))->toBeFalse()
        ->and(property_exists(Publikasi::class, 'template'))->toBeFalse()
        ->and(fn () => $component->call('downloadFlyer'))->toThrow(MethodNotFoundException::class)
        // The generator classes are gone (checked as files: a stale Composer classmap may still list the names).
        ->and(file_exists(app_path('Actions/Publications/GenerateFlyer.php')))->toBeFalse()
        ->and(file_exists(app_path('Actions/Publications/FlyerRenderer.php')))->toBeFalse();

    // No route makes or serves a new flyer either: the only file route is the policy-checked download.
    $flyerRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains(strtolower($route->uri().' '.$route->getName().' '.$route->getActionName()), 'flyer'));

    expect($flyerRoutes)->toBeEmpty()
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($publication->fresh()->flyer_file_id)->toBeNull();
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

it('schedules and publishes without creating any file', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', ['instagram'])
        ->set('date', now()->addDay()->format('Y-m-d'))
        ->set('time', '09:00')
        ->call('schedule')
        ->assertHasNoErrors()
        ->call('publish')
        ->assertNoFileDownloaded();

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->fresh()->flyer_file_id)->toBeNull()
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
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

// QA5 (2026-10-07): a published publication is not "prepared" any more ------------------------------------------

it('heads the panel "Detail publikasi" for a publication that is already out', function (): void {
    $ready = HumasFixtures::publication(studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    $out = HumasFixtures::publication(studentName: 'Salsabila Kirana', competitionName: 'FLS3N Provinsi 2026', publicationState: ['status' => PublicationStatus::Dipublikasikan, 'channels' => ['instagram'], 'published_at' => now()]);

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->call('select', $ready->getKey())
        ->assertSeeHtml('id="pub-judul" class="m-0 text-lg leading-6 font-bold">Siapkan publikasi')
        ->call('select', $out->getKey())
        ->assertSeeHtml('id="pub-judul" class="m-0 text-lg leading-6 font-bold">Detail publikasi')
        ->assertDontSeeHtml('font-bold">Siapkan publikasi');
});

// QA5 retest (2026-10-07, backlog #65): "Jadwalkan" with nothing filled in names every missing field at once ---

it('names every missing field of the schedule form at once', function (): void {
    HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('caption', '')
        ->set('channels', [])
        ->set('date', '')
        ->set('time', '')
        ->call('schedule')
        ->assertHasErrors(['date', 'time', 'caption', 'channels']);
});

it('drops the errors of fields that are now filled when the schedule is still refused', function (): void {
    HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('caption', '')
        ->set('channels', [])
        ->set('date', '')
        ->set('time', '')
        ->call('schedule')
        ->assertHasErrors(['date', 'time', 'caption', 'channels'])
        ->set('caption', 'Selamat!')
        ->set('channels', ['instagram'])
        ->set('date', now()->subDay()->format('Y-m-d'))
        ->set('time', '09:00')
        ->call('schedule')
        ->assertHasErrors(['date'])
        ->assertHasNoErrors(['caption', 'channels', 'time']);
});

// QA7: explore round after UI3 (2026-10-08) ---------------------------------------------------------

it('wraps a long lomba name on a published card instead of widening the lane (QA7)', function (): void {
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDay(), 'channels' => ['instagram'], 'caption' => 'Selamat, Nadia!']);

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->assertSeeHtml('card relative flex flex-col gap-1 [overflow-wrap:anywhere] border-l-4');
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('says the student was told only when the student can still log in (QA8)', function (): void {
    // An alumnus (archived) or a disabled account gets no notice (PublishPublication, RecordAppreciation), yet the
    // toast said "Siswa sudah diberi tahu" (D-109: never claim a notice nobody got).
    $publication = HumasFixtures::publication();
    $student = $publication->achievement->student->user;
    $student->forceFill(['status' => UserStatus::Archived])->save();
    $event = HumasFixtures::event();

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->set('eventId', $event->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Masuk daftar apresiasi.', tone: 'success')
        ->set('channels', ['instagram'])
        ->call('publish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Publikasi ditandai sudah terbit.', tone: 'success');

    Notification::assertNotSentTo($student, AchievementPublished::class);
    Notification::assertNotSentTo($student, AppreciationScheduled::class);
});
