<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\DeleteAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\ReleaseAppreciation;
use App\Enums\AppreciationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Student;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\AppreciationReleased;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| CreateAppreciationEvent, RecordAppreciation, CompleteAppreciationEvent (W2-C); the event-level
| confirmation and DeleteAppreciationEvent (M1-E, lead 2026-10-04 item 12).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

// --- CreateAppreciationEvent ---------------------------------------------------------------

it('creates an appreciation event (Humas and Kesiswaan)', function (RoleName $role): void {
    $actor = $role === RoleName::Humas ? $this->humas : AuthTestHelpers::staff($role);
    $date = now()->addDays(4)->toDateString();

    $event = app(CreateAppreciationEvent::class)->handle($actor, '  Upacara   Senin, 5 Oktober 2026 ', $date, '07:00');

    expect($event->name)->toBe('Upacara Senin, 5 Oktober 2026')
        ->and($event->fresh()->held_on->toDateString())->toBe($date)
        ->and(Activity::query()->where('event', 'appreciation.event_created')->count())->toBe(1);
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('accepts today as the event date', function (): void {
    $event = app(CreateAppreciationEvent::class)->handle($this->humas, 'Apel pagi', now()->toDateString(), '07:00');

    expect($event->exists)->toBeTrue();
});

it('validates the event', function (string $name, string $date, string $key): void {
    try {
        app(CreateAppreciationEvent::class)->handle($this->humas, $name, $date, '07:00');
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }

    expect(AppreciationEvent::query()->count())->toBe(0);
})->with([
    'empty name' => ['   ', '2099-01-05', 'name'],
    'name too long' => [str_repeat('a', 201), '2099-01-05', 'name'],
    'no date' => ['Upacara', '', 'held_on'],
    'bad date' => ['Upacara', '2026-02-31', 'held_on'],
    'past date' => ['Upacara', '2020-01-06', 'held_on'],
]);

it('rejects a duplicate event', function (): void {
    $date = now()->addWeek()->toDateString();
    app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date, '07:00');

    expect(fn () => app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date, '07:00'))
        ->toThrow(ValidationException::class, 'Acara ini sudah ada.');
});

it('refuses roles without appreciation.manage', function (RoleName $role): void {
    expect(fn () => app(CreateAppreciationEvent::class)->handle(AuthTestHelpers::staff($role), 'Upacara', now()->addDay()->toDateString(), '07:00'))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah]);

// --- RecordAppreciation --------------------------------------------------------------------

it('records a verified achievement on an upcoming event and notifies the student', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event();

    $appreciation = app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);

    expect($appreciation->fresh()->status)->toBe(AppreciationStatus::Dijadwalkan)
        ->and($appreciation->achievement_id)->toBe($achievement->getKey())
        ->and($appreciation->appreciation_event_id)->toBe($event->getKey())
        ->and(Activity::query()->where('event', 'appreciation.recorded')->count())->toBe(1);

    Notification::assertSentTo($achievement->student->user, AppreciationScheduled::class, function (AppreciationScheduled $n) use ($event): bool {
        return $n->eventName === $event->name && $n->competitionName === 'OPSI 2026' && $n->resultLabel === 'Juara II';
    });
});

it('refuses a duplicate appreciation', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event();
    app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $event, $achievement))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah ada di daftar acara tersebut.');
    expect(Appreciation::query()->count())->toBe(1);
});

it('refuses past events, unverified achievements and students without consent', function (): void {
    $past = HumasFixtures::event('Upacara lalu', now()->subDays(3)->toDateString());
    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $past, HumasFixtures::achievement()))
        ->toThrow(ValidationException::class, 'Acara apresiasi ini sudah lewat.');

    $event = HumasFixtures::event();
    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(verified: false)))
        ->toThrow(ValidationException::class, 'Prestasi ini belum terverifikasi.');
    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(['publish_consent' => false])))
        ->toThrow(ValidationException::class, 'Siswa belum memberi izin publikasi.');

    expect(Appreciation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses roles without appreciation.manage to record', function (): void {
    expect(fn () => app(RecordAppreciation::class)->handle(AuthTestHelpers::staff(RoleName::Guru), HumasFixtures::event(), HumasFixtures::achievement()))
        ->toThrow(AuthorizationException::class);
});

// --- CompleteAppreciationEvent -------------------------------------------------------------

it('confirms a held event: every scheduled appreciation is done and the event is stamped', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $a = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $b = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: 'Raka Pradipta'));
    Notification::fake();

    expect($event->isCompleted())->toBeFalse();

    $count = app(CompleteAppreciationEvent::class)->handle($this->humas, $event);
    $fresh = $event->fresh();
    $log = Activity::query()->where('event', 'appreciation.event_completed')->sole();

    expect($count)->toBe(2)
        ->and($a->fresh()->status)->toBe(AppreciationStatus::Selesai)
        ->and($b->fresh()->status)->toBe(AppreciationStatus::Selesai)
        ->and($fresh->isCompleted())->toBeTrue()
        ->and($fresh->completed_at->isToday())->toBeTrue()
        ->and((int) $fresh->completed_by)->toBe($this->humas->getKey())
        ->and($log->causer_id)->toBe($this->humas->getKey())
        ->and($log->properties['name'])->toBe('Upacara Senin')
        ->and($log->properties['rows'])->toBe(2)
        ->and(AppreciationEvent::query()->open()->count())->toBe(0)
        ->and(AppreciationEvent::query()->completed()->count())->toBe(1);

    // Students were told when they were scheduled; the confirmation sends nothing.
    Notification::assertNothingSent();

    expect(fn () => app(CompleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, 'Acara ini sudah ditandai dilaksanakan.');
});

it('refuses to confirm a future event, an empty event, or for other roles', function (): void {
    $event = HumasFixtures::event();
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());

    expect(fn () => app(CompleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, 'Acara ini belum berlangsung.');
    expect(fn () => app(CompleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::TataUsaha), $event))
        ->toThrow(AuthorizationException::class);

    $empty = HumasFixtures::event('Apel pagi', now()->toDateString());
    expect(fn () => app(CompleteAppreciationEvent::class)->handle($this->humas, $empty))
        ->toThrow(ValidationException::class, 'Tidak ada apresiasi yang perlu ditandai.');

    expect($event->fresh()->isCompleted())->toBeFalse()
        ->and($empty->fresh()->isCompleted())->toBeFalse()
        ->and(Activity::query()->where('event', 'appreciation.event_completed')->count())->toBe(0);
});

it('names the reason an event cannot be confirmed yet', function (): void {
    $future = HumasFixtures::event();
    $today = HumasFixtures::event('Apel pagi', now()->toDateString());
    $done = HumasFixtures::event('Upacara lalu', now()->subWeek()->toDateString());
    $done->forceFill(['completed_at' => now()->subDays(6)])->save();

    expect(CompleteAppreciationEvent::blocker($future, 3))->toBe(CompleteAppreciationEvent::BLOCK_NOT_YET)
        ->and(CompleteAppreciationEvent::blocker($today, 0))->toBe(CompleteAppreciationEvent::BLOCK_EMPTY)
        ->and(CompleteAppreciationEvent::blocker($today, 1))->toBeNull()
        ->and(CompleteAppreciationEvent::blocker($done->fresh(), 1))->toBe(CompleteAppreciationEvent::BLOCK_DONE);
});

it('stamps events confirmed before M1-E when the migration backfills', function (): void {
    $row = function (string $eventId, AppreciationStatus $status): void {
        $appreciation = new Appreciation(['achievement_id' => HumasFixtures::achievement()->getKey(), 'appreciation_event_id' => $eventId]);
        $appreciation->forceFill(['status' => $status, 'updated_at' => now()->subDays(6)])->save();
    };

    $done = HumasFixtures::event('Upacara lalu', now()->subWeek()->toDateString());
    $row($done->getKey(), AppreciationStatus::Selesai);
    $row($done->getKey(), AppreciationStatus::Selesai);
    $mixed = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    $row($mixed->getKey(), AppreciationStatus::Selesai);
    $row($mixed->getKey(), AppreciationStatus::Dijadwalkan);
    $scheduled = HumasFixtures::event();
    $row($scheduled->getKey(), AppreciationStatus::Dijadwalkan);
    $empty = HumasFixtures::event('Acara uji coba', now()->addDays(9)->toDateString());

    $migration = require database_path('migrations/2026_10_04_140000_m1e_add_completion_to_appreciation_events.php');
    $migration->backfill();

    expect($done->fresh()->isCompleted())->toBeTrue()
        ->and($done->fresh()->completed_at->toDateString())->toBe(now()->subDays(6)->toDateString())
        ->and($done->fresh()->completed_by)->toBeNull()
        ->and($mixed->fresh()->isCompleted())->toBeFalse()
        ->and($scheduled->fresh()->isCompleted())->toBeFalse()
        ->and($empty->fresh()->isCompleted())->toBeFalse();
});

it('adds nothing to a confirmed event', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    app(CompleteAppreciationEvent::class)->handle($this->humas, $event);

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: 'Raka Pradipta')))
        ->toThrow(ValidationException::class, 'Acara ini sudah dilaksanakan. Pilih acara lain.');
    expect(Appreciation::query()->count())->toBe(1);
});

// --- DeleteAppreciationEvent ---------------------------------------------------------------

it('deletes an empty event (Humas, Kesiswaan, Admin), audited, nobody notified', function (RoleName $role): void {
    $actor = $role === RoleName::Humas ? $this->humas : AuthTestHelpers::staff($role);
    $event = HumasFixtures::event('Acara uji coba');

    $released = app(DeleteAppreciationEvent::class)->handle($actor, $event);
    $log = Activity::query()->where('event', 'appreciation.event_deleted')->sole();

    expect($released)->toBe(0)
        ->and(AppreciationEvent::query()->count())->toBe(0)
        ->and($log->causer_id)->toBe($actor->getKey())
        ->and($log->subject_id)->toBe($event->getKey())
        ->and($log->properties['name'])->toBe('Acara uji coba')
        ->and($log->properties['held_on'])->toBe($event->held_on->toDateString())
        ->and($log->properties['rows'])->toBe(0)
        ->and($log->properties['notified'])->toBe(0);

    Notification::assertNothingSent();
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('releases the scheduled rows and tells each student once that the event is off', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta');
    $archived = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    // A second achievement of Nadia on the same event: still one notice for her.
    $second = HumasFixtures::achievement(studentName: 'Salsabila Kirana', competitionName: 'FIKSI 2026');
    $second->forceFill(['student_id' => $nadia->student_id])->save();

    foreach ([$nadia, $raka, $archived, $second->fresh()] as $achievement) {
        app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);
    }

    $archived->student->user->forceFill(['status' => UserStatus::Archived])->save();
    Notification::fake();

    $released = app(DeleteAppreciationEvent::class)->handle($this->humas, $event);
    $log = Activity::query()->where('event', 'appreciation.event_deleted')->sole();

    expect($released)->toBe(4)
        ->and(Appreciation::query()->count())->toBe(0)
        ->and(AppreciationEvent::query()->whereKey($event->getKey())->exists())->toBeFalse()
        ->and($log->properties['rows'])->toBe(4)
        ->and($log->properties['notified'])->toBe(2)
        ->and($log->properties['achievement_ids'])->toHaveCount(4)
        ->and(json_encode($log->properties))->not->toContain('Nadia');

    Notification::assertSentToTimes($nadia->student->user, AppreciationCancelled::class, 1);
    Notification::assertSentTo($nadia->student->user, AppreciationCancelled::class, function (AppreciationCancelled $n) use ($event): bool {
        return $n->eventName === $event->name && count($n->achievements) === 2;
    });
    Notification::assertSentTo($raka->student->user, AppreciationCancelled::class, function (AppreciationCancelled $n): bool {
        return $n->achievements === ['Juara II OPSI 2026'];
    });
    Notification::assertNotSentTo($archived->student->user, AppreciationCancelled::class);

    // Released: the achievement can go on another event.
    $other = HumasFixtures::event('Upacara Senin, 12 Oktober 2026', now()->addDays(11)->toDateString());
    expect(app(RecordAppreciation::class)->handle($this->humas, $other, $nadia)->exists)->toBeTrue();
});

it('refuses to delete a confirmed event: it is the record', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $achievement = HumasFixtures::achievement();
    app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);
    app(CompleteAppreciationEvent::class)->handle($this->humas, $event);

    expect(fn () => app(DeleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, 'Acara yang sudah dilaksanakan tidak bisa dihapus.');

    expect(AppreciationEvent::query()->count())->toBe(1)
        ->and(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai)
        ->and(Activity::query()->where('event', 'appreciation.event_deleted')->count())->toBe(0);
    Notification::assertNotSentTo($achievement->student->user, AppreciationCancelled::class);
});

it('refuses to delete an event that already has an appreciated row (data from before M1-E)', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $row->forceFill(['status' => AppreciationStatus::Selesai])->save();
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: 'Raka Pradipta'));

    expect(fn () => app(DeleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, DeleteAppreciationEvent::COMPLETED_MESSAGE);
    expect(Appreciation::query()->count())->toBe(2)
        ->and(AppreciationEvent::query()->count())->toBe(1);
});

it('refuses roles without appreciation.manage to delete', function (RoleName $role): void {
    $event = HumasFixtures::event();

    expect(fn () => app(DeleteAppreciationEvent::class)->handle(AuthTestHelpers::staff($role), $event))
        ->toThrow(AuthorizationException::class);
    expect(AppreciationEvent::query()->count())->toBe(1);
})->with([RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('refuses a student to delete or confirm an event', function (): void {
    $event = HumasFixtures::event('Apel pagi', now()->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $student = Student::factory()->activated()->create()->user()->first()->fresh();

    expect(fn () => app(DeleteAppreciationEvent::class)->handle($student, $event))->toThrow(AuthorizationException::class);
    expect(fn () => app(CompleteAppreciationEvent::class)->handle($student, $event))->toThrow(AuthorizationException::class);
    expect(AppreciationEvent::query()->open()->count())->toBe(1);
});

// --- M2-B: one achievement, one open event (QA 2026-10-05, Q2-10) ---------------------------

it('refuses an achievement that is still scheduled on another event that is not confirmed, naming that event', function (): void {
    $achievement = HumasFixtures::achievement();
    $first = HumasFixtures::event('Upacara Senin, 5 Oktober 2026', now()->addDays(4)->toDateString());
    $second = HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $first, $achievement);
    Notification::fake();

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, $second, $achievement))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah dijadwalkan di acara Upacara Senin, 5 Oktober 2026 ('
            .now()->addDays(4)->locale('id')->translatedFormat('j M Y').'). Lepas dulu dari acara itu.');

    expect(Appreciation::query()->count())->toBe(1)
        ->and(Appreciation::query()->sole()->appreciation_event_id)->toBe($first->getKey());
    Notification::assertNothingSent();

    // Another achievement of the same student is a different matter.
    $other = HumasFixtures::achievement(studentName: 'Salsabila Kirana', competitionName: 'FIKSI 2026');
    $other->forceFill(['student_id' => $achievement->student_id])->save();
    expect(app(RecordAppreciation::class)->handle($this->humas, $second, $other->fresh())->exists)->toBeTrue();
});

it('counts an unconfirmed event whose date has passed as still scheduled', function (): void {
    $achievement = HumasFixtures::achievement();
    $overdue = HumasFixtures::event('Upacara lalu', now()->subDays(3)->toDateString());
    $row = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $overdue->getKey()]);
    $row->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event(), $achievement))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah dijadwalkan di acara Upacara lalu (');
    expect(Appreciation::query()->count())->toBe(1);
});

it('refuses an achievement that was already appreciated on a confirmed event', function (): void {
    $achievement = HumasFixtures::achievement();
    $held = HumasFixtures::event('Upacara Senin', now()->toDateString());
    app(RecordAppreciation::class)->handle($this->humas, $held, $achievement);
    app(CompleteAppreciationEvent::class)->handle($this->humas, $held);
    Notification::fake();

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString()), $achievement))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah diapresiasi di acara Upacara Senin ('.now()->locale('id')->translatedFormat('j M Y').').');

    expect(Appreciation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('names the appreciated event first when old data has the achievement on two events', function (): void {
    // The state QA produced before the rule: scheduled on one event, confirmed on another.
    $achievement = HumasFixtures::achievement();
    $open = HumasFixtures::event('Upacara Senin, 5 Oktober 2026', now()->addDays(4)->toDateString());
    $done = HumasFixtures::event('Acara hari ini', now()->toDateString());

    foreach ([[$open, AppreciationStatus::Dijadwalkan], [$done, AppreciationStatus::Selesai]] as [$event, $status]) {
        $row = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
        $row->forceFill(['status' => $status])->save();
    }

    $done->forceFill(['completed_at' => now()])->save();

    expect(fn () => app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event('Apel pagi', now()->addDays(2)->toDateString()), $achievement))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah diapresiasi di acara Acara hari ini (');

    // The leftover scheduled row can be released.
    $leftover = Appreciation::query()->where('appreciation_event_id', $open->getKey())->sole();
    expect($this->humas->can('delete', $leftover))->toBeTrue();
    app(ReleaseAppreciation::class)->handle($this->humas, $leftover);
    expect(Appreciation::query()->sole()->appreciation_event_id)->toBe($done->getKey());
});

// --- ReleaseAppreciation ("Lepas dari acara") -----------------------------------------------

it('releases one scheduled achievement from an open event, audited, and tells that student only', function (RoleName $role): void {
    $actor = $role === RoleName::Humas ? $this->humas : AuthTestHelpers::staff($role);
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $nadia);
    $kept = app(RecordAppreciation::class)->handle($this->humas, $event, $raka);
    Notification::fake();

    $told = app(ReleaseAppreciation::class)->handle($actor, $row);
    $log = Activity::query()->where('event', 'appreciation.released')->sole();

    expect($told)->toBeTrue()
        ->and(Appreciation::query()->whereKey($row->getKey())->exists())->toBeFalse()
        ->and(Appreciation::query()->whereKey($kept->getKey())->exists())->toBeTrue()
        ->and($event->fresh()->isCompleted())->toBeFalse()
        ->and($log->causer_id)->toBe($actor->getKey())
        ->and($log->subject_id)->toBe($event->getKey())
        ->and($log->properties['achievement_id'])->toBe($nadia->getKey())
        ->and($log->properties['name'])->toBe($event->name)
        ->and($log->properties['held_on'])->toBe($event->held_on->toDateString())
        ->and($log->properties['notified'])->toBeTrue()
        ->and(json_encode($log->properties))->not->toContain('Nadia');

    Notification::assertSentToTimes($nadia->student->user, AppreciationReleased::class, 1);
    Notification::assertSentTo($nadia->student->user, AppreciationReleased::class, function (AppreciationReleased $n) use ($event): bool {
        return $n->eventName === $event->name && $n->achievement === 'Juara II OPSI 2026';
    });
    Notification::assertNotSentTo($raka->student->user, AppreciationReleased::class);
    Notification::assertNotSentTo($nadia->student->user, AppreciationCancelled::class);

    // Released: the achievement can go on another event.
    $other = HumasFixtures::event('Upacara Senin, 12 Oktober 2026', now()->addDays(11)->toDateString());
    expect(app(RecordAppreciation::class)->handle($this->humas, $other, $nadia)->exists)->toBeTrue();
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('releases without a notice to a student who can no longer log in (the guru pembimbing still hears)', function (): void {
    $event = HumasFixtures::event();
    $achievement = HumasFixtures::achievement(studentName: 'Bima Satria Wicaksono');
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);
    $student = $achievement->student->user;
    $student->forceFill(['status' => UserStatus::Archived])->save();
    Notification::fake();

    expect(app(ReleaseAppreciation::class)->handle($this->humas, $row))->toBeFalse()
        ->and(Appreciation::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.released')->sole()->properties['notified'])->toBeFalse();
    Notification::assertNothingSentTo($student);
    Notification::assertSentTimes(AppreciationReleased::class, 0);
    Notification::assertSentToTimes($achievement->report->registration->mentor, BinaanAppreciationNotice::class, 1);
});

it('refuses to release from a confirmed event: it is the record', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $achievement = HumasFixtures::achievement();
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);
    app(CompleteAppreciationEvent::class)->handle($this->humas, $event);
    Notification::fake();

    expect(fn () => app(ReleaseAppreciation::class)->handle($this->humas, $row))
        ->toThrow(ValidationException::class, 'Prestasi di acara yang sudah dilaksanakan tidak bisa dilepas.');

    expect(Appreciation::query()->sole()->status)->toBe(AppreciationStatus::Selesai)
        ->and(Activity::query()->where('event', 'appreciation.released')->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses to release a row that is already appreciated on an unconfirmed event (data from before M1-E)', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $row = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $row->forceFill(['status' => AppreciationStatus::Selesai])->save();

    expect(fn () => app(ReleaseAppreciation::class)->handle($this->humas, $row))
        ->toThrow(ValidationException::class, ReleaseAppreciation::COMPLETED_MESSAGE);
    expect(Appreciation::query()->count())->toBe(1);
});

it('answers plainly when the row was already released', function (): void {
    $row = app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event(), HumasFixtures::achievement());
    app(ReleaseAppreciation::class)->handle($this->humas, $row);

    expect(fn () => app(ReleaseAppreciation::class)->handle($this->humas, $row))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah tidak ada di acara tersebut.');
    expect(Activity::query()->where('event', 'appreciation.released')->count())->toBe(1);
});

it('refuses roles without appreciation.manage, and students, to release', function (): void {
    $row = app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event(), HumasFixtures::achievement());
    $student = Student::factory()->activated()->create()->user()->first()->fresh();

    foreach ([AuthTestHelpers::staff(RoleName::Guru), AuthTestHelpers::staff(RoleName::TataUsaha), AuthTestHelpers::staff(RoleName::KepalaSekolah), $student] as $user) {
        expect(fn () => app(ReleaseAppreciation::class)->handle($user, $row))->toThrow(AuthorizationException::class)
            ->and($user->can('delete', $row))->toBeFalse();
    }

    expect(Appreciation::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'appreciation.released')->count())->toBe(0);
});

// QA5 (2026-10-07, client document "Penjadwalan Apresiasi": "Siswa dan guru pembimbing menerima notifikasi") --

it('tells the guru pembimbing when a binaan is scheduled, released, or the event is deleted', function (): void {
    $achievement = HumasFixtures::achievement();
    $mentor = $achievement->report->registration->mentor;
    $event = HumasFixtures::event();

    $appreciation = app(RecordAppreciation::class)->handle($this->humas, $event, $achievement);

    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, function (BinaanAppreciationNotice $n) use ($mentor, $event): bool {
        return $n->kind === BinaanAppreciationNotice::SCHEDULED
            && $n->title() === 'Binaan masuk daftar apresiasi'
            && str_contains($n->body(), 'Nadia Putri Anindya (Juara II OPSI 2026) akan diapresiasi di '.$event->name)
            && html_entity_decode((string) $n->toMail($mentor)->greeting, ENT_QUOTES | ENT_HTML5) === 'Yth. Retno Wulandari, S.Pd.,';
    });

    app(ReleaseAppreciation::class)->handle($this->humas, $appreciation);

    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => $n->kind === BinaanAppreciationNotice::RELEASED
        && $n->title() === 'Jadwal apresiasi binaan berubah'
        && str_contains($n->body(), 'Nadia Putri Anindya (Juara II OPSI 2026) tidak jadi diapresiasi di '.$event->name));

    app(RecordAppreciation::class)->handle($this->humas, $event, $achievement->fresh());
    app(DeleteAppreciationEvent::class)->handle($this->humas, $event);

    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => $n->kind === BinaanAppreciationNotice::CANCELLED
        && $n->title() === 'Acara apresiasi binaan dibatalkan'
        && str_contains($n->body(), $event->name)
        && str_contains($n->body(), 'Nadia Putri Anindya (Juara II OPSI 2026)'));

    // Scheduled, released, scheduled again, event deleted.
    Notification::assertSentToTimes($mentor, BinaanAppreciationNotice::class, 4);
});

it('tells no guru pembimbing who can no longer log in', function (): void {
    $achievement = HumasFixtures::achievement();
    $mentor = $achievement->report->registration->mentor;
    $mentor->forceFill(['status' => UserStatus::Disabled])->save();

    app(RecordAppreciation::class)->handle($this->humas, HumasFixtures::event(), $achievement);

    Notification::assertNotSentTo($mentor, BinaanAppreciationNotice::class);
});

it('finds the gurus of a deleted event in a fixed number of queries, one notice per guru', function (): void {
    $event = HumasFixtures::event();
    $names = ['Nadia Putri Anindya', 'Dimas Arya Nugroho', 'Keisha Larasati', 'Rizky Maulana'];

    foreach ($names as $i => $name) {
        app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: $name, competitionName: 'Lomba '.($i + 1)));
    }

    Notification::fake();
    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (preg_match('/from "(reports|registrations)"/', $query->sql) === 1) {
            $lookups++;
        }
    });

    app(DeleteAppreciationEvent::class)->handle($this->humas, $event);

    // The eager load of the rows plus one lookup each for reports and registrations; before QA5's fix it was two per
    // row (9 for these 4 rows).
    expect($lookups)->toBeLessThanOrEqual(3);
    Notification::assertSentTimes(BinaanAppreciationNotice::class, count($names));
});
