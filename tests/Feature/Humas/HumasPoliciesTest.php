<?php

declare(strict_types=1);

use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Publication;
use App\Models\Student;
use App\Policies\PublicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| PublicationPolicy, AppreciationPolicy, AppreciationEventPolicy: deny by default (W2-C; `delete` M1-E).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
});

it('lets only publication.manage holders see the queue', function (RoleName $role, bool $allowed): void {
    $user = AuthTestHelpers::staff($role);
    $publication = HumasFixtures::publication();

    expect($user->can('viewAny', Publication::class))->toBe($allowed)
        ->and($user->can('view', $publication))->toBe($allowed)
        ->and($user->can('schedule', $publication))->toBe($allowed)
        ->and($user->can('publish', $publication))->toBe($allowed);
})->with([
    'humas' => [RoleName::Humas, true],
    'admin' => [RoleName::Admin, true],
    'kesiswaan' => [RoleName::Kesiswaan, false],
    'guru' => [RoleName::Guru, false],
    'kepala_sekolah' => [RoleName::KepalaSekolah, false],
    'tata_usaha' => [RoleName::TataUsaha, false],
]);

it('denies students', function (): void {
    $student = Student::factory()->activated()->create();
    $user = $student->user()->first()->fresh();
    $publication = HumasFixtures::publication();

    expect($user->can('viewAny', Publication::class))->toBeFalse()
        ->and($user->can('publish', $publication))->toBeFalse()
        ->and($user->can('viewAny', AppreciationEvent::class))->toBeFalse();
});

it('denies a disabled Humas account', function (): void {
    $humas = HumasFixtures::humas();
    $humas->forceFill(['status' => UserStatus::Disabled])->save();
    $publication = HumasFixtures::publication();

    expect($humas->can('viewAny', Publication::class))->toBeFalse()
        ->and($humas->can('publish', $publication))->toBeFalse()
        ->and($humas->can('viewAny', AppreciationEvent::class))->toBeFalse();
});

it('blocks changes when the student\'s account is disabled', function (): void {
    $humas = HumasFixtures::humas();
    $publication = HumasFixtures::publication(disabled: true);

    expect($humas->can('view', $publication))->toBeTrue()
        ->and($humas->can('update', $publication))->toBeFalse()
        ->and($humas->can('schedule', $publication))->toBeFalse()
        ->and($humas->can('publish', $publication))->toBeFalse();
});

it('allows changes whatever the old consent columns say (D-162)', function (): void {
    $humas = HumasFixtures::humas();
    $publication = HumasFixtures::publication(['publish_consent' => false, 'photo_consent' => false]);

    expect($humas->can('update', $publication))->toBeTrue()
        ->and($humas->can('schedule', $publication))->toBeTrue()
        ->and($humas->can('publish', $publication))->toBeTrue();
});

it('blocks changes when the achievement is not verified', function (): void {
    $humas = HumasFixtures::humas();
    $publication = HumasFixtures::publication(verified: false);

    expect($humas->can('schedule', $publication))->toBeFalse()
        ->and($humas->can('publish', $publication))->toBeFalse();
});

it('follows the publication status', function (PublicationStatus $status, bool $schedule, bool $publish): void {
    $humas = HumasFixtures::humas();
    $publication = HumasFixtures::publication(publicationState: [
        'status' => $status,
        'published_at' => $status === PublicationStatus::Dipublikasikan ? now() : null,
    ]);

    expect($humas->can('schedule', $publication))->toBe($schedule)
        ->and($humas->can('update', $publication))->toBe($schedule)
        ->and($humas->can('publish', $publication))->toBe($publish);
})->with([
    'siap' => [PublicationStatus::Siap, true, true],
    'terjadwal' => [PublicationStatus::Terjadwal, true, true],
    'dipublikasikan' => [PublicationStatus::Dipublikasikan, false, false],
]);

// M3-E: Humas makes its flyers outside STH. The ability is gone, so asking for it is denied for every role.
it('has no ability left to generate a flyer', function (RoleName $role): void {
    $user = AuthTestHelpers::staff($role);
    $publication = HumasFixtures::publication();

    expect(method_exists(PublicationPolicy::class, 'generateFlyer'))->toBeFalse()
        ->and($user->can('generateFlyer', $publication))->toBeFalse()
        ->and($user->can('view', $publication))->toBe($role !== RoleName::Kesiswaan);
})->with([RoleName::Humas, RoleName::Admin, RoleName::Kesiswaan]);

it('gives appreciation abilities to appreciation.manage holders only', function (RoleName $role, bool $allowed): void {
    $user = AuthTestHelpers::staff($role);
    $event = HumasFixtures::event();
    $appreciation = Appreciation::factory()->create(['appreciation_event_id' => $event->getKey()]);

    expect($user->can('viewAny', AppreciationEvent::class))->toBe($allowed)
        ->and($user->can('view', $event))->toBe($allowed)
        ->and($user->can('create', AppreciationEvent::class))->toBe($allowed)
        ->and($user->can('update', $event))->toBe($allowed)
        ->and($user->can('delete', $event))->toBe($allowed)
        ->and($user->can('viewAny', Appreciation::class))->toBe($allowed)
        ->and($user->can('view', $appreciation))->toBe($allowed)
        ->and($user->can('create', Appreciation::class))->toBe($allowed)
        ->and($user->can('update', $appreciation))->toBe($allowed)
        // "Lepas dari acara" (M2-B): a scheduled row of an event that is not confirmed.
        ->and($user->can('delete', $appreciation))->toBe($allowed);
})->with([
    'humas' => [RoleName::Humas, true],
    'kesiswaan' => [RoleName::Kesiswaan, true],
    'admin' => [RoleName::Admin, true],
    'guru' => [RoleName::Guru, false],
    'kepala_sekolah' => [RoleName::KepalaSekolah, false],
    'tata_usaha' => [RoleName::TataUsaha, false],
]);

it('lets nobody delete an event that is confirmed as held (M1-E)', function (RoleName $role): void {
    $user = AuthTestHelpers::staff($role);
    $event = HumasFixtures::event('Upacara lalu', now()->subWeek()->toDateString());

    expect($user->can('delete', $event))->toBeTrue();

    $event->forceFill(['completed_at' => now()->subDays(6)])->save();

    expect($user->can('delete', $event->fresh()))->toBeFalse()
        ->and($user->can('view', $event->fresh()))->toBeTrue()
        ->and($user->can('update', $event->fresh()))->toBeTrue();
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('lets nobody release a row that is appreciated or sits on a confirmed event (M2-B)', function (RoleName $role): void {
    $user = AuthTestHelpers::staff($role);
    $event = HumasFixtures::event('Upacara lalu', now()->subWeek()->toDateString());
    $scheduled = Appreciation::factory()->create(['appreciation_event_id' => $event->getKey()]);
    $appreciated = Appreciation::factory()->create(['appreciation_event_id' => $event->getKey(), 'status' => AppreciationStatus::Selesai]);

    expect($user->can('delete', $scheduled))->toBeTrue()
        ->and($user->can('delete', $appreciated))->toBeFalse();

    $event->forceFill(['completed_at' => now()->subDays(6)])->save();

    expect($user->can('delete', $scheduled->fresh()))->toBeFalse()
        ->and($user->can('update', $scheduled->fresh()))->toBeTrue();
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('denies releasing a row to students and disabled accounts', function (): void {
    $appreciation = Appreciation::factory()->create(['appreciation_event_id' => HumasFixtures::event()->getKey()]);
    $student = Student::factory()->activated()->create()->user()->first()->fresh();
    $humas = HumasFixtures::humas();
    $humas->forceFill(['status' => UserStatus::Disabled])->save();

    expect($student->can('delete', $appreciation))->toBeFalse()
        ->and($humas->can('delete', $appreciation))->toBeFalse();
});

it('denies deleting an event to students and disabled accounts', function (): void {
    $event = HumasFixtures::event();
    $student = Student::factory()->activated()->create()->user()->first()->fresh();
    $humas = HumasFixtures::humas();
    $humas->forceFill(['status' => UserStatus::Disabled])->save();

    expect($student->can('delete', $event))->toBeFalse()
        ->and($humas->can('delete', $event))->toBeFalse();
});
