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
use Illuminate\Support\Facades\File as Filesystem;
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

    // QA10: the old link gives a warning toast instead of a full-screen 404, and still shows nothing of the student.
    Livewire::withQueryParams(['pilih' => $publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertSet('selectedId', null)
        ->assertDontSee('Data untuk flyer')
        ->assertDontSee('Nadia Putri Anindya');
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

it('preselects ?pilih= and never selects one that is not visible', function (): void {
    HumasFixtures::publication();
    $second = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $hidden = HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan');

    Livewire::withQueryParams(['pilih' => $second->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $second->getKey());

    // QA10: a warning toast instead of a full-screen 404 (an old link); the hidden card is never selected.
    Livewire::withQueryParams(['pilih' => $hidden->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertNotSet('selectedId', $hidden->getKey())
        ->assertDontSee('Fajar Ramadhan');
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

it('publishes from the panel through the dialog and notifies the student', function (): void {
    $publication = HumasFixtures::publication();
    $student = $publication->achievement->student->user;

    // "Tandai dipublikasikan" only opens the dialog: nothing changes and nobody is told before the confirmation.
    $component = Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', ['instagram', 'mading'])
        ->call('askPublish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: Publikasi::PUBLISH_DIALOG)
        ->assertNotDispatched('sth-toast')
        ->assertSet('publishingId', $publication->getKey())
        ->assertSeeHtmlInOrder([
            'Tandai sudah terbit?',
            'Siswa langsung diberi tahu.',
            'OPSI 2026',
            'Nadia Putri Anindya · XI-4 · Juara II Nasional',
            'Kanal: Instagram, Mading sekolah',
            'Status menjadi Dipublikasikan dan tidak bisa dikembalikan ke antrean.',
        ])
        ->assertSeeHtml('wire:click="publish"');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and($publication->fresh()->channels)->toBe([]);
    Notification::assertNothingSent();

    $component->call('publish')
        ->assertDispatched('sth-modal-close', id: Publikasi::PUBLISH_DIALOG)
        ->assertDispatched('sth-toast', message: 'Publikasi ditandai sudah terbit. Siswa sudah diberi tahu.', tone: 'success')
        ->assertSet('publishingId', null)
        ->assertSee('Sudah terbit')
        ->assertDontSeeHtml('wire:click="publish"');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->fresh()->channels)->toBe(['instagram', 'mading']);
    Notification::assertSentToTimes($student, AchievementPublished::class, 1);
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
        ->call('askPublish')
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
        // The hint under the date promises no notice either.
        ->assertSee('Status baru menjadi Dipublikasikan setelah tombol Tandai dipublikasikan ditekan.')
        ->assertDontSee('siswa baru diberi tahu')
        ->set('eventId', $event->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Masuk daftar apresiasi.', tone: 'success')
        ->set('channels', ['instagram'])
        ->call('askPublish')
        // The dialog is honest too: nobody is told, so it does not say "Siswa langsung diberi tahu."
        ->assertSee('Akun siswa tidak aktif, jadi tidak ada notifikasi.')
        ->assertDontSee('Siswa langsung diberi tahu.')
        ->call('publish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Publikasi ditandai sudah terbit.', tone: 'success');

    Notification::assertNotSentTo($student, AchievementPublished::class);
    Notification::assertNotSentTo($student, AppreciationScheduled::class);
});

// EM2 (lead, 2026-10-10; D-122): the schedule is only a reminder, "Tandai dipublikasikan" asks first -----------

/** A scheduled card of a consenting student; $at in the past = "lewat jadwal". */
function em2Card(DateTimeInterface $at, string $name = 'Nadia Putri Anindya', string $lomba = 'OPSI 2026'): Publication
{
    return HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => $at,
        'channels' => ['instagram', 'website'],
        'caption' => 'Selamat untuk '.$name.'!',
    ], studentName: $name, competitionName: $lomba);
}

it('marks a scheduled card whose time has passed as "Lewat jadwal" and leads with "Tandai dipublikasikan"', function (): void {
    $at = CarbonImmutable::now()->subDay()->setTime(21, 50);
    $overdue = em2Card($at);

    $component = Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $overdue->getKey())
        // Still in the lane "Terjadwal": nothing was published by the scheduler.
        ->assertSeeInOrder(['Terjadwal', 'OPSI 2026', 'Lewat jadwal', 'Dipublikasikan', 'Belum ada yang terbit.'])
        ->assertSee('Lewat jadwal '.$at->locale('id')->translatedFormat('j M, H.i'))
        ->assertDontSee('Terjadwal '.$at->locale('id')->translatedFormat('j M, H.i'))
        ->assertSee('Jadwal sudah lewat')
        ->assertSee('Sudah di-upload ke kanal? Tekan Tandai dipublikasikan supaya siswa diberi tahu. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang.')
        // Both ways out stay, the confirmation first.
        ->assertSeeHtmlInOrder(['wire:click="askPublish"', 'Tandai dipublikasikan', 'Jadwalkan ulang'])
        ->assertSeeHtml('wire:key="pub-tandai-utama"')
        ->assertDontSeeHtml('wire:key="pub-tandai"')
        ->assertDontSee('Sudah terbit');

    // The form offers a time that "Jadwalkan ulang" accepts, not the past one.
    $slot = CarbonImmutable::createFromFormat('Y-m-d H:i', $component->get('date').' '.$component->get('time'), (string) config('app.timezone'));
    expect($slot->isFuture())->toBeTrue()
        ->and($slot->format('H:i'))->toBe('09:00');

    $component->call('schedule')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Publikasi dijadwalkan.', tone: 'success')
        ->assertDontSee('Lewat jadwal')
        ->assertDontSee('Jadwal sudah lewat')
        ->assertSeeHtml('wire:key="pub-tandai"');

    expect($overdue->fresh()->scheduled_at->equalTo($slot))->toBeTrue()
        ->and($overdue->fresh()->status)->toBe(PublicationStatus::Terjadwal);
});

it('promises no student notice on an overdue card of an alumnus: alert, hint and dialog agree (D-109)', function (): void {
    // An archived account is still in the queue (consent given) but is not told on "Tandai dipublikasikan".
    $overdue = em2Card(CarbonImmutable::now()->subDay()->setTime(21, 50));
    $overdue->achievement->student->user->forceFill(['status' => UserStatus::Archived])->save();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $overdue->getKey())
        ->assertSee('Jadwal sudah lewat')
        ->assertSee('Sudah di-upload ke kanal? Tekan Tandai dipublikasikan. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang.')
        ->assertSee('Jadwal ini hanya pengingat. Pada waktu ini Humas mendapat pengingat untuk meng-upload ke kanal yang dipilih. Status baru menjadi Dipublikasikan setelah tombol Tandai dipublikasikan ditekan.')
        ->assertDontSee('supaya siswa diberi tahu')
        ->assertDontSee('siswa baru diberi tahu')
        ->call('askPublish')
        ->assertDispatched('sth-modal-open', id: Publikasi::PUBLISH_DIALOG)
        ->assertSee(Publikasi::NOTICE_NOBODY)
        ->assertDontSee(Publikasi::NOTICE_TOLD);
});

it('shows an upcoming scheduled card without any overdue mark, the schedule button first', function (): void {
    $at = CarbonImmutable::now()->addDays(2)->setTime(9, 30);
    $upcoming = em2Card($at);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSet('selectedId', $upcoming->getKey())
        ->assertSet('date', $at->format('Y-m-d'))
        ->assertSet('time', '09:30')
        ->assertSee('Terjadwal '.$at->locale('id')->translatedFormat('j M, H.i'))
        ->assertDontSee('Lewat jadwal')
        ->assertDontSee('Jadwal sudah lewat')
        ->assertSeeHtmlInOrder(['Jadwalkan ulang', 'wire:click="askPublish"', 'Tandai dipublikasikan'])
        ->assertSeeHtml('wire:key="pub-tandai"')
        ->assertDontSeeHtml('wire:key="pub-tandai-utama"');
});

it('says under the date that the schedule is only a reminder', function (): void {
    HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Jadwal ini hanya pengingat. Pada waktu ini Humas mendapat pengingat untuk meng-upload ke kanal yang dipilih. Status baru menjadi Dipublikasikan, dan siswa baru diberi tahu, setelah tombol Tandai dipublikasikan ditekan.')
        ->assertDontSee('berubah otomatis');

    $promising = collect(Filesystem::allFiles(resource_path('views/livewire/humas')))
        ->filter(fn ($view): bool => str_contains($view->getContents(), 'berubah otomatis menjadi Dipublikasikan'))
        ->map(fn ($view): string => $view->getRelativePathname())
        ->values()
        ->all();

    expect($promising)->toBe([]);
});

it('publishes an overdue card from the panel, tells the student then and lowers the Jadwal count', function (): void {
    $overdue = em2Card(now()->subHours(3));
    em2Card(now()->subDay(), 'Raka Pradipta', 'FIKSI 2026');
    $student = $overdue->achievement->student->user;
    $pressed = CarbonImmutable::now()->startOfSecond();
    $this->travelTo($pressed);

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertDispatched('sth-badges', badges: ['humas.publikasi' => 0, 'humas.jadwal' => 2, 'kesiswaan.verifikasi' => 0])
        ->call('select', $overdue->getKey())
        ->call('askPublish')
        ->assertDispatched('sth-modal-open', id: Publikasi::PUBLISH_DIALOG)
        ->assertDispatched('sth-badges', badges: ['humas.publikasi' => 0, 'humas.jadwal' => 2, 'kesiswaan.verifikasi' => 0])
        ->call('publish')
        ->assertDispatched('sth-toast', message: 'Publikasi ditandai sudah terbit. Siswa sudah diberi tahu.', tone: 'success')
        ->assertDispatched('sth-badges', badges: ['humas.publikasi' => 0, 'humas.jadwal' => 1, 'kesiswaan.verifikasi' => 0])
        ->assertSee('Sudah terbit');

    $fresh = $overdue->fresh();
    expect($fresh->status)->toBe(PublicationStatus::Dipublikasikan)
        // The moment Humas confirmed it, not the scheduled time.
        ->and($fresh->published_at->equalTo($pressed))->toBeTrue()
        ->and($fresh->published_at->equalTo($fresh->scheduled_at))->toBeFalse();
    Notification::assertSentToTimes($student, AchievementPublished::class, 1);
});

it('does nothing when the confirm step is called without the question', function (): void {
    $publication = HumasFixtures::publication();
    $student = $publication->achievement->student->user;

    // A forged call, or the second click of a double click: no error page, no change, no notice.
    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', ['instagram'])
        ->call('publish')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('selectedId', $publication->getKey())
        ->assertSee('Siapkan publikasi')
        ->assertDontSee('Sudah terbit');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and($publication->fresh()->published_at)->toBeNull();
    Notification::assertNothingSent();

    // After one real confirmation the question is used up: a second "publish" changes nothing more.
    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('channels', ['instagram'])
        ->call('askPublish')
        ->call('publish')
        ->assertDispatched('sth-toast', tone: 'success')
        ->call('publish')
        ->assertOk()
        ->assertNotDispatched('sth-toast');

    Notification::assertSentToTimes($student, AchievementPublished::class, 1);
});

it('never publishes a card other than the one the dialog asked about', function (): void {
    $first = HumasFixtures::publication(studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    $second = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->call('select', $first->getKey())
        ->call('askPublish')
        ->assertSet('publishingId', $first->getKey())
        // The page behind an open dialog is inert; a forged select makes the question void.
        ->call('select', $second->getKey())
        ->assertSet('publishingId', null)
        ->call('publish')
        ->assertOk()
        ->assertNotDispatched('sth-toast');

    expect($first->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and($second->fresh()->status)->toBe(PublicationStatus::Siap);
    Notification::assertNothingSent();

    // The id the dialog asks about cannot be set from the browser.
    expect(fn () => Livewire::actingAs($this->humas)->test(Publikasi::class)->set('publishingId', $second->getKey()))
        ->toThrow(Exception::class);
});

it('shows the field error instead of the dialog when caption or channels are not valid', function (array $fields, string $error, string $message): void {
    $publication = HumasFixtures::publication();

    $component = Livewire::actingAs($this->humas)->test(Publikasi::class);

    foreach ($fields as $name => $value) {
        $component->set($name, $value);
    }

    $component->call('askPublish')
        ->assertHasErrors([$error])
        ->assertSee($message)
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('publishingId', null)
        // A question that never opened cannot be confirmed.
        ->call('publish')
        ->assertNotDispatched('sth-toast');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
    Notification::assertNothingSent();
})->with([
    'no channel' => [['channels' => []], 'channels', 'Pilih minimal satu kanal.'],
    'unknown channel' => [['channels' => ['tiktok']], 'channels', 'Kanal tidak dikenal.'],
    'caption too long' => [['caption' => str_repeat('a', 2001)], 'caption', 'Caption maksimal 2.000 karakter.'],
]);

it('accepts an empty caption for "Tandai dipublikasikan", as the Action does', function (): void {
    $publication = HumasFixtures::publication();

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->set('caption', '')
        ->set('channels', ['website'])
        ->call('askPublish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: Publikasi::PUBLISH_DIALOG)
        ->call('publish')
        ->assertDispatched('sth-toast', tone: 'success');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->fresh()->caption)->toBeNull();
});

it('uses the dialog of the app, never the confirm box of the browser, on every Humas view', function (): void {
    $views = collect(Filesystem::allFiles(resource_path('views/livewire/humas')));
    $native = $views
        ->filter(fn ($view): bool => str_contains($view->getContents(), 'wire:confirm'))
        ->map(fn ($view): string => $view->getRelativePathname())
        ->values()
        ->all();

    expect($views)->not->toBeEmpty()
        ->and($native)->toBe([]);

    // The panel button opens the dialog; only the dialog confirms.
    expect((string) file_get_contents(resource_path('views/livewire/humas/partials/publication-panel.blade.php')))
        ->toContain('wire:click="askPublish"')
        ->not->toContain('wire:click="publish"')
        ->and((string) file_get_contents(resource_path('views/livewire/humas/publikasi.blade.php')))
        ->toContain('wire:click="publish"');
});
