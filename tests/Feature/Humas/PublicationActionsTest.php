<?php

declare(strict_types=1);

use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\SchedulePublication;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Models\Publication;
use App\Notifications\Publications\AchievementPublished;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| SchedulePublication + PublishPublication (W2-C).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

function humasSchedulePublication(Publication $publication, array $overrides = []): Publication
{
    $args = array_merge([
        'actor' => test()->humas,
        'caption' => 'Selamat kepada Nadia Putri Anindya (XI-4).',
        'channels' => ['instagram', 'website'],
        'at' => CarbonImmutable::now()->addDay()->setTime(9, 0),
    ], $overrides);

    return app(SchedulePublication::class)->handle($args['actor'], $publication, $args['caption'], $args['channels'], $args['at']);
}

// --- SchedulePublication -------------------------------------------------------------------

it('schedules a ready publication', function (): void {
    $publication = HumasFixtures::publication();
    $at = CarbonImmutable::now()->addDays(2)->setTime(9, 0);

    humasSchedulePublication($publication, ['at' => $at, 'channels' => ['website', 'instagram', 'instagram']]);

    $fresh = $publication->fresh();
    expect($fresh->status)->toBe(PublicationStatus::Terjadwal)
        ->and($fresh->channels)->toBe(['instagram', 'website'])
        ->and($fresh->caption)->toBe('Selamat kepada Nadia Putri Anindya (XI-4).')
        ->and($fresh->scheduled_at->equalTo($at))->toBeTrue()
        ->and((int) $fresh->created_by)->toBe((int) $this->humas->getKey())
        ->and($fresh->published_at)->toBeNull();

    $log = Activity::query()->where('event', 'publication.scheduled')->sole();
    expect($log->log_name)->toBe('publication')
        ->and((int) $log->causer_id)->toBe((int) $this->humas->getKey())
        ->and($log->subject_id)->toBe($publication->getKey());

    Notification::assertNothingSent();
});

it('re-schedules a scheduled publication', function (): void {
    $publication = HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now()->addDay(),
        'channels' => ['instagram'],
        'caption' => 'Lama',
    ]);
    $at = CarbonImmutable::now()->addDays(5)->setTime(10, 30);

    humasSchedulePublication($publication, ['at' => $at, 'channels' => ['mading']]);

    expect($publication->fresh()->scheduled_at->equalTo($at))->toBeTrue()
        ->and($publication->fresh()->channels)->toBe(['mading'])
        ->and(Activity::query()->where('event', 'publication.rescheduled')->count())->toBe(1);
});

it('refuses to schedule a published publication', function (): void {
    $publication = HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()]);

    expect(fn () => humasSchedulePublication($publication))->toThrow(AuthorizationException::class);
});

it('refuses to schedule without publish consent', function (): void {
    $publication = HumasFixtures::publication(['publish_consent' => false]);

    expect(fn () => humasSchedulePublication($publication))->toThrow(AuthorizationException::class);
    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
});

it('refuses other roles', function (RoleName $role): void {
    $publication = HumasFixtures::publication();

    expect(fn () => humasSchedulePublication($publication, ['actor' => AuthTestHelpers::staff($role)]))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('validates caption, channels and time', function (array $overrides, string $key): void {
    $publication = HumasFixtures::publication();

    try {
        humasSchedulePublication($publication, $overrides);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
})->with([
    'empty caption' => [['caption' => "   \n "], 'caption'],
    'caption too long' => [['caption' => str_repeat('a', 2001)], 'caption'],
    'no channel' => [['channels' => []], 'channels'],
    'unknown channel' => [['channels' => ['tiktok']], 'channels'],
    'time in the past' => [['at' => CarbonImmutable::now()->subHour()], 'scheduled_at'],
    'too far ahead' => [['at' => CarbonImmutable::now()->addYears(2)], 'scheduled_at'],
]);

// --- PublishPublication --------------------------------------------------------------------

it('publishes a ready publication and notifies the student', function (): void {
    $publication = HumasFixtures::publication();
    $student = $publication->achievement->student->user;

    app(PublishPublication::class)->handle($this->humas, $publication, 'Selamat, Nadia!', ['instagram']);

    $fresh = $publication->fresh();
    expect($fresh->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($fresh->published_at)->not->toBeNull()
        ->and($fresh->channels)->toBe(['instagram'])
        ->and($fresh->caption)->toBe('Selamat, Nadia!')
        ->and(Activity::query()->where('event', 'publication.published')->count())->toBe(1);

    Notification::assertSentTo($student, AchievementPublished::class, function (AchievementPublished $n) use ($publication): bool {
        return $n->publicationId === $publication->getKey()
            && $n->competitionName === 'OPSI 2026'
            && $n->channels === 'Instagram';
    });
});

it('publishes a scheduled publication keeping its channels', function (): void {
    $publication = HumasFixtures::publication(publicationState: [
        'status' => PublicationStatus::Terjadwal,
        'scheduled_at' => now()->addDay(),
        'channels' => ['instagram', 'website'],
        'caption' => 'Terjadwal',
    ]);

    app(PublishPublication::class)->handle($this->humas, $publication);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->fresh()->channels)->toBe(['instagram', 'website'])
        ->and($publication->fresh()->caption)->toBe('Terjadwal');
});

it('refuses to publish twice', function (): void {
    $publication = HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDay()]);

    expect(fn () => app(PublishPublication::class)->handle($this->humas, $publication))->toThrow(AuthorizationException::class);
    Notification::assertNothingSent();
});

it('refuses a stale model that was published meanwhile', function (): void {
    $publication = HumasFixtures::publication();
    $stale = Publication::query()->findOrFail($publication->getKey());
    app(PublishPublication::class)->handle($this->humas, $publication, null, ['website']);

    expect(fn () => app(PublishPublication::class)->handle($this->humas, $stale, null, ['website']))
        ->toThrow(ValidationException::class, 'Publikasi ini sudah dipublikasikan.');
});

it('refuses to publish without publish consent or channels', function (): void {
    $noConsent = HumasFixtures::publication(['publish_consent' => false]);
    expect(fn () => app(PublishPublication::class)->handle($this->humas, $noConsent, null, ['instagram']))->toThrow(AuthorizationException::class);

    $noChannel = HumasFixtures::publication(studentName: 'Raka Pradipta');
    expect(fn () => app(PublishPublication::class)->handle($this->humas, $noChannel))->toThrow(ValidationException::class);
    expect($noChannel->fresh()->status)->toBe(PublicationStatus::Siap);

    Notification::assertNothingSent();
});

it('refuses other roles to publish', function (): void {
    $publication = HumasFixtures::publication();

    expect(fn () => app(PublishPublication::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $publication, null, ['instagram']))
        ->toThrow(AuthorizationException::class);
});
