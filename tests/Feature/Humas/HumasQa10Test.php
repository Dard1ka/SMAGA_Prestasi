<?php

declare(strict_types=1);

use App\Actions\Publications\RemindDuePublication;
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
 * QA10: the Humas side follows the "named" rule of the public pages (QA9: student and account not
 * deleted, account not disabled; an alumnus stays named). Before, the auto-publish job published a disabled student's
 * scheduled card and mailed every Humas "upload sekarang" with the name, while the public pages hid that student
 * (D-30). The `?pilih=` link of that mail answered a full-screen 404 once the card left the queue. Fictional data only.
 *
 * EM2 (lead, 2026-10-10; D-122): the job no longer publishes, it only reminds Humas (RemindDuePublication). The named
 * rule is unchanged: no reminder with the name of a hidden student; an alumnus is still reminded about (D-162: no consent).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

/** A due terjadwal publication of a student who may be named. */
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

it('does not remind Humas about a hidden student and mails nobody (QA10)', function (string $state): void {
    $publication = qa10NoticeScheduled();
    qa10NoticeHideStudent($publication, $state);

    expect(RemindDuePublication::due(CarbonImmutable::now())->pluck('id')->all())->not->toContain($publication->getKey())
        ->and(app(RemindDuePublication::class)->handle($publication->getKey()))->toBeNull()
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->reminded_at)->toBeNull()
        ->and(PublicationPolicy::eligible($publication->fresh()))->toBeFalse();

    Notification::assertNothingSent();
})->with(['disabled account' => ['disabled'], 'deleted account' => ['deleted account'], 'deleted student record' => ['deleted student']]);

it('keeps a hidden student out of the Humas queue and the badge (QA10)', function (string $state): void {
    $shown = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $hidden = HumasFixtures::publication(studentName: 'Fajar Ramadhan', competitionName: 'OPSI 2026');
    qa10NoticeHideStudent($hidden, $state);

    $hidden->forceFill(['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->subDay()])->save();

    // Neither count names the hidden card: not the queue, not the overdue schedule (D-122).
    expect(HumasQueries::visiblePublications()->pluck('id')->all())->toBe([$shown->getKey()])
        ->and(HumasQueries::badges($this->humas)['humas.publikasi'])->toBe(1)
        ->and(HumasQueries::badges($this->humas)['humas.jadwal'])->toBe(0);

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->assertSee('Raka Pradipta')
        ->assertDontSee('Fajar Ramadhan');
})->with(['disabled account' => ['disabled'], 'deleted account' => ['deleted account']]);

it('still reminds Humas about an alumnus (QA10 guard)', function (): void {
    $publication = qa10NoticeScheduled();
    $publication->achievement->student->user->forceFill(['status' => UserStatus::Archived])->save();

    expect(app(RemindDuePublication::class)->handle($publication->getKey()))->not->toBeNull()
        // D-122: reminded, not published. The card waits as "lewat jadwal" for "Tandai dipublikasikan".
        ->and($publication->fresh()->status)->toBe(PublicationStatus::Terjadwal)
        ->and($publication->fresh()->reminded_at)->not->toBeNull()
        ->and(HumasQueries::visiblePublications()->whereKey($publication->getKey())->exists())->toBeTrue()
        ->and(HumasQueries::overduePublications()->whereKey($publication->getKey())->exists())->toBeTrue();
});

it('answers an old ?pilih= link of a card that left the queue with a warning toast, not a 404 (QA10)', function (): void {
    $other = HumasFixtures::publication(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $gone = HumasFixtures::publication(studentName: 'Fajar Ramadhan', competitionName: 'OPSI 2026');
    $gone->achievement->student->user->forceFill(['status' => UserStatus::Disabled])->save();

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
