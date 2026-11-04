<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Actions\Registrations\InviteMembers;
use App\Actions\Registrations\RejectRegistration;
use App\Actions\Registrations\WithdrawInvitation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use App\Notifications\Registrations\RegistrationCancelled;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * QA10: registration Actions and RegistrationPolicy.
 * - A lomba Kesiswaan closed before its deadline: CloseCompetition says existing registrations keep running, but
 *   "Ikut tim" failed with "Batas pendaftaran sudah lewat." and the ketua could no longer invite or withdraw. For an
 *   existing registration the window is now the registration deadline, whatever the lomba's status; respond() uses the
 *   same window, so the buttons only show while they work. A new registration still needs an open lomba.
 * - The Actions say who hears about an answer or a rejection (D-109: toasts claim a notice only when one went out).
 * - A guru pembimbing who is no longer a guru (moved to Humas) gets no binaan notices (D-110 rule, mentorUser()).
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
});

/** Nadia (ketua) registers a team with the given anggota invited. */
function qa10RegTeam(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        RegistrationType::Tim,
        array_map(fn ($s) => $s->getKey(), $members),
        $t->guru->id,
    );
}

// A lomba closed before its deadline ---------------------------------------------------------------------------------

it('lets the invitees answer after Kesiswaan closed the lomba before its deadline (QA10)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = qa10RegTeam($this, $lomba, [$this->dimas, $this->keisha]);
    app(CloseCompetition::class)->handle($this->kesiswaan, $lomba);

    expect(F::user($this->dimas)->can('respond', $registration->fresh()))->toBeTrue();

    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    app(DeclineMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('lets the ketua invite and withdraw after Kesiswaan closed the lomba before its deadline (QA10)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = qa10RegTeam($this, $lomba, [$this->dimas]);
    app(CloseCompetition::class)->handle($this->kesiswaan, $lomba);

    expect(F::user($this->nadia)->can('invite', $registration->fresh()))->toBeTrue();

    app(InviteMembers::class)->handle(F::user($this->nadia), $registration->fresh(), [$this->keisha->getKey()]);
    app(WithdrawInvitation::class)->handle(F::user($this->nadia), $registration->fresh(), $this->dimas->getKey());

    expect($registration->members()->pluck('student_id')->sort()->values()->all())
        ->toBe(collect([$this->nadia->getKey(), $this->keisha->getKey()])->sort()->values()->all());
});

it('still refuses a new registration for a lomba Kesiswaan closed (QA10)', function (): void {
    $lomba = F::teamCompetition(4);
    app(CloseCompetition::class)->handle($this->kesiswaan, $lomba);

    expect(F::user($this->rizky)->can('create', [Registration::class, $lomba->fresh()]))->toBeFalse();
});

it('hides the answer and the invite once the deadline passed, before the expiry job ran (QA10)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = qa10RegTeam($this, $lomba, [$this->dimas, $this->keisha]);
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();

    expect(F::user($this->dimas)->can('respond', $registration->fresh()))->toBeFalse()
        ->and(F::user($this->nadia)->can('invite', $registration->fresh()))->toBeFalse()
        ->and(fn () => app(DeclineMembership::class)->handle(F::user($this->keisha), $registration->fresh()))
        ->toThrow(AuthorizationException::class);

    // The scheduler still expires the open invitations at the deadline.
    expect(app(ExpirePendingInvitations::class)->handle($registration))->toBe(['expired' => 2, 'ready' => false, 'team' => 1])
        ->and(ExpirePendingInvitations::expiredMemberIds($registration->fresh()))->toHaveCount(2);
});

// Who is told (D-109) ------------------------------------------------------------------------------------------------

it('says whether the ketua hears about an answer (QA10)', function (): void {
    $registration = qa10RegTeam($this, F::teamCompetition(3), [$this->dimas]);

    expect(ConfirmMembership::notifiesLeader($registration))->toBeTrue();

    F::user($this->nadia)->forceFill(['status' => UserStatus::Disabled])->save();

    expect(ConfirmMembership::notifiesLeader($registration->fresh()))->toBeFalse();
});

it('says how many members hear about a rejection (QA10)', function (): void {
    $registration = qa10RegTeam($this, F::teamCompetition(3), [$this->dimas]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    expect(RejectRegistration::studentsTold($registration->fresh()))->toBe(['told' => 2, 'of' => 2]);

    F::user($this->dimas)->forceFill(['status' => UserStatus::Disabled])->save();

    expect(RejectRegistration::studentsTold($registration->fresh()))->toBe(['told' => 1, 'of' => 2]);
});

// A guru pembimbing who left the guru role ---------------------------------------------------------------------------

it('does not tell a guru pembimbing who is no longer a guru about a decision or a cancel (QA10)', function (string $what): void {
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), F::openCompetition(), RegistrationType::Individu, [], $this->guru->id);
    $this->guru->syncRoles([RoleName::Humas->value]);

    $notice = match ($what) {
        'approve' => (function () use ($registration): string {
            app(ApproveRegistration::class)->handle($this->kepsek, $registration);

            return RegistrationApproved::class;
        })(),
        'reject' => (function () use ($registration): string {
            app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Jadwal bentrok dengan penilaian tengah semester.');

            return RegistrationRejected::class;
        })(),
        'cancel' => (function () use ($registration): string {
            app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

            return RegistrationCancelled::class;
        })(),
    };

    Notification::assertNotSentTo($this->guru, $notice);
    expect(ApproveRegistration::mentorToTell($registration->fresh()))->toBeNull();
})->with(['approve', 'reject', 'cancel']);

it('still tells a guru pembimbing who holds the guru role (QA10)', function (): void {
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), F::openCompetition(), RegistrationType::Individu, [], $this->guru->id);

    app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Jadwal bentrok dengan penilaian tengah semester.');

    Notification::assertSentTo($this->guru, RegistrationRejected::class);
    expect(ApproveRegistration::mentorToTell($registration->fresh())?->is($this->guru))->toBeTrue();
});
