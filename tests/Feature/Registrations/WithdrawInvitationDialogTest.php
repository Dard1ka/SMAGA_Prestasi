<?php

declare(strict_types=1);

use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Registrations\InvitationWithdrawn;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * D-128 (lead, 2026-10-10), sweep: Siswa › Daftar lomba › "Batalkan undangan" (the × beside an unanswered invitation)
 * asks first. One click on a small icon used to delete the invitation, tell the invitee and, when everyone else had
 * confirmed, send the team to the Kepala Sekolah at once (no more members possible). askBatalkanUndangan() opens the
 * dialog "batal-undangan"; batalkanUndangan() is the confirm step and does nothing without that question.
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-D');
    $this->keisha = F::student('Keisha Larasati', 'XI-F');
});

/** Team registration of Nadia (ketua) with the given anggota invited. */
function d128Team(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        RegistrationType::Tim,
        array_map(fn ($s) => $s->getKey(), $members),
        $t->guru->id,
    );
}

it('asks before it withdraws an invitation and changes nothing yet (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha]);

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        // The × only asks; the dialog is on the page with its description from the first render.
        ->assertSeeHtml('wire:click="askBatalkanUndangan(\''.$this->dimas->getKey().'\')"')
        ->assertDontSeeHtml('wire:click="batalkanUndangan(')
        ->assertSeeHtml('aria-describedby="batal-undangan-desc"')
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: 'batal-undangan')
        ->assertSet('withdrawingId', $this->dimas->getKey())
        ->assertViewHas('withdrawing', ['name' => 'Dimas Arya Nugroho', 'ready' => false, 'notice' => 'Dimas Arya Nugroho langsung diberi tahu.'])
        ->assertSee('Batalkan undangan?')
        ->assertSee('Undangan untuk Dimas Arya Nugroho dibatalkan. Kamu bisa mengundangnya lagi selama tim masih menunggu konfirmasi.')
        ->assertDontSee('langsung diteruskan untuk persetujuan')
        ->assertNotDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});

it('says in the dialog that the team goes to the Kepala Sekolah at once when the others already confirmed (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertViewHas('withdrawing', fn (array $withdrawing): bool => $withdrawing['ready'] === true)
        ->assertSee('Undangan untuk Dimas Arya Nugroho dibatalkan. Anggota lain sudah konfirmasi, jadi pendaftaran langsung diteruskan untuk persetujuan dan anggota tidak bisa ditambah lagi.')
        ->assertDontSee('Kamu bisa mengundangnya lagi')
        ->call('batalkanUndangan')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'batal-undangan')
        ->assertSet('withdrawingId', null)
        // The result names the hand-over, read from what the Action wrote.
        ->assertDispatched('sth-toast', message: 'Undangan untuk Dimas Arya Nugroho dibatalkan. Pendaftaran diteruskan untuk persetujuan.', tone: 'success')
        ->assertDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeFalse()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentToTimes(F::user($this->dimas), InvitationWithdrawn::class, 1);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('says that the team went to the Kepala Sekolah when another invitee confirmed while the dialog was open (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha]);

    // At the question both invitations are open, so the dialog says the ketua can invite again.
    $form = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertViewHas('withdrawing', fn (array $withdrawing): bool => $withdrawing['ready'] === false)
        ->assertSee('Kamu bisa mengundangnya lagi selama tim masih menunggu konfirmasi.');

    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    // Keisha's answer made this withdrawal the last open step: the toast says what really happened.
    $form->call('batalkanUndangan')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Undangan untuk Dimas Arya Nugroho dibatalkan. Pendaftaran diteruskan untuk persetujuan.', tone: 'success')
        ->assertDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeFalse()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('keeps a team of the ketua alone waiting, and says so in the dialog (D-128)', function (): void {
    // Only the ketua would be left: no team of two, so nothing goes to the Kepala Sekolah (D-36).
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertViewHas('withdrawing', fn (array $withdrawing): bool => $withdrawing['ready'] === false)
        ->call('batalkanUndangan')
        ->assertHasNoErrors()
        // Nothing was handed over, so the toast claims no hand-over.
        ->assertDispatched('sth-toast', message: 'Undangan untuk Dimas Arya Nugroho dibatalkan.', tone: 'success');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('promises the invitee a notice only when the account gets one, and never names the account status (D-109)', function (): void {
    $lomba = F::teamCompetition(3);
    d128Team($this, $lomba, [$this->dimas]);
    F::user($this->dimas)->forceFill(['status' => UserStatus::Disabled])->save();

    // The reader is a student: a classmate's account status is not hers to know (SECURITY §3m-3). The promise is
    // left out and the line under the title stays a sentence that is true for every open invitation.
    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertDispatched('sth-modal-open', id: 'batal-undangan')
        ->assertViewHas('withdrawing', fn (array $withdrawing): bool => $withdrawing['notice'] === 'Undangan ini belum dijawab.')
        ->assertSee('Undangan ini belum dijawab.')
        ->assertDontSee('tidak aktif')
        ->assertDontSee('langsung diberi tahu')
        ->call('batalkanUndangan')
        ->assertHasNoErrors();

    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('withdraws nothing when the confirmation arrives without the question, and shows no error (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('batalkanUndangan')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue();
    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});

it('withdraws once when "Batalkan undangan" is pressed twice in the dialog (D-128)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha]);

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->call('batalkanUndangan')
        ->call('batalkanUndangan')
        ->assertOk()
        ->assertHasNoErrors();

    expect($registration->members()->where('student_id', $this->keisha->getKey())->exists())->toBeTrue();
    Notification::assertSentToTimes(F::user($this->dimas), InvitationWithdrawn::class, 1);
});

it('keeps the dialog state locked: the browser cannot name the invitation to withdraw (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);

    expect(fn () => Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('withdrawingId', $this->dimas->getKey()))->toThrow(Exception::class)
        ->and($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue();
});

it('opens no dialog for a row that is not an open invitation and names the reason (D-128)', function (): void {
    $lomba = F::teamCompetition(4);
    $rizky = F::student('Rizky Maulana', 'XI-A');
    $hana = F::student('Hana Safira', 'X-C');
    // Rizky has not answered, so the team keeps waiting and the ketua may still change it.
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha, $rizky]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    app(DeclineMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    $ask = fn ($student) => Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $student->getKey())
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('withdrawingId', null)
        ->errors()->first('members');

    expect($ask($this->dimas))->toBe('Dimas Arya Nugroho sudah konfirmasi, undangannya tidak bisa dibatalkan.')
        ->and($ask($this->keisha))->toBe('Keisha Larasati sudah menolak undangan ini.')
        // The ketua and a student outside the team are no invitation.
        ->and($ask($this->nadia))->toBe('Undangan tidak ditemukan.')
        ->and($ask($hana))->toBe('Undangan tidak ditemukan.')
        ->and($registration->members()->count())->toBe(4)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
});

it('refuses the question to a member who is not the ketua (D-128)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = d128Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    // An anggota sees no team list and no ×; a forged call gets the refusal, not a dialog.
    Livewire::actingAs(F::user($this->dimas))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertDontSeeHtml('askBatalkanUndangan')
        ->assertDontSeeHtml('id="batal-undangan"')
        ->call('askBatalkanUndangan', $this->keisha->getKey())
        ->assertHasErrors(['members'])
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('withdrawingId', null)
        ->call('batalkanUndangan')
        ->assertOk();

    expect($registration->members()->where('student_id', $this->keisha->getKey())->exists())->toBeTrue();
    Notification::assertNotSentTo(F::user($this->keisha), InvitationWithdrawn::class);
});

it('says what happened when the invitee answered while the dialog was open (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);

    $form = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertDispatched('sth-modal-open', id: 'batal-undangan');

    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    // The team is complete now (menunggu_kepsek): the ketua may no longer change it. The team list, where a
    // `members` error is printed, is gone with that status, so the sentence must also come as a toast.
    $form->call('batalkanUndangan')
        ->assertOk()
        ->assertHasErrors(['members'])
        ->assertDispatched('sth-toast', message: 'Undangan tidak bisa dibatalkan lagi: tim sudah lengkap dan diteruskan untuk persetujuan.', tone: 'warning')
        ->assertDontSeeHtml('aria-label="Batalkan undangan Dimas Arya Nugroho"')
        ->assertDispatched('sth-modal-close', id: 'batal-undangan')
        ->assertNotDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});

it('says so in a toast when the × is pressed on a page older than the invitee\'s answer (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);
    $form = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSeeHtml('aria-label="Batalkan undangan Dimas Arya Nugroho"');

    // Dimas confirms on his phone before the ketua presses the ×: the page she looks at is stale.
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    $form->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertOk()
        ->assertHasErrors(['members'])
        ->assertDispatched('sth-toast', message: 'Undangan tidak bisa dibatalkan lagi: tim sudah lengkap dan diteruskan untuk persetujuan.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('withdrawingId', null)
        ->assertNotDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});

it('keeps the general sentence when the team is closed for another reason than the Kepala Sekolah step (D-128)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = d128Team($this, $lomba, [$this->dimas]);

    $form = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('askBatalkanUndangan', $this->dimas->getKey())
        ->assertDispatched('sth-modal-open', id: 'batal-undangan');

    // The registration deadline passes while the dialog is open: the team still waits, nothing went anywhere.
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();

    $form->call('batalkanUndangan')
        ->assertOk()
        ->assertHasErrors(['members'])
        ->assertDispatched('sth-toast', message: 'Undangan tidak bisa dibatalkan lagi untuk pendaftaran ini.', tone: 'warning')
        ->assertNotDispatched('pendaftaran-berubah');

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo(F::user($this->dimas), InvitationWithdrawn::class);
});
