<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\DeleteAppreciationEvent;
use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\RecordAppreciations;
use App\Actions\Publications\ReleaseAppreciation;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Livewire\Humas\Apresiasi;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\Publication;
use App\Models\TrashEntry;
use App\Models\User;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
 * smaga_server, the trash of 5 Nov 2026: a trashed student (students row and users row soft-deleted, status unchanged) is
 * hidden everywhere until a restore. Humas › Apresiasi and the publication Actions must neither tell that student, nor
 * name them to the guru pembimbing, nor list or count their row on an event card. The row itself stays on the event, so
 * a restore brings it back unchanged. Fictional people only (DESIGN-SPEC-v2 §5).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->humas = HumasFixtures::humas();
    $this->admin = AuthTestHelpers::staff(RoleName::Admin);

    /** Admin › Kelas › "Hapus": the student goes to the trash; returns the trash entry. */
    $this->trash = fn (Achievement $achievement): TrashEntry => app(TrashStudents::class)
        ->handle($this->admin, [(int) $achievement->student->user_id], 'Data siswa salah saat impor.')[0];

    /** The trashed account (the default query no longer finds it). */
    $this->account = fn (Achievement $achievement): User => User::withTrashed()->findOrFail($achievement->student->user_id);
});

// --- DeleteAppreciationEvent ---------------------------------------------------------------------------------

it('deletes an event without telling a trashed student or naming them to the guru pembimbing', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $raka]);
    ($this->trash)($raka);
    Notification::fake();

    expect(DeleteAppreciationEvent::studentsToTell($event))->toBe(1);

    $released = app(DeleteAppreciationEvent::class)->handle($this->humas, $event);
    $log = Activity::query()->where('event', 'appreciation.event_deleted')->sole();

    // The trashed row goes with the event (the audit keeps it); the screen's count is the rows it showed.
    expect($released)->toBe(1)
        ->and(Appreciation::query()->count())->toBe(0)
        ->and($log->properties['rows'])->toBe(2)
        ->and($log->properties['notified'])->toBe(1);

    Notification::assertSentToTimes($nadia->student->user, AppreciationCancelled::class, 1);
    Notification::assertNothingSentTo(($this->account)($raka));

    $mentor = $nadia->report->registration->mentor;
    Notification::assertSentToTimes($mentor, BinaanAppreciationNotice::class, 1);
    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => $n->kind === BinaanAppreciationNotice::CANCELLED
        && count($n->binaan) === 1
        && str_contains($n->binaan[0], 'Nadia Putri Anindya')
        && ! str_contains($n->body(), 'Raka'));
});

it('tells nobody when the only student on a deleted event is in the trash', function (): void {
    $event = HumasFixtures::event();
    $bima = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    app(RecordAppreciation::class)->handle($this->humas, $event, $bima);
    ($this->trash)($bima);
    Notification::fake();

    expect(DeleteAppreciationEvent::studentsToTell($event))->toBe(0)
        ->and(app(DeleteAppreciationEvent::class)->handle($this->humas, $event))->toBe(0)
        ->and(Appreciation::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.event_deleted')->sole()->properties['notified'])->toBe(0);

    Notification::assertNothingSent();
});

// --- ReleaseAppreciation -------------------------------------------------------------------------------------

it('releases a trashed student\'s row without telling the student or the guru pembimbing', function (): void {
    $event = HumasFixtures::event();
    $bima = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $bima);
    ($this->trash)($bima);
    Notification::fake();

    expect(app(ReleaseAppreciation::class)->handle($this->humas, $row))->toBeFalse()
        ->and(Appreciation::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.released')->sole()->properties['notified'])->toBeFalse();

    Notification::assertNothingSent();
});

// --- PublishPublication --------------------------------------------------------------------------------------

it('never counts a trashed student as told about a publication', function (): void {
    $publication = HumasFixtures::publication();
    ($this->trash)($publication->achievement);

    expect(PublishPublication::notifiesStudent(Publication::query()->findOrFail($publication->getKey())))->toBeFalse();
});

it('publishes a team card without the trashed teammate, who hears nothing', function (): void {
    $publication = HumasFixtures::publication();
    $raka = HumasFixtures::teammate($publication->achievement, 'Raka Pradipta');
    $rakaCard = new Publication(['achievement_id' => $raka->getKey()]);
    $rakaCard->forceFill(['status' => PublicationStatus::Siap, 'channels' => []])->save();
    ($this->trash)($raka);
    Notification::fake();

    $action = app(PublishPublication::class);
    $action->handle($this->humas, Publication::query()->findOrFail($publication->getKey()), 'Selamat untuk tim kami.', ['instagram']);

    expect($action->team)->toBe([])
        ->and($rakaCard->fresh()->status)->toBe(PublicationStatus::Siap);

    Notification::assertSentToTimes($publication->achievement->student->user, AchievementPublished::class, 1);
    Notification::assertNothingSentTo(($this->account)($raka));
});

it('tells no student who went to the trash after the card was loaded', function (): void {
    $publication = HumasFixtures::publication()->load('achievement.student.user');
    ($this->trash)($publication->achievement);
    Notification::fake();

    // Whatever the publish itself does with the stale card, the trashed student is never told.
    rescue(fn () => app(PublishPublication::class)->handle($this->humas, $publication, null, ['instagram']), report: false);

    Notification::assertNothingSentTo(($this->account)($publication->achievement));
});

// --- RecordAppreciations -------------------------------------------------------------------------------------

it('counts no trashed student among the students told', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    ($this->trash)($raka);

    $achievements = Achievement::query()->whereKey([$nadia->getKey(), $raka->getKey()])->get();

    expect(RecordAppreciations::toldStudents($achievements))->toBe(1);
});

it('refuses a student who went to the trash after the picker loaded the row, and tells nobody', function (): void {
    $event = HumasFixtures::event();
    $bima = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    $bima->load('student.user');
    ($this->trash)($bima);
    Notification::fake();

    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $event, [$bima]))
        ->toThrow(ValidationException::class, RecordAppreciations::INACTIVE_MESSAGE);

    expect(Appreciation::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.recorded')->count())->toBe(0);
    Notification::assertNothingSent();
});

// --- Humas › Apresiasi -----------------------------------------------------------------------------------------

it('hides a trashed student\'s row on the event card and in its counts, and shows it again after a restore', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $raka]);
    $entry = ($this->trash)($raka);

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('Raka Pradipta')
        ->assertSeeHtml('<span class="num">1</span> prestasi')
        ->assertDontSeeHtml('<span class="num">2</span> prestasi')
        // The "Hapus acara?" dialog counts the rows the card shows.
        ->call('confirmDelete', (string) $event->getKey())
        ->assertDispatched('sth-modal-open')
        ->assertSee('1 prestasi dilepas dari acara ini')
        ->assertDontSeeHtml('<span class="num">2</span> prestasi');

    app(RestoreFromTrash::class)->handle($this->admin, $entry);

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Raka Pradipta')
        ->assertSeeHtml('<span class="num">2</span> prestasi');
});

it('counts only the shown rows in the toast after "Hapus acara"', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $raka]);
    ($this->trash)($raka);

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('confirmDelete', (string) $event->getKey())
        ->call('runConfirmed')
        ->assertDispatched('sth-toast', message: 'Acara dihapus. 1 prestasi dilepas dari acara ini dan siswanya diberi tahu.', tone: 'success');
});

it('answers a release click on a trashed student\'s row like a row that is gone, and keeps the row', function (): void {
    $event = HumasFixtures::event();
    $bima = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $bima);
    ($this->trash)($bima);

    // Admin holds `student.view`: the dialog would print the name of a student hidden everywhere.
    Livewire::actingAs($this->admin)->test(Apresiasi::class)
        ->call('confirmRelease', (string) $row->getKey())
        ->assertDispatched('sth-toast', message: ReleaseAppreciation::GONE_MESSAGE, tone: 'error')
        ->assertNotDispatched('sth-modal-open')
        ->assertDontSee('Bima Satria Wicaksono');

    expect(Appreciation::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('shows an event that holds only a trashed student\'s row as empty', function (): void {
    $event = HumasFixtures::event('Apel pagi', now()->toDateString());
    $bima = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    app(RecordAppreciation::class)->handle($this->humas, $event, $bima);
    ($this->trash)($bima);

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertDontSee('Bima Satria Wicaksono')
        ->assertSeeHtml('<span class="num">0</span> prestasi')
        ->assertSee('Belum bisa ditandai sudah dilaksanakan: belum ada prestasi di acara ini.')
        ->assertSee('Hapus acara')
        // A stale or forged click answers with the reason instead of a dialog that counts a hidden row.
        ->call('confirmComplete', (string) $event->getKey())
        ->assertDispatched('sth-toast', message: CompleteAppreciationEvent::MESSAGES[CompleteAppreciationEvent::BLOCK_EMPTY], tone: 'error')
        ->assertNotDispatched('sth-modal-open');
});
