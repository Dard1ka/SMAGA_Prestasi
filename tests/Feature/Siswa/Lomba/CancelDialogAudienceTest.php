<?php

declare(strict_types=1);

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RegistrationCancelled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * SV1 (second browser check of 2026-10-30): the "Batalkan pendaftaran?" dialog on Lomba saya had a fixed description
 * that never said who hears of the cancel, while the dialog on Detail lomba › Pendaftaran lomba had its own copy of
 * CancelRegistration's recipient rules. Both now print CancelRegistration::noticeLine(), built from audience(), the rule
 * handle() sends by (D-109). Every case shows the line in both dialogs, then cancels on Lomba saya and compares the
 * groups the line names with the RegistrationCancelled notices that really went out. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->tu = F::staff(RoleName::TataUsaha, 'Tata Usaha Contoh');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
});

/** @param list<Student> $members */
function cdlRegister(object $t, Competition $lomba, RegistrationType $type, array $members = []): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        $type,
        array_map(fn (Student $s): string => $s->getKey(), $members),
        $t->guru->id,
    );
}

/** The audiences of every RegistrationCancelled sent so far, in the order of the line (siswa, guru, kepsek). */
function cdlAudiencesSent(): array
{
    $sent = collect(Notification::sentNotifications())
        ->flatMap(fn (array $byKey): array => collect($byKey)
            ->flatMap(fn (array $byClass): array => $byClass[RegistrationCancelled::class] ?? [])
            ->all())
        ->map(fn (array $entry): string => $entry['notification']->audience)
        ->unique()
        ->all();

    return array_values(array_intersect(['siswa', 'guru', 'kepsek'], $sent));
}

/**
 * Nadia (the ketua) reads $expected in the dialog of Lomba saya and of the Detail lomba panel, cancels on Lomba saya,
 * and the groups the line names are exactly the groups that got a RegistrationCancelled.
 */
function cdlSameLineThenCancel(object $t, Registration $registration, string $expected): void
{
    $leader = F::user($t->nadia);
    $description = '<p id="batal-daftar-desc" class="m-0 text-sm text-muted">'.e($expected).'</p>';

    $lombaSaya = Livewire::actingAs($leader)->test(LombaSaya::class, ['registration' => $registration]);
    expect($lombaSaya->viewData('detail')['cancelNote'])->toBe($expected)
        ->and(CancelRegistration::noticeLine($registration->fresh(), $leader))->toBe($expected);
    $lombaSaya->assertSee('Batalkan pendaftaran?')->assertSeeHtml($description)
        // The old fixed text promised nothing about notices and said "pendaftaran lomba" (SV1).
        ->assertDontSee('Pendaftaran yang dibatalkan tidak bisa dipulihkan. Kamu bisa mendaftar lagi selama pendaftaran lomba masih dibuka.');

    $panel = Livewire::actingAs($leader)->test(Form::class, ['competitionId' => $registration->competition_id]);
    expect($panel->viewData('status')['cancelNote'])->toBe($expected);
    $panel->assertSee('Batalkan pendaftaran?')->assertSeeHtml($description);

    $lombaSaya->call('cancel')->assertDispatched('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'success');
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    $line = mb_strtolower($expected);
    $named = array_keys(array_filter([
        'siswa' => str_contains($line, 'anggota tim'),
        'guru' => str_contains($line, 'guru pembimbing'),
        'kepsek' => str_contains($line, 'sekolah'),
    ]));

    expect(cdlAudiencesSent())->toBe($named);
}

it('names the guru and the school for an individu registration that waits for the approval', function (): void {
    $registration = cdlRegister($this, F::openCompetition(), RegistrationType::Individu);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek);

    cdlSameLineThenCancel($this, $registration, 'Guru pembimbing dan sekolah akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    Notification::assertSentTo([$this->guru, $this->kepsek, $this->tu], RegistrationCancelled::class);
    Notification::assertSentTimes(RegistrationCancelled::class, 3);
});

it('names the team and the guru while the team waits for answers, not the school', function (): void {
    $registration = cdlRegister($this, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    cdlSameLineThenCancel($this, $registration, 'Anggota tim dan guru pembimbing akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    // Also the invitee who has not answered yet hears of it; the approvers were never asked.
    Notification::assertSentTo([F::user($this->dimas), F::user($this->keisha), $this->guru], RegistrationCancelled::class);
    Notification::assertNotSentTo([$this->kepsek, $this->tu, F::user($this->nadia)], RegistrationCancelled::class);
});

it('names the team, the guru and the school once the team waits for the decision (D-77, D-150)', function (): void {
    $registration = cdlRegister($this, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);

    cdlSameLineThenCancel($this, $registration, 'Anggota tim, guru pembimbing, dan sekolah akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    Notification::assertSentTo(
        [F::user($this->dimas), F::user($this->keisha), $this->guru, $this->kepsek, $this->tu],
        RegistrationCancelled::class,
    );
    Notification::assertSentTimes(RegistrationCancelled::class, 5);
});

it('says nobody is told while the own lomba still waits for Kesiswaan (D-148)', function (): void {
    $lomba = F::pendingCompetition(F::user($this->nadia), F::usulanAttributes([
        'participation' => Participation::Tim, 'max_team_size' => 3,
    ]));
    $registration = cdlRegister($this, $lomba, RegistrationType::Tim, [$this->dimas]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);

    cdlSameLineThenCancel($this, $registration, 'Pendaftaran ini belum diteruskan, jadi tidak ada yang diberi tahu.');

    Notification::assertNothingSent();
});

it('leaves out a member who declined, in the line and in the notices', function (): void {
    $registration = cdlRegister($this, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);

    cdlSameLineThenCancel($this, $registration, 'Anggota tim dan guru pembimbing akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    Notification::assertSentTo([F::user($this->keisha), $this->guru], RegistrationCancelled::class);
    Notification::assertNotSentTo(F::user($this->dimas), RegistrationCancelled::class);
});

it('names only the guru for a team left alone after its invitation expired (D-36, D-50)', function (): void {
    $lomba = F::teamCompetition(2);
    $registration = cdlRegister($this, $lomba, RegistrationType::Tim, [$this->dimas]);
    $lomba->forceFill(['registration_closes_at' => now()->subHour()])->save();
    app(ExpirePendingInvitations::class)->handle($registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::teamIsShort($registration->fresh()))->toBeTrue();

    cdlSameLineThenCancel($this, $registration, 'Guru pembimbing akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.');

    Notification::assertSentTo($this->guru, RegistrationCancelled::class);
    Notification::assertSentTimes(RegistrationCancelled::class, 1);
});

it('is the audience handle() sends to, never the ketua, and empty from menunggu_lomba', function (): void {
    $registration = cdlRegister($this, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    $leader = F::user($this->nadia);

    $audience = CancelRegistration::audience($registration->fresh(), $leader);

    expect($audience['members']->modelKeys())->toEqualCanonicalizing([F::user($this->dimas)->id, F::user($this->keisha)->id])
        ->and($audience['mentor']?->is($this->guru))->toBeTrue()
        ->and($audience['approvers']->modelKeys())->toEqualCanonicalizing([$this->kepsek->id, $this->tu->id])
        ->and(collect([...$audience['members'], ...$audience['approvers']])->contains(fn (User $u): bool => $u->is($leader)))->toBeFalse();

    // The same registration read from the status a cancel starts from: no approvers before menunggu_kepsek, nobody
    // from menunggu_lomba.
    expect(CancelRegistration::audience($registration->fresh(), $leader, RegistrationStatus::MenungguKonfirmasi)['approvers'])->toBeEmpty();
    $nobody = CancelRegistration::audience($registration->fresh(), $leader, RegistrationStatus::MenungguLomba);
    expect($nobody['members'])->toBeEmpty()->and($nobody['mentor'])->toBeNull()->and($nobody['approvers'])->toBeEmpty();
});

it('draws no cancel dialog and no line for a team member who is not the ketua', function (): void {
    $registration = cdlRegister($this, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);

    $page = Livewire::actingAs(F::user($this->dimas))->test(LombaSaya::class, ['registration' => $registration]);

    expect($page->viewData('detail')['canCancel'])->toBeFalse()
        ->and($page->viewData('detail')['cancelNote'])->toBe('');
    $page->assertDontSee('Batalkan pendaftaran?');
});
