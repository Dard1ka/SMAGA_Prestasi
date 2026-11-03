<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Reports\VerifyReport;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Competition;
use App\Notifications\Competitions\BinaanCompetitionChanged;
use App\Notifications\Competitions\CompetitionChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\LombaChangeFixtures as C;

/*
 * QA9: Kesiswaan › Kelola lomba with a second Kesiswaan or Admin account working on the same lomba. A card or drawer
 * acting on a lomba another account had published, rejected or closed meanwhile answered a full-screen 403 (the
 * component or the Action asked the policy first, which refuses any state but the expected one). The screen now says
 * what happened and refreshes. Also: correcting a published lomba refreshes the public pages at once.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

/** The other account decides the lomba ('dipublikasikan' | 'ditolak' | 'ditutup'). */
function qa9LombaDecide(object $t, Competition $competition, string $state): void
{
    if ($state === 'ditutup') {
        if ($competition->status === CompetitionStatus::MenungguVerifikasi) {
            app(VerifyCompetition::class)->handle($t->admin, $competition, CompetitionStatus::Dipublikasikan);
        }

        app(CloseCompetition::class)->handle($t->admin, $competition->fresh());

        return;
    }

    app(VerifyCompetition::class)->handle($t->admin, $competition, CompetitionStatus::from($state));
}

it('tells a second account that a queued lomba was already decided instead of a 403 (QA9)', function (string $method, string $state): void {
    $competition = Competition::factory()->create(['name' => 'Lomba Karya Tulis Ilmiah Fiktif 2026']);
    $component = Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Lomba Karya Tulis Ilmiah Fiktif 2026');

    qa9LombaDecide($this, $competition, $state);

    $component->call($method, $competition->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah diputuskan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('rejectingId', null);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::from($state))
        ->and($competition->fresh()->verified_by)->toBe($this->admin->getKey());
})->with([
    'publish, published' => ['publish', 'dipublikasikan'],
    'publish, rejected' => ['publish', 'ditolak'],
    'startReject, published' => ['startReject', 'dipublikasikan'],
    'startReject, rejected' => ['startReject', 'ditolak'],
]);

it('tells a second account the lomba was decided while its reject form or past-dates question was open (QA9)', function (): void {
    $competition = Competition::factory()->create();
    $past = Competition::factory()->create([
        'registration_opens_at' => now()->subDays(40), 'registration_closes_at' => now()->subDays(20),
        'starts_on' => now()->subDays(10)->format('Y-m-d'), 'final_on' => now()->subDays(10)->format('Y-m-d'),
    ]);
    $component = Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('startReject', $competition->getKey())
        ->set('rejectNote', 'Lomba sudah lewat.')
        ->call('publish', $past->getKey())
        ->assertSet('pastId', $past->getKey());

    qa9LombaDecide($this, $competition, 'dipublikasikan');
    qa9LombaDecide($this, $past, 'ditolak');

    $component->call('reject')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah diputuskan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('rejectingId', null)
        ->assertHasNoErrors();

    $component->call('publishPast')
        ->assertOk()
        ->assertDispatched('sth-modal-close', id: Index::PAST_DIALOG)
        ->assertSet('pastId', null);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($competition->fresh()->verification_note)->toBeNull()
        ->and($past->fresh()->status)->toBe(CompetitionStatus::Ditolak);
});

it('tells a second account that a lomba was already closed or rejected when it closes or edits it (QA9)', function (string $method, string $state, string $message): void {
    $competition = $state === 'ditolak' ? Competition::factory()->create() : F::competition();
    $component = Livewire::actingAs($this->kesiswaan)->test(Index::class);

    qa9LombaDecide($this, $competition, $state);

    $component->call($method, $competition->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: $message, tone: 'warning')
        ->assertNotDispatched('lomba-form-open');

    expect($competition->fresh()->status)->toBe(CompetitionStatus::from($state));
})->with([
    // D-128: "Tutup" is askClose() now (it opens the confirm dialog); close() alone does nothing (LombaTutupDialogTest).
    'close, closed' => ['askClose', 'ditutup', 'Lomba ini sudah ditutup. Daftar sudah diperbarui.'],
    'edit, closed' => ['edit', 'ditutup', 'Lomba ini sudah ditutup. Daftar sudah diperbarui.'],
    'edit, rejected' => ['edit', 'ditolak', 'Lomba ini sudah ditolak. Daftar sudah diperbarui.'],
]);

it('keeps the 403 for an account that may not manage lomba (QA9)', function (): void {
    $competition = F::competition();
    $guru = F::staff(RoleName::Guru, 'Pak Agus');

    expect(fn () => app(CloseCompetition::class)->handle($guru, $competition))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($guru)->test(Index::class)->assertForbidden();
    Livewire::actingAs($guru)->test(Form::class)->assertForbidden();
});

// Drawer (Lomba\Form) -----------------------------------------------------------------------------------------------

it('does not open the drawer on a lomba another account rejected meanwhile (QA9)', function (): void {
    $competition = Competition::factory()->create();
    qa9LombaDecide($this, $competition, 'ditolak');

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah ditolak. Daftar sudah diperbarui.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open');
});

it('closes the drawer when another account rejected or closed the lomba before "Simpan" (QA9)', function (string $method, string $state, string $message): void {
    $competition = Competition::factory()->create(['name' => 'Lomba Karya Tulis Ilmiah Fiktif 2026']);
    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->set('form.name', 'Lomba Karya Tulis Ilmiah Fiktif Nasional 2026');

    qa9LombaDecide($this, $competition, $state);

    $component->call($method)
        ->assertOk()
        ->assertDispatched('sth-toast', message: $message, tone: 'warning')
        ->assertDispatched('sth-modal-close', id: Form::DIALOG)
        ->assertDispatched('lomba-saved');

    expect($competition->fresh()->name)->toBe('Lomba Karya Tulis Ilmiah Fiktif 2026');
})->with([
    'save, rejected' => ['save', 'ditolak', 'Lomba ini sudah ditolak. Daftar sudah diperbarui.'],
    'save, closed' => ['save', 'ditutup', 'Lomba ini sudah ditutup. Daftar sudah diperbarui.'],
    'saveAndPublish, rejected' => ['saveAndPublish', 'ditolak', 'Lomba ini sudah ditolak. Daftar sudah diperbarui.'],
]);

it('keeps the typed changes when another account published the lomba before "Simpan & publikasikan" (QA9)', function (): void {
    $competition = Competition::factory()->create(['name' => 'Lomba Karya Tulis Ilmiah Fiktif 2026']);
    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.name', 'Lomba Karya Tulis Ilmiah Fiktif Nasional 2026');

    qa9LombaDecide($this, $competition, 'dipublikasikan');

    $component->call('saveAndPublish')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah dipublikasikan. Perubahan belum tersimpan, periksa lalu simpan.', tone: 'warning')
        ->assertNotDispatched('sth-modal-close')
        ->assertSet('formMode', 'edit')
        ->assertSet('form.name', 'Lomba Karya Tulis Ilmiah Fiktif Nasional 2026');

    expect($competition->fresh()->name)->toBe('Lomba Karya Tulis Ilmiah Fiktif 2026')
        ->and($competition->fresh()->verified_by)->toBe($this->admin->getKey());

    // "Simpan" now saves the change on the published lomba.
    $component->call('save')->assertHasNoErrors()->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');
    expect($competition->fresh()->name)->toBe('Lomba Karya Tulis Ilmiah Fiktif Nasional 2026');
});

// Since D-126 a save on a published lomba tells its registered students and their guru. A "Periksa lomba" drawer
// that stayed open while a colleague published the lomba therefore gets the same stop as "Simpan & publikasikan":
// nothing is saved or sent on the first "Simpan", the drawer turns into "Ubah lomba" with the hint, the second saves.
it('asks for a second "Simpan" when another account published the lomba while "Periksa lomba" was open (EM2)', function (): void {
    $competition = Competition::factory()->create([
        'name' => 'Lomba Karya Tulis Ilmiah Fiktif 2026', 'mode' => 'luring', 'location' => 'Kota Semarang',
    ]);
    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->assertDontSee('sedang terdaftar di lomba ini');

    qa9LombaDecide($this, $competition, 'dipublikasikan');
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-B');
    C::registration($competition->fresh(), RegistrationStatus::MenungguKepsek, $ketua, $guru, RegistrationType::Individu);

    $component->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->call('save')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Lomba ini sudah dipublikasikan. Perubahan belum tersimpan, periksa lalu simpan.', tone: 'warning')
        ->assertDispatched('lomba-saved')
        ->assertNotDispatched('sth-modal-close')
        ->assertSet('formMode', 'edit')
        ->assertSet('form.location', 'Aula SMA Negeri 3 Semarang')
        ->assertSee('Ubah lomba')
        ->assertSee('1 siswa sedang terdaftar di lomba ini')
        ->assertSee('Simpan perubahan')
        ->assertDontSee('Simpan & publikasikan');

    // Not assertNothingSent(): the colleague's publish itself told the submitter.
    expect($competition->fresh()->location)->toBe('Kota Semarang');
    Notification::assertNotSentTo($ketua->user, CompetitionChanged::class);
    Notification::assertNotSentTo($guru, BinaanCompetitionChanged::class);

    $component->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: Form::DIALOG)
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan. 1 siswa dan 1 guru pembimbing diberi tahu.', tone: 'success');

    expect($competition->fresh()->location)->toBe('Aula SMA Negeri 3 Semarang');
    Notification::assertSentTo($ketua->user, CompetitionChanged::class);
    Notification::assertSentTo($guru, BinaanCompetitionChanged::class);
});

it('still saves a queued lomba with one "Simpan" from "Periksa lomba" (EM2)', function (): void {
    $competition = Competition::factory()->create(['location' => 'Kota Semarang']);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: Form::DIALOG)
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');

    expect($competition->fresh()->location)->toBe('Aula SMA Negeri 3 Semarang')
        ->and($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
    Notification::assertNothingSent();
});

it('opens "Ubah lomba" on a published lomba right after a "Periksa lomba" drawer, without the warning (EM2)', function (): void {
    $queued = Competition::factory()->create();
    $published = F::competition();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $queued->getKey())
        ->assertSet('formMode', 'verify')
        ->call('open', $published->getKey())
        ->assertOk()
        ->assertSet('competitionId', $published->getKey())
        ->assertSet('formMode', 'edit')
        ->assertDispatched('sth-modal-open', id: Form::DIALOG)
        ->assertNotDispatched('sth-toast');
});

// Public pages ------------------------------------------------------------------------------------------------------

it('shows a corrected lomba name on the public pages right after the update (QA9)', function (): void {
    $competition = F::competition(['name' => 'Olimpiade Kimia Nasional Undip 2026']);
    $report = F::pendingReport(F::student('Nadia Putri Anindya', 'XI-D'), [], ResultType::Juara1, null, $competition);
    app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara1, F::allChecks());

    expect(collect(app(PublicAchievements::class)->recent())->pluck('lomba')->all())->toBe(['Olimpiade Kimia Nasional Undip 2026']);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), F::form([
        'name' => 'Olimpiade Kimia Nasional Universitas Diponegoro 2026',
        'bidang' => 'sains',
        'level' => 'nasional',
        'weight_category' => 'C',
    ]));

    expect(collect(app(PublicAchievements::class)->recent())->pluck('lomba')->all())->toBe(['Olimpiade Kimia Nasional Universitas Diponegoro 2026']);
});
