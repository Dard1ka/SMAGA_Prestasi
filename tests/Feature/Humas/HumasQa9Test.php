<?php

declare(strict_types=1);

use App\Actions\Publications\AutoPublishPublication;
use App\Actions\Publications\DeleteAppreciationEvent;
use App\Actions\Publications\PublishPublication;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Publikasi;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
 * QA9: Humas screens open while something else changed the row. Publikasi: the auto-publish job (every 5 min) or a
 * second tab published the selected card → 403 (PublicationPolicy before the Action's "sudah dipublikasikan"); Admin
 * withdrew the publish consent → 404. Apresiasi (Humas and Kesiswaan): a colleague deleted the event → 404 (findOrFail).
 * The screens now say what happened and refresh; an id they never showed is still a 404. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

// Publikasi -----------------------------------------------------------------------------------------------------------

it('tells Humas that the selected card was already published instead of a 403 (QA9)', function (string $method, string $by): void {
    $publication = HumasFixtures::publication();
    $component = Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->assertSet('selectedId', $publication->getKey())
        ->set('date', CarbonImmutable::tomorrow()->format('Y-m-d'))
        ->set('time', '09:00');

    if ($by === 'job') {
        $publication->forceFill(['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->subMinute(), 'channels' => ['instagram'], 'caption' => 'Selamat, Nadia!'])->save();
        app(AutoPublishPublication::class)->handle((string) $publication->getKey());
    } else {
        app(PublishPublication::class)->handle($this->humas, $publication->fresh(), 'Selamat, Nadia!', ['instagram']);
    }

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan);

    $component->call($method)
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Publikasi ini sudah dipublikasikan. Antrean sudah diperbarui.', tone: 'warning')
        ->assertHasNoErrors()
        ->assertSet('selectedId', $publication->getKey())
        ->assertSee('Sudah terbit');

    expect(Publication::query()->sole()->status)->toBe(PublicationStatus::Dipublikasikan);
})->with([
    'publish, other tab' => ['publish', 'tab'],
    'publish, auto-publish' => ['publish', 'job'],
    'schedule, other tab' => ['schedule', 'tab'],
]);

it('tells Humas that the selected card left the queue when the consent was withdrawn, instead of a 404 (QA9)', function (string $method): void {
    $publication = HumasFixtures::publication();
    $component = Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->assertSet('selectedId', $publication->getKey())
        ->set('date', CarbonImmutable::tomorrow()->format('Y-m-d'))
        ->set('time', '09:00');

    $publication->achievement->student->forceFill(['publish_consent' => false])->save();

    $component->call($method)
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Prestasi ini sudah tidak ada di antrean publikasi. Antrean sudah diperbarui.', tone: 'warning')
        ->assertSet('selectedId', null)
        ->assertDontSee('Nadia Putri Anindya');

    expect($publication->fresh()->status)->toBe(PublicationStatus::Siap);
})->with(['publish', 'schedule', 'recordAppreciation']);

it('still answers 404 for a card the queue never held (QA9)', function (): void {
    HumasFixtures::publication();
    $hidden = HumasFixtures::publication(['publish_consent' => false], studentName: 'Fajar Ramadhan');

    Livewire::actingAs($this->humas)->test(Publikasi::class)
        ->call('select', $hidden->getKey())
        ->assertNotFound();
});

// Apresiasi -----------------------------------------------------------------------------------------------------------

it('tells a colleague that the event was deleted instead of a 404 (QA9)', function (string $method): void {
    HumasFixtures::achievement();
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    $component = Livewire::actingAs($this->humas)->test(Apresiasi::class)->assertSee('Upacara hari ini');

    app(DeleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $event);

    $component->call($method, $event->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Acara ini sudah dihapus. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('addingEventId', null)
        ->assertSet('confirmEventId', null)
        ->assertNotDispatched('sth-modal-open')
        ->assertDontSee('Upacara hari ini');
})->with(['startAdding', 'confirmComplete', 'confirmDelete']);

it('closes the add form of an event a colleague deleted meanwhile (QA9)', function (): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event('Upacara Senin, 12 Oktober 2026');
    $component = Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('startAdding', $event->getKey())
        ->set('achievementId', $achievement->getKey());

    app(DeleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $event);

    $component->call('addAchievement')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Acara ini sudah dihapus. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('addingEventId', null)
        ->assertSet('achievementId', '');

    expect(Appreciation::query()->count())->toBe(0);
});

it('closes the confirmation dialog of an event a colleague deleted meanwhile (QA9)', function (string $open): void {
    $achievement = HumasFixtures::achievement();
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString());
    $row = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
    $row->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $component = Livewire::actingAs($this->humas)->test(Apresiasi::class);
    $component = $open === 'confirmRelease'
        ? $component->call('confirmRelease', $row->getKey())
        : $component->call($open, $event->getKey());
    $component->assertSet('confirmEventId', $event->getKey());

    app(DeleteAppreciationEvent::class)->handle(AuthTestHelpers::staff(RoleName::Kesiswaan), $event);

    $component->call('runConfirmed')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Acara ini sudah dihapus. Daftar sudah diperbarui.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: Apresiasi::CONFIRM_DIALOG)
        ->assertSet('confirmEventId', null)
        ->assertSet('confirmAction', '');

    expect(AppreciationEvent::query()->count())->toBe(0);
})->with(['confirmComplete', 'confirmDelete', 'confirmRelease']);

it('still answers 404 for an event id that was never deleted (QA9)', function (): void {
    $event = HumasFixtures::event();
    $event->delete(); // gone without the audited Action: nothing says a colleague deleted it

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('startAdding', $event->getKey())
        ->assertNotFound();

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->call('confirmDelete', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
});
