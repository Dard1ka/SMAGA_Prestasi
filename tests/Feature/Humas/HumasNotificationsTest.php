<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Models\Appreciation;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\AppreciationReleased;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Publications\{AchievementPublished, AppreciationScheduled, AppreciationCancelled}: queued (mail queue, after commit),
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

it('queues AppreciationCancelled on the mail queue after commit, with plain event text', function (): void {
    $achievement = HumasFixtures::achievement();
    // An event name is free text typed by staff: it must reach the mail as text, never as a link (D-69).
    $event = HumasFixtures::event('Upacara [klik](https://evil.example) Senin', '2026-10-05');
    $appreciation = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $notification = AppreciationCancelled::for($event, [$appreciation->fresh()]);
    $student = $achievement->student;
    $user = $student->user;

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('mail')
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($user))->toBe(['database', 'mail'])
        ->and($notification->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and($notification->achievements)->toBe(['Juara II OPSI 2026']);

    $data = $notification->toArray($user);
    $mail = (string) $notification->toMail($user)->render();

    expect($data['kind'])->toBe('appreciation.cancelled')
        ->and($data['title'])->toBe('Acara apresiasi dibatalkan')
        ->and($data['body'])->toContain('Senin, 5 Oktober 2026')->toContain('dibatalkan')->toContain('Prestasi kamu, Juara II OPSI 2026,')
        ->and($mail)->toContain('dibatalkan')
        ->not->toContain('href="https://evil.example')
        ->not->toContain($student->nis)->not->toContain($student->nisn)
        ->and(json_encode($data))->not->toContain($student->nis)->not->toContain($student->birth_date->format('Y-m-d'));

    $user->email = null;
    expect($notification->via($user))->toBe(['database']);

    $several = new AppreciationCancelled('Upacara Senin', 'Senin, 5 Oktober 2026', ['Juara II OPSI 2026', 'Juara I FIKSI 2026']);
    expect($several->body())->toContain('2 prestasi kamu belum jadi diapresiasi');
});

// M2-B: "Lepas dari acara" takes one achievement off an event that itself goes on.
it('queues AppreciationReleased on the mail queue after commit, with plain event text', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event('Upacara [klik](https://evil.example) Senin', '2026-10-05');
    $appreciation = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $notification = AppreciationReleased::for($event, $appreciation->fresh());
    $student = $achievement->student;
    $user = $student->user;

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->queue)->toBe('mail')
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($user))->toBe(['database', 'mail'])
        ->and($notification->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and($notification->achievement)->toBe('Juara II OPSI 2026');

    $data = $notification->toArray($user);
    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($mail)->toBeInstanceOf(PlainMailMessage::class)
        ->and($data['kind'])->toBe('appreciation.released')
        ->and($data['title'])->toBe('Jadwal apresiasi berubah')
        ->and($data['body'])->toContain('Prestasi kamu, Juara II OPSI 2026, tidak jadi diapresiasi di')
        ->toContain('Senin, 5 Oktober 2026')->toContain('bisa dijadwalkan lagi di acara lain')
        // The event goes on: this is not the "acara dibatalkan" notice.
        ->not->toContain('dibatalkan')
        ->and($html)->toContain('tidak jadi diapresiasi')
        ->not->toContain('href="https://evil.example')
        ->not->toContain($student->nis)->not->toContain($student->nisn)
        ->and(json_encode($data))->not->toContain($student->nis)->not->toContain($student->birth_date->format('Y-m-d'));

    $user->email = null;
    expect($notification->via($user))->toBe(['database']);
});
