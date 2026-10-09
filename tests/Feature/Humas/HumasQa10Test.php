<?php

declare(strict_types=1);

use App\Actions\Publications\AutoPublishPublication;
use App\Enums\PublicationStatus;
use App\Enums\UserStatus;
use App\Livewire\Humas\HumasQueries;
use App\Livewire\Humas\Publikasi;
use App\Models\Publication;
use App\Policies\PublicationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
 * QA10: the Humas side follows the "named" rule of the public pages (QA9: publish consent, student and account not
 * deleted, account not disabled; an alumnus stays named). Before, the auto-publish job published a disabled student's
 * scheduled card and mailed every Humas "upload sekarang" with the name, while the public pages hid that student
 * (D-30). The `?pilih=` link of that mail answered a full-screen 404 once the card left the queue. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

/** A due terjadwal publication of a consenting student. */
function qa10NoticeScheduled(string $name = 'Nadia Putri Anindya'): Publication
{
    return HumasFixtures::publication([], [
        'status' => PublicationStatus::Terjadwal,
        'channels' => ['instagram', 'website'],
        'caption' => 'Selamat untuk '.$name.'!',
        'scheduled_at' => now()->subMinutes(3)->startOfMinute(),
    ], $name);
}

/** Puts the student of $publication into the given state. */
function qa10NoticeHideStudent(Publication $publication, string $state): void
{
    $student = $publication->achievement()->firstOrFail()->student()->firstOrFail();
    $user = $student->user()->firstOrFail();

    match ($state) {
        'disabled' => $user->forceFill(['status' => UserStatus::Disabled])->save(),
        'deleted account' => $user->delete(),
        'deleted student' => $student->delete(),
    };
}

it('does not auto-publish a hidden student and mails nobody (QA10)', function (string $state): void {
    $publication = qa10NoticeScheduled();
    qa10NoticeHideStudent($publication, $state);

    expect(AutoPublishPublication::due(CarbonImmutable::now())->pluck('id')->all())->not->toContain($publication->getKey())
        ->and(app(AutoPublishPublication::class)->handle($publication->getKey()))->toBeNull()
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and(PublicationPolicy::eligible($publication->fresh()))->toBeFalse();

    Notification::assertNothingSent();
})->with(['disabled account' => ['disabled'], 'deleted account' => ['deleted account'], 'deleted student record' => ['deleted student']]);

it('keeps a hidden student out of the Humas queue and the badge (QA10)', function (string $state): void {
    $shown = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $hidden = HumasFixtures::publication(studentName: 'Fajar Ramadhan', competitionName: 'OPSI 2026');
    qa10NoticeHideStudent($hidden, $state);

    expect(HumasQueries::visiblePublications()->pluck('id')->all())->toBe([$shown->getKey()])
        ->and(HumasQueries::badges($this->humas)['humas.publikasi'])->toBe(1);

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Fajar Ramadhan');
})->with(['disabled account' => ['disabled'], 'deleted account' => ['deleted account']]);

it('still auto-publishes an alumnus with consent (QA10 guard)', function (): void {
    $publication = qa10NoticeScheduled();
    $publication->achievement->student->user->forceFill(['status' => UserStatus::Archived])->save();

    expect(app(AutoPublishPublication::class)->handle($publication->getKey()))->not->toBeNull()
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and(HumasQueries::visiblePublications()->whereKey($publication->getKey())->exists())->toBeTrue();
});

it('answers an old ?pilih= link of a card that left the queue with a warning toast, not a 404 (QA10)', function (): void {
    $other = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $gone = HumasFixtures::publication(studentName: 'Fajar Ramadhan', competitionName: 'OPSI 2026');
    $gone->achievement->student->forceFill(['publish_consent' => false])->save();

    Livewire::withQueryParams(['pilih' => $gone->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Prestasi ini sudah tidak ada di antrean publikasi. Antrean sudah diperbarui.', tone: 'warning')
        ->assertSet('selectedId', $other->getKey())
        ->assertDontSee('Fajar Ramadhan');
});

it('still answers 404 for a ?pilih= id that is no publication at all (QA10 guard)', function (): void {
    HumasFixtures::publication();

    Livewire::withQueryParams(['pilih' => (string) Str::ulid()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertNotFound();
});
