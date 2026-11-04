<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| D-128 (lead, 2026-10-10), sweep: Kesiswaan › Kelola lomba › "Tutup" asks in the app's own dialog instead of the
| browser's confirm(). A closed lomba leaves the student catalog and nothing opens it again; the native box did not
| say so. askClose() opens the dialog "tutup-lomba"; close() is the confirm step and does nothing without that
| question. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

it('asks in its own dialog before a lomba is closed and closes nothing yet (D-128)', function (): void {
    $lomba = F::competition(['name' => 'Lomba Peneliti Belia (LPB) 2026']);
    F::pendingReport(F::student('Nadia Putri Anindya', 'XI-4'), [F::student('Raka Pradipta', 'XI-4')], competition: $lomba);

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->assertSeeHtml('wire:click="askClose(\''.$lomba->getKey().'\')"')
        ->assertDontSeeHtml('wire:confirm')
        ->assertDontSeeHtml('wire:click="close(')
        // The dialog is on the page with its description from the first render.
        ->assertSeeHtml('aria-describedby="tutup-lomba-desc"')
        ->call('askClose', $lomba->getKey())
        ->assertDispatched('sth-modal-open', id: 'tutup-lomba')
        ->assertSet('closingId', $lomba->getKey())
        ->assertViewHas('closingLomba', ['name' => 'Lomba Peneliti Belia (LPB) 2026', 'count' => 2])
        ->assertSee('Tutup lomba ini?')
        ->assertSee('Lomba tidak tampil lagi di katalog siswa dan tidak bisa dibuka lagi.')
        ->assertSee('Pendaftaran yang sudah ada tetap berjalan.')
        ->assertSee('2 siswa terdaftar')
        ->assertSee('Tutup lomba')
        ->assertNotDispatched('sth-toast');

    expect($lomba->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and(Activity::query()->where('event', 'competition.closed')->exists())->toBeFalse();
});

it('closes the lomba after the confirmation (D-128)', function (RoleName $role): void {
    $lomba = F::competition();
    $actor = $role === RoleName::Admin ? $this->admin : $this->kesiswaan;

    Livewire::actingAs($actor)->test(Index::class)
        ->call('askClose', $lomba->getKey())
        ->call('close')
        ->assertDispatched('sth-modal-close', id: 'tutup-lomba')
        ->assertSet('closingId', null)
        ->assertDispatched('sth-toast', message: 'Lomba ditutup dan tidak tampil lagi di katalog.', tone: 'success')
        ->assertDontSeeHtml('wire:click="askClose(\''.$lomba->getKey().'\')"');

    expect($lomba->fresh()->status)->toBe(CompetitionStatus::Ditutup)
        ->and(Activity::query()->where('event', 'competition.closed')->sole()->causer_id)->toBe($actor->getKey());
})->with([RoleName::Kesiswaan, RoleName::Admin]);

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('closes nothing when the confirmation arrives without the question, and shows no error page (D-128)', function (): void {
    $lomba = F::competition();

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('close')
        ->assertOk()
        ->assertNotDispatched('sth-toast');

    expect($lomba->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});

it('closes one lomba once when "Tutup lomba" is pressed twice (D-128)', function (): void {
    $lomba = F::competition();
    $other = F::competition(['name' => 'National Science and Invention Fair (NSIF) 2026']);

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('askClose', $lomba->getKey())
        ->call('close')
        ->call('close')
        ->assertOk()
        // The second click says nothing: no "sudah ditutup" warning on top of the success.
        ->assertNotDispatched('sth-toast');

    expect($lomba->fresh()->status)->toBe(CompetitionStatus::Ditutup)
        ->and($other->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and(Activity::query()->where('event', 'competition.closed')->count())->toBe(1);
});

it('keeps the dialog state locked: the browser cannot name the lomba to close (D-128)', function (): void {
    $lomba = F::competition();

    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(Index::class)->set('closingId', $lomba->getKey()))
        ->toThrow(Exception::class)
        ->and($lomba->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});

it('opens no dialog for a lomba that is not published, and says what happened (D-128)', function (): void {
    $closed = F::competition();
    app(CloseCompetition::class)->handle($this->admin, $closed);

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('askClose', $closed->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah ditutup. Daftar sudah diperbarui.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('closingId', null);
});

it('says the lomba was already closed when a colleague closed it while the dialog was open (D-128)', function (): void {
    $lomba = F::competition();

    $component = Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->call('askClose', $lomba->getKey())
        ->assertDispatched('sth-modal-open', id: 'tutup-lomba');

    app(CloseCompetition::class)->handle($this->admin, $lomba->fresh());

    $component->call('close')
        ->assertOk()
        ->assertDispatched('sth-modal-close', id: 'tutup-lomba')
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah ditutup. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('closingId', null);

    expect(Activity::query()->where('event', 'competition.closed')->sole()->causer_id)->toBe($this->admin->getKey());
});

it('keeps every other role away from the question (D-128)', function (): void {
    $lomba = F::competition();

    foreach ([RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas] as $role) {
        Livewire::actingAs(F::staff($role, 'Staf '.$role->value))->test(Index::class)->assertForbidden();
    }

    Livewire::actingAs(F::student('Raka Pradipta', 'XII-2')->user)->test(Index::class)->assertForbidden();

    expect($lomba->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});
