<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Enums\AppreciationStatus;
use App\Enums\RoleName;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Notifications\Publications\AppreciationScheduled;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| CreateAppreciationEvent, RecordAppreciation, CompleteAppreciationEvent (W2-C).
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

    $event = app(CreateAppreciationEvent::class)->handle($actor, '  Upacara   Senin, 5 Oktober 2026 ', $date);

    expect($event->name)->toBe('Upacara Senin, 5 Oktober 2026')
        ->and($event->fresh()->held_on->toDateString())->toBe($date)
        ->and(Activity::query()->where('event', 'appreciation.event_created')->count())->toBe(1);
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('accepts today as the event date', function (): void {
    $event = app(CreateAppreciationEvent::class)->handle($this->humas, 'Apel pagi', now()->toDateString());

    expect($event->exists)->toBeTrue();
});

it('validates the event', function (string $name, string $date, string $key): void {
    try {
        app(CreateAppreciationEvent::class)->handle($this->humas, $name, $date);
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
    app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date);

    expect(fn () => app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date))
        ->toThrow(ValidationException::class, 'Acara ini sudah ada.');
});

it('refuses roles without appreciation.manage', function (RoleName $role): void {
    expect(fn () => app(CreateAppreciationEvent::class)->handle(AuthTestHelpers::staff($role), 'Upacara', now()->addDay()->toDateString()))
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

it('marks every scheduled appreciation of a held event as done', function (): void {
    $event = HumasFixtures::event('Upacara Senin', now()->toDateString());
    $a = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    $b = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: 'Raka Pradipta'));

    $count = app(CompleteAppreciationEvent::class)->handle($this->humas, $event);

    expect($count)->toBe(2)
        ->and($a->fresh()->status)->toBe(AppreciationStatus::Selesai)
        ->and($b->fresh()->status)->toBe(AppreciationStatus::Selesai)
        ->and(Activity::query()->where('event', 'appreciation.event_completed')->count())->toBe(1);

    expect(fn () => app(CompleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, 'Tidak ada apresiasi yang perlu ditandai.');
});

it('refuses to complete a future event or for other roles', function (): void {
    $event = HumasFixtures::event();
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());

    expect(fn () => app(CompleteAppreciationEvent::class)->handle($this->humas, $event))
        ->toThrow(ValidationException::class, 'Acara ini belum berlangsung.');
    expect(fn () => app(CompleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::TataUsaha), $event))
        ->toThrow(AuthorizationException::class);
});
