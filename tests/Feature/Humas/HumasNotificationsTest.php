<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Models\Appreciation;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationScheduled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Publications\{AchievementPublished, AppreciationScheduled}: queued (mail queue, after commit),
| database always + mail only with an email, no NIS/NISN/birth date in any channel.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    config(['fortify.sth.mail_queue' => 'mail']);
});

it('queues AchievementPublished on the mail queue after commit', function (): void {
    $publication = HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'channels' => ['instagram', 'website'], 'published_at' => now()]);
    $notification = AchievementPublished::for($publication);
    $student = $publication->achievement->student;
    $user = $student->user;

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('mail')
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($user))->toBe(['database', 'mail']);

    $user->email = null;
    expect($notification->via($user))->toBe(['database']);

    $mail = $notification->toMail($user)->render();
    $data = $notification->toArray($user);

    expect((string) $mail)->toContain('OPSI 2026')->toContain('Instagram, Website')->toContain('kamu')
        ->not->toContain($student->nis)->not->toContain($student->nisn)
        ->and($data['kind'])->toBe('publication.published')
        ->and($data['body'])->toContain('Juara II OPSI 2026')
        ->and(json_encode($data))->not->toContain($student->nis)->not->toContain($student->nisn);
});

it('queues AppreciationScheduled on the mail queue after commit', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event('Upacara Senin, 5 Oktober 2026', '2026-10-05');
    $appreciation = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $notification = AppreciationScheduled::for($appreciation->fresh());
    $student = $achievement->student;
    $user = $student->user;

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('mail')
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($user))->toBe(['database', 'mail'])
        ->and($notification->eventDate)->toBe('Senin, 5 Oktober 2026');

    $data = $notification->toArray($user);
    $mail = (string) $notification->toMail($user)->render();

    expect($data['kind'])->toBe('appreciation.scheduled')
        ->and($data['body'])->toContain('Upacara Senin, 5 Oktober 2026')->toContain('kamu')
        ->and($mail)->not->toContain($student->nis)->not->toContain($student->nisn)
        ->and(json_encode($data))->not->toContain($student->birth_date->format('Y-m-d'));
});
