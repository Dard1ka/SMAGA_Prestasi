<?php

declare(strict_types=1);

use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RemindDuePublication;
use App\Actions\Publications\SchedulePublication;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Jobs\RemindDuePublications;
use App\Models\File;
use App\Models\Publication;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\PublicationDue;
use App\Policies\PublicationPolicy;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures as F;

/*
 * EM2 (lead, 2026-10-10; D-122, replaces the auto-publish half of D-47): the schedule of a publication is only a
 * reminder for Humas. When scheduled_at arrives the scheduler (RemindDuePublication via the RemindDuePublications job)
 * mails every active Humas account once; the row stays `terjadwal` ("Lewat jadwal") and the student hears nothing
 * until Humas presses "Tandai dipublikasikan" (PublishPublication). STH makes no flyer (M3-E): the reminder promises
 * none, and a flyer file from before stays where it is. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = F::humas();
});

/** @param array<string, mixed> $state */
function em2Scheduled(array $state = [], array $student = [], string $name = 'Nadia Putri Anindya'): Publication
{
    return F::publication($student, array_merge([
        'status' => PublicationStatus::Terjadwal,
        'channels' => ['instagram', 'website'],
        'caption' => 'Selamat untuk Nadia!',
        'scheduled_at' => now()->subMinutes(3)->startOfMinute(),
    ], $state), $name);
}

/** Puts the student of $publication into a state in which the student may no longer be named. */
function em2HideStudent(Publication $publication, string $state): void
{
    $student = $publication->achievement()->firstOrFail()->student()->firstOrFail();
    $user = $student->user()->firstOrFail();

    match ($state) {
        'consent withdrawn' => $student->forceFill(['publish_consent' => false])->save(),
        'disabled' => $user->forceFill(['status' => UserStatus::Disabled])->save(),
        'deleted account' => $user->delete(),
        'deleted student' => $student->delete(),
    };
}

it('keeps the reminder time in its own column that is not mass-assignable', function (): void {
    $publication = em2Scheduled();

    expect(Schema::hasColumn('publications', 'reminded_at'))->toBeTrue()
        ->and($publication->reminded_at)->toBeNull()
        ->and(fn () => Publication::query()->create(['achievement_id' => $publication->achievement_id, 'reminded_at' => now()]))
        ->toThrow(MassAssignmentException::class);
});

it('reminds Humas when the scheduled time arrives, publishes nothing and tells the student nothing', function (): void {
    $publication = em2Scheduled();
    $scheduledAt = $publication->scheduled_at;
    $student = $publication->achievement->student->user;
    $now = CarbonImmutable::now();

    $result = app(RemindDuePublication::class)->handle($publication, $now);

    $publication->refresh();
    expect($result)->not->toBeNull()
        ->and($publication->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->published_at)->toBeNull()
        ->and($publication->reminded_at?->equalTo($now->startOfSecond()))->toBeTrue()
        ->and($publication->scheduled_at->equalTo($scheduledAt))->toBeTrue()
        ->and($publication->channels)->toBe(['instagram', 'website'])
        ->and($publication->caption)->toBe('Selamat untuk Nadia!')
        ->and($publication->isOverdue())->toBeTrue();

    Notification::assertSentTo($this->humas, PublicationDue::class, function (PublicationDue $n) use ($publication): bool {
        $data = $n->toArray($this->humas);

        return $n->publicationId === $publication->getKey()
            && $data['kind'] === 'publication.due'
            && str_contains($data['body'], 'Instagram')
            && str_contains($data['url'], 'pilih='.$publication->getKey());
    });
    Notification::assertNotSentTo($student, AchievementPublished::class);

    $audit = Activity::query()->where('event', 'publication.reminded')->sole();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->log_name)->toBe('publication')
        ->and($audit->subject_id)->toBe($publication->getKey())
        ->and($audit->properties['by'])->toBe('scheduler')
        ->and($audit->properties['achievement_id'])->toBe($publication->achievement_id)
        ->and(Activity::query()->where('event', 'publication.published')->count())->toBe(0);
});

it('reminds exactly once per scheduled time', function (): void {
    $publication = em2Scheduled();

    expect(app(RemindDuePublication::class)->handle($publication))->not->toBeNull()
        ->and(RemindDuePublication::due(CarbonImmutable::now())->count())->toBe(0)
        ->and(app(RemindDuePublication::class)->handle($publication->getKey()))->toBeNull();

    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));

    Notification::assertSentToTimes($this->humas, PublicationDue::class, 1);
    expect(Activity::query()->where('event', 'publication.reminded')->count())->toBe(1)
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal);
});

it('leaves future, unscheduled and already published rows alone', function (): void {
    $future = em2Scheduled(['scheduled_at' => now()->addHour()]);
    $ready = F::publication([], ['status' => PublicationStatus::Siap, 'channels' => ['instagram']], 'Dimas Arya Nugroho');
    $done = F::publication([], ['status' => PublicationStatus::Dipublikasikan, 'channels' => ['website'], 'published_at' => now()->subDay(), 'scheduled_at' => now()->subDays(2)], 'Keisha Larasati');

    $now = CarbonImmutable::now();
    expect(RemindDuePublication::due($now)->count())->toBe(0);

    foreach ([$future, $ready, $done] as $publication) {
        expect(app(RemindDuePublication::class)->handle($publication, $now))->toBeNull()
            ->and($publication->fresh()->reminded_at)->toBeNull();
    }

    expect($future->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($future->fresh()->isOverdue())->toBeFalse()
        ->and($ready->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and($done->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($done->fresh()->isOverdue())->toBeFalse()
        ->and(app(RemindDuePublication::class)->handle('01JZZZZZZZZZZZZZZZZZZZZZZZ', $now))->toBeNull();
    Notification::assertNothingSent();
});

it('skips a student who may not be named and mails nobody', function (string $state): void {
    $publication = em2Scheduled();
    em2HideStudent($publication, $state);

    expect(RemindDuePublication::due(CarbonImmutable::now())->pluck('id')->all())->not->toContain($publication->getKey())
        ->and(app(RemindDuePublication::class)->handle($publication->getKey()))->toBeNull()
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->reminded_at)->toBeNull()
        ->and(PublicationPolicy::eligible($publication->fresh()))->toBeFalse()
        ->and(Activity::query()->where('event', 'publication.reminded')->count())->toBe(0);

    Notification::assertNothingSent();
})->with([
    'consent withdrawn' => ['consent withdrawn'],
    'disabled account' => ['disabled'],
    'deleted account' => ['deleted account'],
    'deleted student record' => ['deleted student'],
]);

it('still reminds for an alumnus with consent, and the row stays scheduled', function (): void {
    $publication = em2Scheduled();
    $alumnus = $publication->achievement->student->user;
    $alumnus->forceFill(['status' => UserStatus::Archived])->save();

    expect(app(RemindDuePublication::class)->handle($publication->getKey()))->not->toBeNull()
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->reminded_at)->not->toBeNull();

    Notification::assertSentToTimes($this->humas, PublicationDue::class, 1);
    Notification::assertNotSentTo($alumnus, AchievementPublished::class);
});

it('runs every due publication from the scheduled job, reminding only active Humas accounts', function (): void {
    $disabled = AuthTestHelpers::staff(RoleName::Humas, ['email' => 'humas.nonaktif@example.test']);
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $kesiswaan = AuthTestHelpers::staff(RoleName::Kesiswaan);
    $admin = AuthTestHelpers::staff(RoleName::Admin);

    $first = em2Scheduled();
    $second = em2Scheduled(['scheduled_at' => now()->subMinute()->startOfMinute()], [], 'Keisha Larasati');
    $later = em2Scheduled(['scheduled_at' => now()->addDay()], [], 'Dimas Arya Nugroho');

    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));

    expect($first->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($first->fresh()->reminded_at)->not->toBeNull()
        ->and($second->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($second->fresh()->reminded_at)->not->toBeNull()
        ->and($later->fresh()->reminded_at)->toBeNull();

    Notification::assertSentToTimes($this->humas, PublicationDue::class, 2);
    Notification::assertNotSentTo($disabled, PublicationDue::class);
    Notification::assertNotSentTo($kesiswaan, PublicationDue::class);
    Notification::assertNotSentTo($admin, PublicationDue::class);

    foreach ([$first, $second, $later] as $publication) {
        Notification::assertNotSentTo($publication->achievement->student->user, AchievementPublished::class);
    }
});

it('stamps the reminder even when no Humas account can be told, so a later account is not mailed late', function (): void {
    $this->humas->forceFill(['status' => UserStatus::Disabled])->save();
    $publication = em2Scheduled();

    expect(app(RemindDuePublication::class)->handle($publication))->not->toBeNull()
        ->and($publication->fresh()->reminded_at)->not->toBeNull()
        ->and($publication->fresh()->isOverdue())->toBeTrue();

    Notification::assertNothingSent();
});

it('gives a new time its own reminder after "Jadwalkan ulang"', function (): void {
    $publication = em2Scheduled();
    app(RemindDuePublication::class)->handle($publication);
    expect($publication->fresh()->reminded_at)->not->toBeNull();

    $later = CarbonImmutable::now()->addDays(2)->setTime(9, 0);
    app(SchedulePublication::class)->handle($this->humas, $publication->fresh(), 'Selamat untuk Nadia!', ['instagram'], $later);

    $fresh = $publication->fresh();
    expect($fresh->reminded_at)->toBeNull()
        ->and($fresh->status)->toBe(PublicationStatus::Terjadwal)
        ->and($fresh->isOverdue())->toBeFalse()
        ->and(Activity::query()->where('event', 'publication.rescheduled')->count())->toBe(1)
        ->and(RemindDuePublication::due(CarbonImmutable::now())->count())->toBe(0)
        // Not yet: the new time has not arrived.
        ->and(app(RemindDuePublication::class)->handle($fresh))->toBeNull();

    $this->travelTo($later->addMinute());
    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));
    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));

    Notification::assertSentToTimes($this->humas, PublicationDue::class, 2);
    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal);
});

it('clears the reminder mark on the first schedule too', function (): void {
    $publication = F::publication();
    $publication->forceFill(['reminded_at' => now()->subDay()])->save();

    app(SchedulePublication::class)->handle($this->humas, $publication->fresh(), 'Selamat!', ['website'], CarbonImmutable::now()->addDay());

    expect($publication->fresh()->reminded_at)->toBeNull()
        ->and(Activity::query()->where('event', 'publication.scheduled')->count())->toBe(1);
});

it('tells the student only when Humas marks the publication as published', function (): void {
    $publication = F::publication();
    $student = $publication->achievement->student->user;
    $at = CarbonImmutable::now()->addHour()->startOfMinute();

    app(SchedulePublication::class)->handle($this->humas, $publication, 'Selamat untuk Nadia!', ['instagram', 'website'], $at);
    Notification::assertNothingSent();

    // The time arrives: Humas is reminded, the student is not told and nothing is published.
    $this->travelTo($at->addMinutes(2));
    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));

    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->published_at)->toBeNull();
    Notification::assertSentToTimes($this->humas, PublicationDue::class, 1);
    Notification::assertNotSentTo($student, AchievementPublished::class);

    // Humas posts it the next morning and presses "Tandai dipublikasikan".
    $pressed = $at->addDay()->setTime(8, 15);
    $this->travelTo($pressed);
    app(PublishPublication::class)->handle($this->humas, $publication->fresh());

    $fresh = $publication->fresh();
    expect($fresh->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($fresh->published_at->equalTo($pressed))->toBeTrue()
        ->and($fresh->published_at->equalTo($at))->toBeFalse()
        ->and($fresh->isOverdue())->toBeFalse();
    Notification::assertSentToTimes($student, AchievementPublished::class, 1);

    $published = Activity::query()->where('event', 'publication.published')->sole();
    expect((int) $published->causer_id)->toBe((int) $this->humas->getKey())
        ->and($published->properties['from'])->toBe('terjadwal');

    // Nothing more from the scheduler.
    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));
    Notification::assertSentToTimes($this->humas, PublicationDue::class, 1);
    Notification::assertSentToTimes($student, AchievementPublished::class, 1);
});

it('reminds for a scheduled row without a channel, which still cannot be published without one', function (): void {
    $publication = em2Scheduled(['channels' => []], [], 'Rizky Maulana');

    expect(RemindDuePublication::due(CarbonImmutable::now())->pluck('id')->all())->toBe([$publication->getKey()])
        ->and(app(RemindDuePublication::class)->handle($publication))->not->toBeNull();

    Notification::assertSentTo($this->humas, PublicationDue::class, fn (PublicationDue $n): bool => $n->channels === ''
        && str_contains($n->body(), 'Upload publikasinya sekarang.')
        && ! str_contains($n->body(), ' ke '));

    expect(fn () => app(PublishPublication::class)->handle($this->humas, $publication->fresh()))
        ->toThrow(ValidationException::class, 'Pilih minimal satu kanal.');
    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal);
});

it('lets only Humas mark an overdue publication as published', function (RoleName $role): void {
    $publication = em2Scheduled();
    app(RemindDuePublication::class)->handle($publication);

    expect(fn () => app(PublishPublication::class)->handle(AuthTestHelpers::staff($role), $publication->fresh()))
        ->toThrow(AuthorizationException::class);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal);
    Notification::assertNotSentTo($publication->achievement->student->user, AchievementPublished::class);
})->with([RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Kesiswaan]);

it('never lets a student mark a publication as published', function (): void {
    $publication = em2Scheduled();
    $student = $publication->achievement->student->user;

    expect(fn () => app(PublishPublication::class)->handle($student, $publication))
        ->toThrow(AuthorizationException::class);
    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal);
});

it('says in the reminder that the student is told only after "Tandai dipublikasikan" (no flyer from the app, M3-E)', function (): void {
    $publication = em2Scheduled();

    $reminder = PublicationDue::for($publication);
    $body = $reminder->body();
    $mail = (string) $reminder->toMail($this->humas)->render();

    expect($reminder->title())->toBe('Waktunya upload publikasi')
        ->and($body)->toStartWith('Jadwal '.$publication->scheduled_at->locale('id')->translatedFormat('j M Y, H.i').' sudah tiba: Juara II')
        ->toContain('OPSI 2026 (Nadia Putri Anindya).')
        ->toContain('Upload publikasinya ke Instagram, Website sekarang.')
        ->toContain('Setelah itu tekan Tandai dipublikasikan di halaman publikasi: siswa baru diberi tahu saat itu.')
        ->toContain('Caption dan data untuk flyer ada di halaman publikasi.')
        ->not->toContain('Statusnya kini Dipublikasikan')
        ->not->toContain('Upload caption dan flyer')
        ->and($reminder->toArray($this->humas)['body'])->toBe($body)
        ->and($reminder->toArray($this->humas)['kind'])->toBe('publication.due')
        ->and($mail)->toContain('data untuk flyer')
        ->toContain('Tandai dipublikasikan')
        ->toContain('pilih='.$publication->getKey());
});

it('promises no student notice in the reminder for an alumnus, whom "Tandai dipublikasikan" does not tell (D-109)', function (): void {
    // An archived account cannot log in, so PublishPublication notifies nobody: the reminder must not say it will.
    $publication = em2Scheduled();
    $publication->achievement->student->user->forceFill(['status' => UserStatus::Archived])->save();

    app(RemindDuePublication::class)->handle($publication->getKey());

    $reminder = Notification::sent($this->humas, PublicationDue::class)->sole();
    $body = $reminder->body();

    expect(PublishPublication::notifiesStudent($publication->fresh()))->toBeFalse()
        ->and($reminder->notifiesStudent)->toBeFalse()
        ->and($body)->toContain('Upload publikasinya ke Instagram, Website sekarang.')
        ->toContain('Setelah itu tekan Tandai dipublikasikan di halaman publikasi supaya statusnya menjadi Dipublikasikan.')
        ->toContain('Caption dan data untuk flyer ada di halaman publikasi.')
        ->not->toContain('diberi tahu')
        ->and($reminder->toArray($this->humas)['body'])->toBe($body)
        ->and((string) $reminder->toMail($this->humas)->render())
        ->toContain('supaya statusnya menjadi Dipublikasikan')
        ->not->toContain('siswa baru diberi tahu');
});

it('still reads a reminder that was queued before it knew whether the student is told', function (): void {
    // unserialize() skips the constructor: a payload from before `notifiesStudent` existed leaves it unset.
    $values = PublicationDue::for(em2Scheduled())->__serialize();
    unset($values['notifiesStudent']);

    $queued = (new ReflectionClass(PublicationDue::class))->newInstanceWithoutConstructor();
    $queued->__unserialize($values);

    expect($queued->body())
        ->toContain('Setelah itu tekan Tandai dipublikasikan di halaman publikasi: siswa baru diberi tahu saat itu.');
});

it('creates no file when reminding and leaves a flyer from before M3-E on the private disk', function (): void {
    $publication = em2Scheduled();
    $flyer = F::legacyFlyer($publication, $this->humas);

    app(RemindDuePublication::class)->handle($publication);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->flyer_file_id)->toBe($flyer->getKey())
        ->and($flyer->fresh()->disk)->toBe('local')
        ->and(File::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([$flyer->path])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('seeds the demo publications so that a fresh local stack mails nobody by itself', function (): void {
    app()['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';
    Notification::fake();

    $scheduled = Publication::query()->where('status', PublicationStatus::Terjadwal)->orderBy('scheduled_at')->get();

    // The two scheduled demo rows have a fixed time in the past: they show as "Lewat jadwal" and count as reminded.
    expect($scheduled)->toHaveCount(2)
        ->and($scheduled->every(fn (Publication $p): bool => $p->scheduled_at->isPast()
            && $p->reminded_at !== null
            && $p->reminded_at->equalTo($p->scheduled_at)
            && $p->isOverdue()))->toBeTrue()
        ->and(Publication::query()->where('status', '!=', PublicationStatus::Terjadwal->value)->whereNotNull('reminded_at')->count())->toBe(0)
        ->and(RemindDuePublication::due(CarbonImmutable::now())->count())->toBe(0);

    app(RemindDuePublications::class)->handle(app(RemindDuePublication::class));

    Notification::assertNothingSent();
    expect(Publication::query()->where('status', PublicationStatus::Terjadwal)->count())->toBe(2)
        ->and(Activity::query()->where('event', 'publication.reminded')->count())->toBe(0);
});

it('knows an overdue row in the model and in the query the same way', function (): void {
    $now = CarbonImmutable::now()->startOfMinute();
    $exactly = em2Scheduled(['scheduled_at' => $now]);
    $past = em2Scheduled(['scheduled_at' => $now->subDay()], [], 'Keisha Larasati');
    $noTime = em2Scheduled(['scheduled_at' => null], [], 'Rizky Maulana');
    $future = em2Scheduled(['scheduled_at' => $now->addMinute()], [], 'Dimas Arya Nugroho');
    $ready = F::publication([], ['scheduled_at' => $now->subDay()], 'Salsabila Kirana');

    expect($exactly->isOverdue($now))->toBeTrue()
        ->and($past->isOverdue($now))->toBeTrue()
        ->and($noTime->isOverdue($now))->toBeTrue()
        ->and($future->isOverdue($now))->toBeFalse()
        ->and($ready->isOverdue($now))->toBeFalse()
        ->and(Publication::query()->overdue($now)->orderBy('id')->pluck('id')->all())
        ->toBe(collect([$exactly, $past, $noTime])->map->getKey()->sort()->values()->all());
});
