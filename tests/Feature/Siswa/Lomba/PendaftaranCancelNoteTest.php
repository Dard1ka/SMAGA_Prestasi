<?php

declare(strict_types=1);

use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use App\Notifications\Registrations\RegistrationCancelled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * SV1 (browser check of 2026-10-30): the dialog "Batalkan pendaftaran?" on Detail lomba › Pendaftaran lomba said
 * "Anggota tim akan diberi tahu." also for an individu registration. The sentence now names who CancelRegistration
 * really tells (D-109): the other team members who can log in, the guru pembimbing while still a guru, the approvers
 * ("sekolah") once the registration waited for the decision (D-77, D-150), nobody while the lomba waits (D-148).
 * Every case below also cancels and checks the notices, so the sentence and the Action cannot drift apart.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->tu = F::staff(RoleName::TataUsaha, 'Tata Usaha Contoh');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-D');
    $this->keisha = F::student('Keisha Larasati', 'XI-F');

    /** @param list<Student> $members */
    $this->register = fn (Student $leader, Competition $lomba, RegistrationType $type, array $members = []): Registration => app(CreateRegistration::class)
        ->handle(F::user($leader), $lomba, $type, array_map(fn (Student $s): string => $s->getKey(), $members), $this->guru->id);

    // The dialog line on the leader's panel: in the view data and on the page (the dialog's description).
    $this->note = function (Student $leader, Competition $lomba): string {
        $panel = Livewire::actingAs(F::user($leader))->test(Form::class, ['competitionId' => $lomba->getKey()]);
        $note = (string) $panel->viewData('status')['cancelNote'];
        $panel->assertSee('Batalkan pendaftaran?')->assertSee($note);

        return $note;
    };

    $this->cancel = fn (Student $leader, Competition $lomba) => Livewire::actingAs(F::user($leader))
        ->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->call('batalkan')
        ->assertHasNoErrors();
});

it('names the guru and the school, not a team, for an individu registration (SV1)', function (): void {
    $lomba = F::openCompetition();
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Individu);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek);

    expect(($this->note)($this->nadia, $lomba))
        ->toBe('Guru pembimbing dan sekolah akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.')
        ->not->toContain('Anggota tim');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertSentTo($this->guru, RegistrationCancelled::class);
    Notification::assertSentTo([$this->kepsek, $this->tu], RegistrationCancelled::class);
    Notification::assertSentTimes(RegistrationCancelled::class, 3);
});

it('names the team and the guru while the team waits for answers, not the school yet', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Tim, [$this->dimas, $this->keisha]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    expect(($this->note)($this->nadia, $lomba))
        ->toBe('Anggota tim dan guru pembimbing akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertSentTo([F::user($this->dimas), F::user($this->keisha), $this->guru], RegistrationCancelled::class);
    Notification::assertNotSentTo([$this->kepsek, $this->tu], RegistrationCancelled::class);
});

it('names the team, the guru and the school once the team waits for the decision (D-77)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);

    expect(($this->note)($this->nadia, $lomba))
        ->toBe('Anggota tim, guru pembimbing, dan sekolah akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertSentTo(
        [F::user($this->dimas), F::user($this->keisha), $this->guru, $this->kepsek, $this->tu],
        RegistrationCancelled::class,
    );
});

it('says nobody is told while the own lomba still waits for Kesiswaan (D-148)', function (): void {
    $lomba = F::pendingCompetition(F::user($this->nadia), F::usulanAttributes());
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Individu);
    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);

    expect(($this->note)($this->nadia, $lomba))->toBe('Pendaftaran ini belum diteruskan, jadi tidak ada yang diberi tahu.');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertSentTimes(RegistrationCancelled::class, 0);
});

it('leaves out a guru who can no longer be told', function (): void {
    $lomba = F::openCompetition();
    ($this->register)($this->nadia, $lomba, RegistrationType::Individu);
    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    expect(($this->note)($this->nadia, $lomba))
        ->toBe('Sekolah akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertNotSentTo($this->guru, RegistrationCancelled::class);
    Notification::assertSentTo([$this->kepsek, $this->tu], RegistrationCancelled::class);
});

it('promises nothing when nobody can be told, without naming a teammate\'s account status', function (): void {
    $lomba = F::teamCompetition(2);
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Tim, [$this->dimas]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    F::user($this->dimas)->forceFill(['status' => UserStatus::Disabled])->save();
    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    expect(($this->note)($this->nadia, $lomba))
        ->toBe('Tidak ada yang diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    ($this->cancel)($this->nadia, $lomba);

    Notification::assertSentTimes(RegistrationCancelled::class, 0);
});

it('draws no cancel dialog for a member who is not the ketua', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = ($this->register)($this->nadia, $lomba, RegistrationType::Tim, [$this->dimas]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);

    $panel = Livewire::actingAs(F::user($this->dimas))->test(Form::class, ['competitionId' => $lomba->getKey()]);

    expect($panel->viewData('status')['canCancel'])->toBeFalse()
        ->and($panel->viewData('status')['cancelNote'])->toBe('');
    $panel->assertDontSee('Batalkan pendaftaran?');
});
