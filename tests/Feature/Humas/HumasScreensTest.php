<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Arsip;
use App\Livewire\Humas\HumasQueries;
use App\Livewire\Humas\Jadwal;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\AppreciationReleased;
use App\Notifications\Publications\AppreciationScheduled;
use App\Providers\NavigationServiceProvider;
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
        // D-122: the schedule only reminded Humas; the box says what is still to do.
        ->assertSeeInOrder([
            'Lewat jadwal, belum ditandai terbit',
            'Siswa belum diberi tahu. Setelah publikasi di-upload ke kanal, buka kartunya lalu tekan Tandai dipublikasikan.',
            'FLS3N Provinsi 2026',
            'OPSI 2026',
        ])
        ->assertSee('FLS3N Provinsi 2026')
        ->assertDontSee('Fajar Ramadhan')
        ->assertSee(route('humas.beranda', ['pilih' => $scheduled->getKey()]), false)
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertSee('1 prestasi');
});

it('counts a publication as overdue from its scheduled minute on, and no longer once Humas marked it (D-122)', function (): void {
    $this->travelTo(now()->startOfMinute());
    $due = HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now(),
        'channels' => ['instagram'],
    ], studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now()->addMinute(),
        'channels' => ['website'],
    ], studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    // The row that is due this very minute is in the box (and in the count of the menu), the next one is not.
    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSeeInOrder(['Lewat jadwal, belum ditandai terbit', 'OPSI 2026', 'FIKSI 2026'])
        ->assertViewHas('overdue', fn (array $overdue): bool => array_column($overdue, 'id') === [$due->getKey()])
        ->assertViewHas('days', fn (array $days): bool => count($days) === 1 && count($days[0]['items']) === 1);

    expect(HumasQueries::badges($this->humas)['humas.jadwal'])->toBe(1);

    app(PublishPublication::class)->handle($this->humas, $due->fresh());

    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertDontSee('Lewat jadwal, belum ditandai terbit')
        ->assertDontSee('OPSI 2026')
        ->assertSee('FIKSI 2026');

    expect(HumasQueries::badges($this->humas)['humas.jadwal'])->toBe(0);
});

it('shows the number of overdue publications on the menu item Jadwal, on every Humas page (D-122)', function (): void {
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();

    foreach (['Nadia Putri Anindya' => now()->subDay(), 'Raka Pradipta' => now()->subMinutes(5), 'Salsabila Kirana' => now()->addDay()] as $name => $at) {
        HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Terjadwal, 'scheduled_at' => $at, 'channels' => ['instagram']], studentName: $name);
    }
    // Not counted: no publish consent (never named to Humas), still "siap", already published.
    HumasFixtures::publication(['publish_consent' => false], ['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->subDay()], studentName: 'Fajar Ramadhan');
    HumasFixtures::publication(studentName: 'Dimas Arya Nugroho');
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'scheduled_at' => now()->subDays(3), 'published_at' => now()->subDays(2), 'channels' => ['website']], studentName: 'Keisha Larasati');

    $badge = function (string $html, string $key): ?int {
        if (preg_match('/<span class="badge num" data-sth-badge="'.preg_quote($key, '/').'"([^>]*)>\s*(\d+)\s*<\/span>/', $html, $m) !== 1) {
            return null;
        }

        return str_contains($m[1], 'hidden') ? null : (int) $m[2];
    };

    expect(HumasQueries::overduePublications()->count())->toBe(2)
        ->and(HumasQueries::badges($this->humas))->toBe(['humas.publikasi' => 1, 'humas.jadwal' => 2]);

    // A page that passes no badges (Notifikasi) and the screens that pass their own show the same number.
    foreach (['notifikasi.index', 'humas.jadwal', 'humas.beranda', 'humas.arsip'] as $route) {
        $html = $this->actingAs($this->humas)->get(route($route))->assertOk()->getContent();

        expect($badge($html, 'humas.jadwal'))->toBe(2, $route)
            ->and($badge($html, 'humas.publikasi'))->toBe(1, $route);
    }

    // Only for an account that may manage publications.
    expect(HumasQueries::badges(AuthTestHelpers::staff(RoleName::Guru)))->not->toHaveKey('humas.jadwal')
        ->and(HumasQueries::badges(AuthTestHelpers::staff(RoleName::Kesiswaan)))->not->toHaveKey('humas.jadwal')
        ->and(NavigationServiceProvider::badges(AuthTestHelpers::staff(RoleName::TataUsaha), 'humas'))->toBe([]);
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
        ->set('heldTime', '07:00')
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
        ->set('heldTime', '07:00')
        ->call('createEvent')
        ->assertHasErrors(['name'])
        ->assertHasNoErrors(['heldTime']);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', 'Upacara')
        ->set('heldOn', now()->subDay()->toDateString())
        ->set('heldTime', '07:00')
        ->call('createEvent')
        ->assertHasErrors(['heldOn'])
        ->assertHasNoErrors(['name', 'heldTime']);

    expect(AppreciationEvent::query()->count())->toBe(0);
});

it('adds a verified achievement to an event and confirms a held event through the dialog', function (): void {
    $achievement = HumasFixtures::achievement();
    HumasFixtures::achievement(['publish_consent' => false], studentName: 'Fajar Ramadhan');
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Hari ini')
        ->assertSee('Belum bisa ditandai sudah dilaksanakan: belum ada prestasi di acara ini.')
        ->assertDontSeeHtml("confirmComplete('".$event->getKey()."')")
        ->call('startAdding', $event->getKey())
        ->assertSet('addingEventId', $event->getKey())
        // The picker (D-124) lists the one free achievement of a consenting student.
        ->assertViewHas('picker', fn (array $picker): bool => array_column($picker['rows'], 'id') === [$achievement->getKey()]
            && $picker['rows'][0]['student'] === 'Nadia Putri Anindya' && $picker['rows'][0]['class'] === 'XI-4')
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('Fajar Ramadhan')
        ->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Pilih minimal satu prestasi.')
        ->set('picked', [$achievement->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertSet('addingEventId', null)
        ->assertSet('picked', [])
        ->assertDispatched('sth-toast', message: 'Prestasi masuk daftar apresiasi. Siswa sudah diberi tahu.', tone: 'success')
        ->assertSee('Dijadwalkan')
        ->assertSeeHtml("confirmComplete('".$event->getKey()."')");

    Notification::assertSentTo($achievement->student->user, AppreciationScheduled::class);

    // The button only opens the dialog; nothing changes before the confirmation.
    $component->call('confirmComplete', $event->getKey())
        ->assertSet('confirmEventId', $event->getKey())
        ->assertDispatched('sth-modal-open', id: 'apresiasi-konfirmasi')
        ->assertSee('Tandai sudah dilaksanakan?')
        ->assertSee('1 prestasi ditandai sudah diapresiasi.');

    expect(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Dijadwalkan)
        ->and($event->fresh()->isCompleted())->toBeFalse();

    $component->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Acara ditandai sudah dilaksanakan. 1 prestasi sudah diapresiasi.', tone: 'success')
        ->assertSet('confirmEventId', null)
        ->assertSee('Belum ada acara apresiasi')
        ->assertSeeInOrder(['Sudah dilaksanakan', 'Upacara hari ini', 'Dikonfirmasi', 'Sudah diapresiasi'])
        ->assertDontSee('Tambah prestasi')
        ->assertDontSee('Hapus acara');

    expect(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai)
        ->and($event->fresh()->isCompleted())->toBeTrue();
});

it('deletes an event through the dialog and tells the scheduled students', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event();
    $trial = HumasFixtures::event('Acara uji coba', now()->addDays(9)->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Acara uji coba')
        ->assertSee('Hapus acara')
        ->call('confirmDelete', $trial->getKey())
        ->assertSet('confirmEventId', $trial->getKey())
        ->assertDispatched('sth-modal-open', id: 'apresiasi-konfirmasi')
        ->assertSee('Hapus acara ini?')
        ->assertSee('tidak ada siswa yang perlu diberi tahu');

    expect(AppreciationEvent::query()->count())->toBe(2);

    $component->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Acara dihapus.', tone: 'success')
        ->assertDontSee('Acara uji coba')
        ->assertSee('Upacara Senin, 5 Oktober 2026');

    Notification::assertNotSentTo($achievement->student->user, AppreciationCancelled::class);

    $component->call('confirmDelete', $event->getKey())
        ->assertSee('Siswanya diberi tahu bahwa acara dibatalkan.')
        ->call('runConfirmed')
        ->assertDispatched('sth-toast', message: 'Acara dihapus. 1 prestasi dilepas dari acara ini dan siswanya diberi tahu.', tone: 'success')
        ->assertSee('Belum ada acara apresiasi');

    expect(AppreciationEvent::query()->count())->toBe(0)
        ->and(Appreciation::query()->count())->toBe(0);
    Notification::assertSentTo($achievement->student->user, AppreciationCancelled::class);
});

it('shows the reason instead of a dead confirm button and refuses a forged confirmation', function (): void {
    $future = HumasFixtures::event('Upacara depan', now()->addDays(4)->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $future, HumasFixtures::achievement());

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Akan datang')
        ->assertSee('Bisa ditandai sudah dilaksanakan mulai '.now()->addDays(4)->locale('id')->translatedFormat('l, j F Y').'.')
        ->assertDontSeeHtml("confirmComplete('".$future->getKey()."')")
        ->assertSeeHtml("confirmDelete('".$future->getKey()."')")
        ->call('confirmComplete', $future->getKey())
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Acara ini belum berlangsung.', tone: 'error')
        ->assertSet('confirmEventId', null);

    expect($future->fresh()->isCompleted())->toBeFalse()
        ->and(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Dijadwalkan);
});

it('answers with the reason when the event changed while the dialog was open', function (): void {
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());

    $complete = Livewire::actingAs($this->humas)->test(Apresiasi::class)->call('confirmComplete', $event->getKey());
    $delete = Livewire::actingAs($this->humas)->test(Apresiasi::class)->call('confirmDelete', $event->getKey());

    // A colleague confirms the event in the meantime.
    app(CompleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $event);

    $complete->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Acara ini sudah ditandai dilaksanakan.', tone: 'error')
        ->assertSet('confirmEventId', null);
    $delete->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Acara yang sudah dilaksanakan tidak bisa dihapus.', tone: 'error')
        ->assertSet('confirmEventId', null);

    expect(AppreciationEvent::query()->completed()->count())->toBe(1)
        ->and(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai);
    Notification::assertNotSentTo(Appreciation::query()->sole()->achievement->student->user, AppreciationCancelled::class);
});

it('lists past unconfirmed events first and keeps confirmed events as a record', function (): void {
    $overdue = HumasFixtures::event('Upacara lalu', now()->subDays(3)->toDateString());
    $row = new Appreciation(['achievement_id' => HumasFixtures::achievement()->getKey(), 'appreciation_event_id' => $overdue->getKey()]);
    $row->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();
    $emptyPast = HumasFixtures::event('Acara kosong', now()->subDays(5)->toDateString());
    $done = HumasFixtures::event('Upacara bulan lalu', now()->subDays(30)->toDateString());
    $done->forceFill(['completed_at' => now()->subDays(29), 'completed_by' => $this->humas->getKey()])->save();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSeeInOrder(['Perlu dikonfirmasi', 'Acara kosong', 'Upacara lalu', 'Acara mendatang', 'Belum ada acara apresiasi', 'Upacara bulan lalu'])
        ->assertSee('Belum dikonfirmasi')
        ->assertSee('Acara ini kosong, jadi tidak bisa ditandai sudah dilaksanakan. Hapus kalau tidak jadi.')
        ->assertSee('Dikonfirmasi '.now()->subDays(29)->locale('id')->translatedFormat('j M Y').' oleh '.$this->humas->name)
        ->assertSeeHtml("confirmComplete('".$overdue->getKey()."')")
        ->assertSeeHtml("confirmDelete('".$overdue->getKey()."')")
        ->assertDontSeeHtml("confirmComplete('".$emptyPast->getKey()."')")
        ->assertSeeHtml("confirmDelete('".$emptyPast->getKey()."')")
        ->assertDontSeeHtml("startAdding('".$overdue->getKey()."')")
        ->assertDontSeeHtml("confirmDelete('".$done->getKey()."')")
        // A stale list: the event was confirmed meanwhile, "Hapus acara" answers with the reason.
        ->call('confirmDelete', $done->getKey())
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Acara yang sudah dilaksanakan tidak bisa dihapus.', tone: 'error')
        ->assertSet('confirmEventId', null);

    expect(AppreciationEvent::query()->count())->toBe(3);
});

it('shows confirmed events in steps of 10', function (): void {
    for ($i = 1; $i <= 11; $i++) {
        HumasFixtures::event(sprintf('Upacara ke-%02d', $i), now()->subDays($i)->toDateString())
            ->forceFill(['completed_at' => now()->subDays($i)])
            ->save();
    }

    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('doneLimit', 500))->toThrow(Exception::class);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('11 acara, terbaru di atas.')
        ->assertSee('Upacara ke-10')
        ->assertDontSee('Upacara ke-11')
        ->assertSee('Tampilkan lebih banyak')
        ->call('showMoreDone')
        ->assertSee('Upacara ke-11')
        ->assertDontSee('Tampilkan lebih banyak');
});

it('keeps the event ids of the screen locked and 404s unknown events', function (): void {
    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('addingEventId', 'x'))
        ->toThrow(Exception::class);
    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('confirmEventId', 'x'))
        ->toThrow(Exception::class);
    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('confirmAction', 'hapus'))
        ->toThrow(Exception::class);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('confirmDelete', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('confirmComplete', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
    // No dialog was opened: there is nothing to confirm.
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('runConfirmed')
        ->assertNotFound();

    expect(AppreciationEvent::query()->count())->toBe(0);
});

// --- M2-B: one achievement, one open event; "Lepas dari acara" (QA 2026-10-05, Q2-10) ------

it('offers only achievements that are on no event in "Tambah prestasi" and refuses a forged second event', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $first = HumasFixtures::event();
    $second = HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $first, $nadia);
    Notification::fake();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $second->getKey())
        ->assertViewHas('picker', fn (array $picker): bool => array_column($picker['rows'], 'student') === ['Raka Pradipta'])
        ->assertSee('belum masuk acara mana pun')
        // The browser names it anyway: it is not among the free achievements, so nothing is written (D-124:
        // the picker loads every ticked id again through the eligibility query).
        ->set('picked', [$nadia->getKey()])
        ->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Sebagian prestasi yang dipilih sudah tidak bisa dimasukkan. Daftar sudah diperbarui, pilih lagi.')
        ->assertSet('picked', [])
        ->set('picked', [$raka->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        // Nothing is left to add: the hint says why.
        ->call('startAdding', $second->getKey())
        ->assertSee('Tidak ada prestasi lain yang bisa dimasukkan.')
        ->assertSee('perlu dilepas dulu dari acara itu');

    expect(Appreciation::query()->where('achievement_id', $nadia->getKey())->count())->toBe(1)
        ->and(Appreciation::query()->count())->toBe(2);
    Notification::assertNotSentTo($nadia->student->user, AppreciationScheduled::class);
});

it('releases one achievement from an event through the dialog and tells the student', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $event = HumasFixtures::event();
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $nadia);
    $kept = app(RecordAppreciation::class)->handle($this->humas, $event, $raka);
    Notification::fake();

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Lepas dari acara')
        ->assertSeeHtml("confirmRelease('".$row->getKey()."')")
        ->assertSeeHtml("confirmRelease('".$kept->getKey()."')")
        ->call('confirmRelease', $row->getKey())
        ->assertDispatched('sth-modal-open', id: 'apresiasi-konfirmasi')
        ->assertSet('confirmRowId', $row->getKey())
        ->assertSet('confirmEventId', $event->getKey())
        ->assertSee('Lepas dari acara?')
        ->assertSee('Nadia Putri Anindya (XI-4)')
        ->assertSee('Acaranya tetap ada. Siswanya diberi tahu.');

    // The button only opened the dialog.
    expect(Appreciation::query()->count())->toBe(2);
    Notification::assertNothingSent();

    $component->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Prestasi dilepas dari acara ini. Siswanya diberi tahu.', tone: 'success')
        ->assertSet('confirmRowId', null)
        ->assertSet('confirmEventId', null)
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertDontSeeHtml("confirmRelease('".$row->getKey()."')")
        ->assertSeeHtml("confirmRelease('".$kept->getKey()."')")
        // Free again: it is offered for an event.
        ->call('startAdding', $event->getKey())
        ->assertViewHas('picker', fn (array $picker): bool => array_column($picker['rows'], 'id') === [$nadia->getKey()]);

    expect(Appreciation::query()->sole()->getKey())->toBe($kept->getKey())
        ->and(AppreciationEvent::query()->count())->toBe(1);
    Notification::assertSentTo($nadia->student->user, AppreciationReleased::class);
    Notification::assertNotSentTo($raka->student->user, AppreciationReleased::class);
});

it('offers no "Lepas dari acara" on a confirmed event and answers a stale or forged release with the reason', function (): void {
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $stale = Livewire::actingAs($this->humas)->test(Apresiasi::class)->call('confirmRelease', $row->getKey());

    // A colleague confirms the event while the dialog is open.
    app(CompleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $event);

    $stale->call('runConfirmed')
        ->assertDispatched('sth-modal-close', id: 'apresiasi-konfirmasi')
        ->assertDispatched('sth-toast', message: 'Prestasi di acara yang sudah dilaksanakan tidak bisa dilepas.', tone: 'error')
        ->assertSet('confirmRowId', null);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Sudah diapresiasi')
        ->assertDontSee('Lepas dari acara')
        ->assertDontSeeHtml("confirmRelease('".$row->getKey()."')")
        ->call('confirmRelease', $row->getKey())
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Prestasi di acara yang sudah dilaksanakan tidak bisa dilepas.', tone: 'error')
        ->assertSet('confirmRowId', null)
        // A row that no longer exists.
        ->call('confirmRelease', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Prestasi ini sudah tidak ada di acara tersebut.', tone: 'error');

    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('confirmRowId', $row->getKey()))->toThrow(Exception::class);
    expect(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai);
});

it('lets Kesiswaan release a row and keeps the release away from other roles', function (): void {
    $event = HumasFixtures::event();
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());

    foreach ([RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah] as $role) {
        Livewire::actingAs(AuthTestHelpers::staff($role))->test(Apresiasi::class)->assertForbidden();
    }

    expect(Appreciation::query()->count())->toBe(1);

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))
        ->test(Apresiasi::class)
        ->assertSeeHtml("confirmRelease('".$row->getKey()."')")
        ->call('confirmRelease', $row->getKey())
        ->call('runConfirmed')
        ->assertDispatched('sth-toast', message: 'Prestasi dilepas dari acara ini. Siswanya diberi tahu.', tone: 'success');

    expect(Appreciation::query()->count())->toBe(0)
        ->and(AppreciationEvent::query()->count())->toBe(1);
});

it('works for Kesiswaan (appreciation.manage) and not for other roles', function (): void {
    $event = HumasFixtures::event();

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))
        ->test(Apresiasi::class)
        ->assertOk()
        ->assertSee('Upacara Senin, 5 Oktober 2026')
        ->assertSee('Buat acara apresiasi')
        ->assertSeeHtml("confirmDelete('".$event->getKey()."')")
        ->call('confirmDelete', $event->getKey())
        ->call('runConfirmed')
        ->assertDispatched('sth-toast', message: 'Acara dihapus.', tone: 'success');

    expect(AppreciationEvent::query()->count())->toBe(0);

    HumasFixtures::event();

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::Guru))->test(Apresiasi::class)->assertForbidden();
    Livewire::actingAs(AuthTestHelpers::staff(RoleName::TataUsaha))->test(Apresiasi::class)->assertForbidden();
    Livewire::actingAs(AuthTestHelpers::staff(RoleName::KepalaSekolah))->test(Apresiasi::class)->assertForbidden();

    expect(AppreciationEvent::query()->count())->toBe(1);
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

// QA5 (2026-10-07): an alumnus is offered as "Alumni", never with the last class (same as the ranking, D-80) --

it('offers the achievement of an alumnus as "Alumni" instead of the last class', function (): void {
    $achievement = HumasFixtures::achievement(studentName: 'Putri Maharani Kusuma');
    $achievement->student->user->forceFill(['status' => 'archived'])->save();
    $event = HumasFixtures::event();

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('startAdding', $event->getKey())
        ->assertViewHas('picker', fn (array $picker): bool => $picker['rows'] === [[
            'id' => $achievement->getKey(),
            'student' => 'Putri Maharani Kusuma',
            'class' => 'Alumni',
            'headline' => 'Juara II Nasional',
            'competition' => 'OPSI 2026',
        ]])
        ->assertDontSee('XI-4');
});

// QA5 retest (2026-10-07, backlog #65): an empty "Buat acara" form names both missing fields ------------------

it('names a missing event name and a missing date together', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', '')
        ->set('heldOn', '')
        ->call('createEvent')
        ->assertHasErrors(['name', 'heldOn']);

    expect(AppreciationEvent::query()->count())->toBe(0);
});

it('drops the name error once the name is filled while the date is still missing', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->set('name', '')
        ->set('heldOn', '')
        ->call('createEvent')
        ->assertHasErrors(['name', 'heldOn'])
        ->set('name', 'Upacara Senin, 12 Oktober 2026')
        ->call('createEvent')
        ->assertHasErrors(['heldOn'])
        ->assertHasNoErrors(['name']);
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('says the students were told only when one of them can still log in (QA8)', function (): void {
    $achievement = HumasFixtures::achievement();
    $student = $achievement->student->user;
    $student->forceFill(['status' => UserStatus::Archived])->save();
    $event = HumasFixtures::event();

    $component = Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('startAdding', $event->getKey())
        ->set('picked', [$achievement->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Prestasi masuk daftar apresiasi.', tone: 'success');

    $component->call('confirmDelete', $event->getKey())
        ->call('runConfirmed')
        ->assertDispatched('sth-toast', message: 'Acara dihapus. 1 prestasi dilepas dari acara ini.', tone: 'success');

    Notification::assertNotSentTo($student, AppreciationScheduled::class);
    Notification::assertNotSentTo($student, AppreciationCancelled::class);
});
