<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\Apresiasi;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| D-124 (lead, 2026-10-10; hand test of 2026-10-09, finding 6-1: "bagian pilih prestasi terverifikasi tambahi search
| bar jadi ga nyari scroll manual"): "Tambah prestasi" on Apresiasi is a picker: a search box (student or lomba
| name), a list with a tick per achievement that comes in steps, one "Masukkan" for everything ticked. Who may be
| picked did not change: verified, may be named (NamedStudent; no consent since D-162), on no event (D-97). The ticks come from the browser and are
| never trusted. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
    $this->event = HumasFixtures::event('Upacara Senin', now()->addDays(4)->toDateString(), '07:00');
});

/** Student names of the picker rows, in the order shown. */
function em2Names(array $picker, string $list = 'rows'): array
{
    return array_column($picker[$list], 'student');
}

/** A free achievement whose place in the list is fixed: a smaller $age is listed earlier. */
function em2Free(string $studentName, string $competitionName = 'OPSI 2026', int $age = 0): Achievement
{
    $achievement = HumasFixtures::achievement(studentName: $studentName, competitionName: $competitionName);
    $achievement->forceFill(['verified_at' => now()->subMinutes($age)])->save();

    return $achievement->fresh();
}

// --- The list and its search ---------------------------------------------------------------------------------------

it('replaces the long select with a search box and a list of ticks', function (): void {
    $nadia = em2Free('Nadia Putri Anindya');

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        // Closed: no picker is built, and none of its controls is on the page.
        ->assertViewHas('picker', fn (?array $p): bool => $p === null)
        ->assertDontSeeHtml('id="cari-prestasi"')
        ->call('startAdding', $this->event->getKey())
        ->assertSeeHtml('id="cari-prestasi"')
        ->assertSee('Cari prestasi')
        ->assertSeeHtml('placeholder="Nama siswa atau nama lomba"')
        ->assertSee('Hanya prestasi yang belum masuk acara mana pun. Siswa dengan akun nonaktif tidak ditampilkan.')
        ->assertSee('1 prestasi bisa dimasukkan.')
        ->assertSeeHtml('value="'.$nadia->getKey().'" wire:model="picked"')
        ->assertSeeHtml('wire:click="addAchievements"')
        // The search box must not sit in a form: Enter would submit it.
        ->assertDontSeeHtml('wire:submit="addAchievement')
        ->assertDontSee('Pilih prestasi terverifikasi');
});

it('finds an achievement by student name or by lomba name, whatever the case', function (): void {
    em2Free('Nadia Putri Anindya', 'OPSI 2026', 1);
    em2Free('Raka Pradipta', 'FIKSI 2026', 2);
    em2Free('Keisha Larasati', 'Olimpiade Sains Kota 2026', 3);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Nadia Putri Anindya', 'Raka Pradipta', 'Keisha Larasati']
            && $p['status'] === '3 prestasi bisa dimasukkan.')
        ->set('search', 'raka')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Raka Pradipta'] && $p['status'] === '1 prestasi cocok.')
        ->set('search', 'NADIA putri')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Nadia Putri Anindya'])
        ->set('search', 'olimpiade')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Keisha Larasati'])
        // "2026" is in every lomba name. The query trims the term; the box keeps what was typed (see the test below).
        ->set('search', '  2026 ')
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 3 && $p['status'] === '3 prestasi cocok.')
        ->set('search', 'tidak-ada')
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [] && $p['empty'] === false)
        ->assertSee('Tidak ada prestasi yang cocok.')
        // The search stays: the old text is for "nothing is free at all".
        ->assertSeeHtml('id="cari-prestasi"')
        ->assertDontSee('Tidak ada prestasi lain yang bisa dimasukkan.');
});

it('leaves the typed text in the search box as it is, so a space before the next word is not eaten', function (): void {
    em2Free('Nadia Putri Anindya', 'OPSI 2026', 1);
    em2Free('Raka Pradipta', 'FIKSI 2026', 2);

    // Livewire writes a value the server changed back into the box, also while Humas is still typing: a trimmed
    // "nadia " would come back as "nadia" and the next word would be glued to it ("nadiaputri").
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('search', 'nadia ')
        ->assertSet('search', 'nadia ')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Nadia Putri Anindya'] && $p['status'] === '1 prestasi cocok.')
        ->set('search', '  2026 ')
        ->assertSet('search', '  2026 ')
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 2)
        // Only spaces is no search: the whole list, counted as such.
        ->set('search', '   ')
        ->assertSet('search', '   ')
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 2 && $p['status'] === '2 prestasi bisa dimasukkan.')
        // The box has maxlength="100"; a hand-made request cannot park a longer text in the component either.
        ->set('search', str_repeat('a', Apresiasi::SEARCH_MAX + 50))
        ->assertSet('search', str_repeat('a', Apresiasi::SEARCH_MAX))
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [] && $p['status'] === 'Tidak ada prestasi yang cocok.');
});

it('reads % and _ in the search as plain characters, not as wildcards', function (string $term): void {
    em2Free('Nadia Putri Anindya');
    em2Free('Raka Pradipta', 'FIKSI 2026');

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('search', $term)
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [])
        ->assertSee('Tidak ada prestasi yang cocok.');
})->with(['%', '_', '%%', 'N_dia', 'Rak%']);

it('never finds a student with a disabled account, by name or by lomba', function (): void {
    em2Free('Nadia Putri Anindya');
    HumasFixtures::achievement(studentName: 'Fajar Ramadhan', competitionName: 'Kimia Jateng 2026', disabled: true);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Nadia Putri Anindya'])
        ->set('search', 'Fajar')
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [] && $p['status'] === 'Tidak ada prestasi yang cocok.')
        ->assertDontSee('Fajar Ramadhan')
        ->set('search', 'Kimia Jateng')
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [])
        ->assertDontSee('Kimia Jateng 2026');
});

it('lists a student whose old consent columns are false and puts them on the event (D-162)', function (): void {
    $fajar = HumasFixtures::achievement(['publish_consent' => false, 'photo_consent' => false], studentName: 'Fajar Ramadhan');

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Fajar Ramadhan'])
        ->assertSee('Hanya prestasi yang belum masuk acara mana pun. Siswa dengan akun nonaktif tidak ditampilkan.')
        ->assertDontSee('izin publikasi')
        ->set('picked', [$fajar->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors();

    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->sole()->achievement_id)->toBe($fajar->getKey());
});

it('does not find an achievement that is already on an event, open or confirmed', function (): void {
    $nadia = em2Free('Nadia Putri Anindya');
    $raka = em2Free('Raka Pradipta', 'FIKSI 2026');
    em2Free('Keisha Larasati', 'O2SN Kota 2026');
    app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString()), $nadia);
    $held = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $held, $raka);
    app(CompleteAppreciationEvent::class)->handle($this->humas, $held);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Keisha Larasati'])
        ->set('search', 'Nadia')
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === [])
        ->set('search', 'FIKSI')
        ->assertViewHas('picker', fn (array $p): bool => $p['rows'] === []);
});

it('says why nothing can be added when no achievement is free', function (): void {
    app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString()), em2Free('Nadia Putri Anindya'));

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => $p['empty'] === true && $p['rows'] === [])
        ->assertSee('Tidak ada prestasi lain yang bisa dimasukkan. Prestasi yang sudah dijadwalkan di acara lain perlu dilepas dulu dari acara itu.')
        ->assertSee('Tutup')
        ->assertDontSeeHtml('id="cari-prestasi"')
        ->assertDontSeeHtml('wire:click="addAchievements"')
        ->call('cancelAdding')
        ->assertSet('addingEventId', null);
});

it('puts team mates next to each other in the list', function (): void {
    $nadia = em2Free('Nadia Putri Anindya', 'OPSI 2026', 5);
    em2Free('Keisha Larasati', 'FIKSI 2026', 5);
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $raka->forceFill(['verified_at' => $nadia->verified_at])->save();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertViewHas('picker', function (array $p): bool {
            $names = em2Names($p);

            return count($names) === 3 && abs(array_search('Nadia Putri Anindya', $names, true) - array_search('Raka Pradipta', $names, true)) === 1;
        })
        ->set('search', 'OPSI')
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 2 && $p['rows'][1]['class'] === 'XII-B');
});

// --- Steps (D-72) --------------------------------------------------------------------------------------------------

it('lists the achievements in steps of ten and never more than the ceiling', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        em2Free(sprintf('Siswa %02d', $i), sprintf('Lomba %02d', $i), $i);
    }

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->assertSet('pickLimit', Apresiasi::PICK_STEP)
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 10 && $p['more'] === true && $p['capped'] === false
            && em2Names($p)[0] === 'Siswa 01' && em2Names($p)[9] === 'Siswa 10')
        ->assertSee('Menampilkan 10 dari 12 prestasi.')
        ->assertSee('Tampilkan lebih banyak')
        ->assertDontSee('Siswa 11')
        ->call('showMoreOptions')
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 12 && $p['more'] === false)
        ->assertSee('12 prestasi bisa dimasukkan.')
        ->assertSee('Siswa 12')
        ->assertDontSee('Tampilkan lebih banyak')
        // A search starts at the first step again.
        ->set('search', 'Siswa')
        ->assertSet('pickLimit', Apresiasi::PICK_STEP)
        ->assertViewHas('picker', fn (array $p): bool => count($p['rows']) === 10 && $p['status'] === 'Menampilkan 10 dari 12 prestasi yang cocok.');

    for ($i = 0; $i < 12; $i++) {
        $component->call('showMoreOptions');
    }

    $component->assertSet('pickLimit', Apresiasi::PICK_MAX);

    expect(Apresiasi::PICK_STEP)->toBe(10)
        ->and(Apresiasi::PICK_MAX)->toBe(100);
});

it('keeps the step locked against the browser', function (): void {
    expect(fn () => Livewire::actingAs($this->humas)->test(Apresiasi::class)->set('pickLimit', 9999))->toThrow(Exception::class);
});

// --- Ticks ---------------------------------------------------------------------------------------------------------

it('keeps a ticked achievement listed, first, when the search no longer matches it', function (): void {
    $nadia = em2Free('Nadia Putri Anindya', 'OPSI 2026', 1);
    $raka = em2Free('Raka Pradipta', 'FIKSI 2026', 2);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('picked', [$nadia->getKey()])
        // Ticked and still matching: it stays where it is.
        ->assertViewHas('picker', fn (array $p): bool => $p['kept'] === [] && em2Names($p) === ['Nadia Putri Anindya', 'Raka Pradipta'])
        ->set('search', 'raka')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p, 'kept') === ['Nadia Putri Anindya'] && em2Names($p) === ['Raka Pradipta']
            && $p['status'] === '1 prestasi cocok.')
        ->assertSet('picked', [$nadia->getKey()])
        ->assertSeeInOrder(['Nadia Putri Anindya', 'Raka Pradipta', 'Masukkan'])
        ->set('picked', [$nadia->getKey(), $raka->getKey()])
        ->set('search', 'tidak-ada')
        ->assertViewHas('picker', fn (array $p): bool => count($p['kept']) === 2 && $p['rows'] === [])
        ->assertSee('Tidak ada prestasi yang cocok.')
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 prestasi masuk daftar apresiasi. 2 siswa sudah diberi tahu.', tone: 'success');

    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->count())->toBe(2);
});

it('says that a ticked achievement was taken by another event meanwhile, and writes nothing of the click', function (): void {
    $nadia = em2Free('Nadia Putri Anindya', 'OPSI 2026', 1);
    $raka = em2Free('Raka Pradipta', 'FIKSI 2026', 2);
    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('picked', [$nadia->getKey(), $raka->getKey()]);

    // A colleague schedules Nadia's achievement on another event.
    app(RecordAppreciation::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString()), $nadia);
    Notification::fake();

    $component->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Sebagian prestasi yang dipilih sudah tidak bisa dimasukkan. Daftar sudah diperbarui, pilih lagi.')
        ->assertSet('picked', [$raka->getKey()])
        ->assertSet('addingEventId', $this->event->getKey())
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Raka Pradipta']);

    // Nothing of the click was written: all or nothing.
    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->count())->toBe(0);
    Notification::assertNothingSent();

    $component->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Prestasi masuk daftar apresiasi. Siswa sudah diberi tahu.', tone: 'success');

    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->sole()->achievement_id)->toBe($raka->getKey());
});

it('refuses ticks the screen never offered and writes nothing', function (Closure $forge): void {
    $nadia = em2Free('Nadia Putri Anindya');
    $forged = $forge($this->humas, $nadia);

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('picked', $forged)
        ->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Sebagian prestasi yang dipilih sudah tidak bisa dimasukkan. Daftar sudah diperbarui, pilih lagi.')
        ->assertSet('addingEventId', $this->event->getKey())
        // Only what may really go on the event stays ticked.
        ->assertSet('picked', [$nadia->getKey()])
        ->assertNotDispatched('sth-toast');

    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->count())->toBe(0);
    Notification::assertNotSentTo($nadia->student->user, AppreciationScheduled::class);
    Notification::assertSentTimes(BinaanAppreciationNotice::class, 0);
})->with([
    'a student with a disabled account' => [fn ($humas, $nadia) => [
        $nadia->getKey(),
        HumasFixtures::achievement(studentName: 'Fajar Ramadhan', disabled: true)->getKey(),
    ]],
    'an achievement on another event' => [function ($humas, $nadia) {
        $taken = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
        $other = HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString());
        $row = new Appreciation(['achievement_id' => $taken->getKey(), 'appreciation_event_id' => $other->getKey()]);
        $row->forceFill(['status' => 'dijadwalkan'])->save();

        return [$nadia->getKey(), $taken->getKey()];
    }],
    'an id that is no achievement' => [fn ($humas, $nadia) => [$nadia->getKey(), '01JZZZZZZZZZZZZZZZZZZZZZZZ']],
    'something that is not an id' => [fn ($humas, $nadia) => [$nadia->getKey(), 'bukan-id']],
    'a nested value' => [fn ($humas, $nadia) => [$nadia->getKey(), ['x' => 'y']]],
    'the same id twice' => [fn ($humas, $nadia) => [$nadia->getKey(), $nadia->getKey()]],
]);

it('asks for at least one tick and takes at most thirty in one go', function (): void {
    $first = em2Free('Nadia Putri Anindya');
    $ids = [$first->getKey()];

    for ($i = 1; $i <= Apresiasi::PICKED_MAX; $i++) {
        $ids[] = HumasFixtures::teammate($first, 'Siswa Tim '.$i)->getKey();
    }

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Pilih minimal satu prestasi.')
        ->set('picked', $ids)
        ->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Maksimal 30 prestasi sekali masuk.')
        // Nothing is dropped behind the back: every tick is still there to untick one.
        ->assertSet('picked', $ids);

    expect(count($ids))->toBe(31)
        ->and(Appreciation::query()->count())->toBe(0);
    Notification::assertNothingSent();

    array_pop($ids);
    $component->set('picked', $ids)
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertSet('addingEventId', null)
        ->assertDispatched('sth-toast', message: '30 prestasi masuk daftar apresiasi. 30 siswa sudah diberi tahu.', tone: 'success');

    expect(Appreciation::query()->count())->toBe(30);
    // One team, one guru, one click: one notice.
    Notification::assertSentTimes(BinaanAppreciationNotice::class, 1);
});

// --- One "Masukkan" ------------------------------------------------------------------------------------------------

it('puts a ticked team on the event with one click and tells the guru pembimbing once', function (): void {
    $nadia = em2Free('Nadia Putri Anindya');
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $mentor = $nadia->report->registration->mentor;

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('search', 'opsi')
        ->set('picked', [$nadia->getKey(), $raka->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertSet('addingEventId', null)
        ->assertSet('picked', [])
        ->assertSet('search', '')
        ->assertDispatched('sth-toast', message: '2 prestasi masuk daftar apresiasi. 2 siswa sudah diberi tahu.', tone: 'success')
        ->assertSeeHtml('<span class="num">2</span> prestasi')
        ->assertSeeInOrder(['Upacara Senin', 'Nadia Putri Anindya', 'Raka Pradipta', 'Tambah prestasi']);

    expect(Appreciation::query()->where('appreciation_event_id', $this->event->getKey())->count())->toBe(2);
    Notification::assertSentToTimes($nadia->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentToTimes($raka->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentToTimes($mentor, BinaanAppreciationNotice::class, 1);
    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => count($n->binaan) === 2
        && str_contains($n->body(), 'Nadia Putri Anindya') && str_contains($n->body(), 'Raka Pradipta')
        && str_contains($n->body(), 'pukul 07.00 WIB'));
});

it('claims a notice in the toast only for the students who got one (QA8)', function (): void {
    $nadia = em2Free('Nadia Putri Anindya');
    $bima = HumasFixtures::teammate($nadia, 'Bima Satria Wicaksono');
    $bima->student->user->forceFill(['status' => UserStatus::Archived])->save();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('picked', [$nadia->getKey(), $bima->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 prestasi masuk daftar apresiasi. 1 siswa sudah diberi tahu.', tone: 'success');

    Notification::assertNotSentTo($bima->student->user, AppreciationScheduled::class);

    // Nobody can be told (two alumni; a disabled account cannot go on an event at all since D-162): the toast claims nothing.
    $keisha = em2Free('Keisha Larasati', 'FIKSI 2026');
    $dimas = HumasFixtures::teammate($keisha, 'Dimas Arya Nugroho');
    $keisha->student->user->forceFill(['status' => UserStatus::Archived])->save();
    $dimas->student->user->forceFill(['status' => UserStatus::Archived])->save();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('picked', [$keisha->getKey(), $dimas->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 prestasi masuk daftar apresiasi.', tone: 'success');
});

it('says so when the event was confirmed as held while the picker was open', function (): void {
    $today = HumasFixtures::event('Upacara hari ini', now()->toDateString(), '07:00');
    app(RecordAppreciation::class)->handle($this->humas, $today, em2Free('Keisha Larasati', 'O2SN Kota 2026'));
    $nadia = em2Free('Nadia Putri Anindya');
    $raka = em2Free('Raka Pradipta', 'FIKSI 2026');

    $component = Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $today->getKey())
        ->set('picked', [$nadia->getKey(), $raka->getKey()]);

    app(CompleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $today);
    Notification::fake();

    // With several ticks too, what the Action says about the event is shown as it is.
    $component->call('addAchievements')
        ->assertHasErrors(['picked'])
        ->assertSee('Acara ini sudah dilaksanakan. Pilih acara lain.');

    expect(Appreciation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('forgets the search and the ticks when the picker is closed or opened for another event', function (): void {
    $nadia = em2Free('Nadia Putri Anindya');
    $other = HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString(), '07:00');

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('search', 'nadia')
        ->set('picked', [$nadia->getKey()])
        ->call('showMoreOptions')
        ->call('startAdding', $other->getKey())
        ->assertSet('addingEventId', $other->getKey())
        ->assertSet('search', '')
        ->assertSet('picked', [])
        ->assertSet('pickLimit', Apresiasi::PICK_STEP)
        ->set('picked', [$nadia->getKey()])
        ->call('cancelAdding')
        ->assertSet('addingEventId', null)
        ->assertSet('picked', [])
        ->assertViewHas('picker', fn (?array $p): bool => $p === null);

    expect(Appreciation::query()->count())->toBe(0);
});

// --- Roles ---------------------------------------------------------------------------------------------------------

it('lets Kesiswaan search and add through the shared screen, and keeps other roles out', function (): void {
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();
    $nadia = em2Free('Nadia Putri Anindya');
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $kesiswaan = AuthTestHelpers::staff(RoleName::Kesiswaan);

    $this->actingAs($kesiswaan)->get(route('kesiswaan.apresiasi.index'))->assertOk()->assertSee('Buat acara apresiasi')->assertSee('Jam mulai');

    Livewire::actingAs($kesiswaan)
        ->test(Apresiasi::class)
        ->call('startAdding', $this->event->getKey())
        ->set('search', 'raka')
        ->assertViewHas('picker', fn (array $p): bool => em2Names($p) === ['Raka Pradipta'])
        ->set('picked', [$raka->getKey(), $nadia->getKey()])
        ->call('addAchievements')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 prestasi masuk daftar apresiasi. 2 siswa sudah diberi tahu.', tone: 'success');

    expect(Appreciation::query()->count())->toBe(2);

    foreach ([RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah] as $role) {
        Livewire::actingAs(AuthTestHelpers::staff($role))->test(Apresiasi::class)->assertForbidden();
    }

    expect(Appreciation::query()->count())->toBe(2);
});
