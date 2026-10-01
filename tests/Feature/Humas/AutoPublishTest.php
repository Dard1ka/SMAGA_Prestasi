<?php

declare(strict_types=1);

use App\Actions\Publications\AutoPublishPublication;
use App\Actions\Publications\GenerateFlyer;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Jobs\AutoPublishPublications;
use App\Models\Publication;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\PublicationDue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures as F;

/*
 * B3 · backlog #12: terjadwal publications whose scheduled_at arrived become dipublikasikan
 * (AutoPublishPublication via the AutoPublishPublications job) and Humas is reminded to post.
 * Flyers stay on the private disk (lead decision). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = F::humas();
});

/** @param array<string, mixed> $state */
function b3Scheduled(array $state = [], array $student = [], string $name = 'Nadia Putri Anindya'): Publication
{
    return F::publication($student, array_merge([
        'status' => PublicationStatus::Terjadwal,
        'channels' => ['instagram', 'website'],
        'caption' => 'Selamat untuk Nadia!',
        'scheduled_at' => now()->subMinutes(3)->startOfMinute(),
    ], $state), $name);
}

it('publishes a due scheduled publication and reminds Humas and the student', function (): void {
    $publication = b3Scheduled();
    $scheduledAt = $publication->scheduled_at;

    $result = app(AutoPublishPublication::class)->handle($publication);

    $publication->refresh();
    expect($result)->not->toBeNull()
        ->and($publication->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($publication->published_at?->equalTo($scheduledAt))->toBeTrue()
        ->and($publication->channels)->toBe(['instagram', 'website'])
        ->and($publication->caption)->toBe('Selamat untuk Nadia!');

    Notification::assertSentTo($this->humas, PublicationDue::class, function (PublicationDue $n) use ($publication): bool {
        $data = $n->toArray($this->humas);

        return $n->publicationId === $publication->getKey()
            && $data['kind'] === 'publication.due'
            && str_contains($data['body'], 'Instagram')
            && str_contains($data['url'], 'pilih='.$publication->getKey());
    });
    Notification::assertSentTo($publication->achievement->student->user, AchievementPublished::class);

    $audit = Activity::query()->where('event', 'publication.published')->firstOrFail();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->properties['by'])->toBe('scheduler')
        ->and($audit->properties['from'])->toBe('terjadwal');

    // Idempotent.
    expect(app(AutoPublishPublication::class)->handle($publication))->toBeNull();
    Notification::assertSentToTimes($this->humas, PublicationDue::class, 1);
});

it('leaves future, unscheduled and already published rows alone', function (): void {
    $future = b3Scheduled(['scheduled_at' => now()->addHour()]);
    $ready = F::publication([], ['status' => PublicationStatus::Siap, 'channels' => ['instagram']], 'Dimas Arya Nugroho');
    $done = F::publication([], ['status' => PublicationStatus::Dipublikasikan, 'channels' => ['website'], 'published_at' => now()->subDay()], 'Keisha Larasati');

    $now = CarbonImmutable::now();
    expect(AutoPublishPublication::due($now)->count())->toBe(0);

    foreach ([$future, $ready, $done] as $publication) {
        expect(app(AutoPublishPublication::class)->handle($publication, $now))->toBeNull();
    }

    expect($future->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($ready->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and($done->fresh()->published_at->lessThan($now->subHours(12)))->toBeTrue();
    Notification::assertNothingSent();
});

it('skips a publication whose student withdrew consent or that has no channel', function (): void {
    $withdrawn = b3Scheduled([], ['publish_consent' => false]);
    $noChannel = b3Scheduled(['channels' => []], [], 'Rizky Maulana');

    expect(AutoPublishPublication::due(CarbonImmutable::now())->pluck('id')->all())->toBe([$noChannel->getKey()]);

    expect(app(AutoPublishPublication::class)->handle($withdrawn))->toBeNull()
        ->and(app(AutoPublishPublication::class)->handle($noChannel))->toBeNull()
        ->and($withdrawn->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($noChannel->fresh()->status)->toBe(PublicationStatus::Terjadwal);
    Notification::assertNothingSent();
});

it('runs every due publication from the scheduled job, reminding only active Humas accounts', function (): void {
    $disabled = AuthTestHelpers::staff(RoleName::Humas, ['email' => 'humas.nonaktif@example.test']);
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $kesiswaan = AuthTestHelpers::staff(RoleName::Kesiswaan);

    $first = b3Scheduled();
    $second = b3Scheduled(['scheduled_at' => now()->subMinute()->startOfMinute()], [], 'Keisha Larasati');
    $later = b3Scheduled(['scheduled_at' => now()->addDay()], [], 'Dimas Arya Nugroho');

    app(AutoPublishPublications::class)->handle(app(AutoPublishPublication::class));

    expect($first->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($second->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($later->fresh()->status)->toBe(PublicationStatus::Terjadwal);

    Notification::assertSentToTimes($this->humas, PublicationDue::class, 2);
    Notification::assertNotSentTo($disabled, PublicationDue::class);
    Notification::assertNotSentTo($kesiswaan, PublicationDue::class);
});

it('keeps the flyer on the private disk when publishing', function (): void {
    $publication = b3Scheduled();
    $flyer = app(GenerateFlyer::class)->handle($this->humas, $publication, 'biru');

    app(AutoPublishPublication::class)->handle($publication);

    expect($publication->fresh()->flyer_file_id)->toBe($flyer->getKey())
        ->and($flyer->fresh()->disk)->toBe('local');
});
