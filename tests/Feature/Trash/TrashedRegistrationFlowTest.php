<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Registrations\MemberResponded;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * smaga_server, the trash of 5 Nov 2026 (Admin › Kelas › Sampah): a trashed student (its students row AND users row
 * soft-deleted, the status left as it was) takes no part in the registration flow until "Pulihkan".
 * - ResumeWaitingRegistrations: the waiting registration (`menunggu_lomba`) of a trashed ketua stays waiting when
 *   Kesiswaan decides the lomba: no approver notice, no team invitation, no cancel, so Pulihkan finds it as it was.
 * - ConfirmMembership / ExpirePendingInvitations: only members whose student is outside the trash count as people of a
 *   team (D-36), and a team whose ketua is in the trash never goes on to the approvers; its open invitations are not
 *   expired while the ketua is in the trash (they are after Pulihkan).
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->admin = F::staff(RoleName::Admin, 'Admin Sekolah Contoh');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Kesiswaan Contoh');
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->tu = F::staff(RoleName::TataUsaha, 'Tata Usaha Contoh');
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    $this->rizkyUser = F::user($this->rizky);
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->dimasUser = F::user($this->dimas);
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->keishaUser = F::user($this->keisha);
});

/** Puts these students in the trash through the Admin's Action. */
function trflowTrash(object $t, Student ...$students): void
{
    app(TrashStudents::class)->handle($t->admin, array_map(fn (Student $s): int => (int) $s->user_id, $students), 'Data ganda dari import.');
}

/** "Pulihkan" of the student's open trash entry. */
function trflowRestore(object $t, Student $student): void
{
    $entry = TrashEntry::query()->where('user_id', $student->user_id)->whereNull('restored_at')->sole();
    app(RestoreFromTrash::class)->handle($t->admin, $entry);
}

/** Nadia's queued lomba with her waiting registration (individu, or a team with Rizky). */
function trflowWaiting(object $t, bool $team = false): Registration
{
    $lomba = F::pendingCompetition($t->nadiaUser, F::usulanAttributes(
        $team ? ['participation' => Participation::Tim, 'max_team_size' => 3] : [],
    ));

    return app(CreateRegistration::class)->handle(
        $t->nadiaUser,
        $lomba,
        $team ? RegistrationType::Tim : RegistrationType::Individu,
        $team ? [$t->rizky->getKey()] : [],
        $t->guru->getKey(),
    );
}

function trflowDecide(object $t, Registration $registration, CompetitionStatus $decision): void
{
    app(VerifyCompetition::class)->handle($t->kesiswaan, Competition::query()->findOrFail($registration->competition_id), $decision);
}

/** Nadia (ketua) registers a team for a published lomba of up to four people, the given students invited. */
function trflowTeam(object $t, array $members, ?Competition $lomba = null): Registration
{
    return app(CreateRegistration::class)->handle(
        $t->nadiaUser,
        $lomba ?? F::teamCompetition(4),
        RegistrationType::Tim,
        array_map(fn (Student $s): string => (string) $s->getKey(), $members),
        $t->guru->getKey(),
    );
}

function trflowStatus(Registration $registration): RegistrationStatus
{
    return Registration::query()->findOrFail($registration->getKey())->status;
}

// ResumeWaitingRegistrations ------------------------------------------------------------------------------------------

it('keeps the waiting registration of a trashed ketua waiting when Kesiswaan decides the lomba, as Pulihkan finds it', function (CompetitionStatus $decision, bool $team): void {
    $r = trflowWaiting($this, $team);
    trflowTrash($this, $this->nadia);

    trflowDecide($this, $r, $decision);

    $fresh = Registration::query()->findOrFail($r->getKey());
    expect($fresh->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->answer_until)->toBeNull()
        ->and($r->members()->where('role', MemberRole::Anggota)->whereNotNull('invited_at')->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('event', ['registration.resumed', 'registration.cancelled'])->exists())->toBeFalse();
    Notification::assertNothingSentTo($this->kepsek);
    Notification::assertNothingSentTo($this->tu);
    Notification::assertNothingSentTo($this->guru);
    Notification::assertNothingSentTo($this->rizkyUser);

    trflowRestore($this, $this->nadia);

    expect(trflowStatus($r))->toBe(RegistrationStatus::MenungguLomba);
    Notification::assertNothingSentTo($this->kepsek);
    Notification::assertNothingSentTo($this->rizkyUser);
})->with([
    'published, individu' => [CompetitionStatus::Dipublikasikan, false],
    'published, tim' => [CompetitionStatus::Dipublikasikan, true],
    'rejected, individu' => [CompetitionStatus::Ditolak, false],
    'rejected, tim' => [CompetitionStatus::Ditolak, true],
]);

it('moves on the waiting registration of a ketua outside the trash and keeps only the trashed ketua\'s one waiting', function (): void {
    $nadias = trflowWaiting($this);
    $rizkys = Registration::factory()->status(RegistrationStatus::MenungguLomba)->create([
        'competition_id' => $nadias->competition_id,
        'leader_student_id' => $this->rizky->getKey(),
        'mentor_id' => $this->guru->getKey(),
    ]);
    trflowTrash($this, $this->nadia);

    trflowDecide($this, $nadias, CompetitionStatus::Dipublikasikan);

    expect(trflowStatus($nadias))->toBe(RegistrationStatus::MenungguLomba)
        ->and(trflowStatus($rizkys))->toBe(RegistrationStatus::MenungguKepsek)
        ->and(Activity::query()->where('event', 'registration.resumed')->sole()->subject_id)->toBe($rizkys->getKey());
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class, fn ($n) => $n->registration->is($rizkys));
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class, fn ($n) => $n->registration->is($nadias));
});

// ConfirmMembership ---------------------------------------------------------------------------------------------------

it('does not count a trashed member as a person of the team, and counts it again after Pulihkan', function (): void {
    $r = trflowTeam($this, [$this->rizky, $this->dimas]);
    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());
    trflowTrash($this, $this->rizky);

    // Nadia and Dimas (not answered yet) are still two people.
    expect(ConfirmMembership::teamIsShort($r->fresh()))->toBeFalse();

    app(DeclineMembership::class)->handle($this->dimasUser, $r->fresh());

    // Nadia and the trashed Rizky are not two confirmed people: the team stays short and nobody approves it.
    expect(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::teamIsShort($r->fresh()))->toBeTrue()
        ->and(ConfirmMembership::teamIsReady($r->members()->get()))->toBeFalse()
        ->and(ConfirmMembership::onlyPeople($r->members())->count())->toBe(1);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertNotSentTo($this->tu, RegistrationAwaitingPrincipal::class);

    trflowRestore($this, $this->rizky);

    expect(ConfirmMembership::teamIsShort($r->fresh()))->toBeFalse()
        ->and(ConfirmMembership::teamIsReady($r->members()->get()))->toBeTrue();
});

it('moves a team on without a trashed invitee who has not answered', function (): void {
    $r = trflowTeam($this, [$this->rizky, $this->dimas]);
    trflowTrash($this, $this->dimas);

    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());

    expect(trflowStatus($r))->toBe(RegistrationStatus::MenungguKepsek)
        ->and($r->members()->where('student_id', $this->dimas->getKey())->sole()->confirmed_at)->toBeNull();
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class, fn ($n) => $n->registration->is($r));
});

it('never sends the team of a trashed ketua to the approvers when the last invitee answers', function (string $answer): void {
    $r = trflowTeam($this, [$this->rizky, $this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle($this->dimasUser, $r->fresh());
    app(ConfirmMembership::class)->handle($this->keishaUser, $r->fresh());
    trflowTrash($this, $this->nadia);

    // Without the ketua the others would be enough (Dimas and Keisha confirmed): still nothing moves.
    $answer === 'ikut'
        ? app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh())
        : app(DeclineMembership::class)->handle($this->rizkyUser, $r->fresh());

    expect(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::leaderIsTrashed($r->fresh()))->toBeTrue()
        ->and(app(ConfirmMembership::class)->advanceIfTeamReady($r->fresh()))->toBeFalse()
        ->and(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertNotSentTo($this->tu, RegistrationAwaitingPrincipal::class);
    // Dimas's and Keisha's answers, both before the trash: Rizky's answer tells the trashed ketua nothing.
    Notification::assertSentToTimes($this->nadiaUser, MemberResponded::class, 2);

    trflowRestore($this, $this->nadia);

    expect(ConfirmMembership::leaderIsTrashed($r->fresh()))->toBeFalse();
})->with(['ikut', 'tolak']);

// ExpirePendingInvitations --------------------------------------------------------------------------------------------

it('counts only members outside the trash when the invitations expire', function (): void {
    $lomba = F::teamCompetition(4);
    $r = trflowTeam($this, [$this->rizky, $this->dimas], $lomba);
    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());
    trflowTrash($this, $this->rizky);
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();

    // Nadia and the trashed Rizky are not two confirmed people: short, and the approvers hear nothing.
    expect(app(ExpirePendingInvitations::class)->handle($r))->toBe(['expired' => 1, 'ready' => false, 'team' => 1])
        ->and(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(Activity::query()->where('event', 'registration.invitations_expired')->sole()->properties['team'])->toBe(1);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertNotSentTo($this->tu, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo($this->nadiaUser, InvitationsExpired::class, fn (InvitationsExpired $n) => ! $n->ready && $n->team === 1);
});

it('leaves the open invitations of a trashed ketua\'s team alone at the deadline and expires them after Pulihkan', function (): void {
    $lomba = F::teamCompetition(4);
    $r = trflowTeam($this, [$this->rizky, $this->dimas], $lomba);
    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());
    trflowTrash($this, $this->nadia);
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();

    expect(ExpirePendingInvitations::due(now()->toImmutable())->pluck('id')->all())->toBe([])
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toBeNull()
        ->and($r->members()->whereNotNull('declined_at')->exists())->toBeFalse()
        ->and(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(Activity::query()->where('event', 'registration.invitations_expired')->exists())->toBeFalse();
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertNotSentTo($this->nadiaUser, InvitationsExpired::class);

    trflowRestore($this, $this->nadia);

    expect(ExpirePendingInvitations::due(now()->toImmutable())->pluck('id')->all())->toBe([$r->getKey()])
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toBe(['expired' => 1, 'ready' => true, 'team' => 2])
        ->and(trflowStatus($r))->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class, fn ($n) => $n->registration->is($r));
    Notification::assertSentTo($this->nadiaUser, InvitationsExpired::class, fn (InvitationsExpired $n) => $n->ready && $n->team === 2);
});

it('sends no invitation to a trashed anggota of a team resumed from its lomba', function (): void {
    $r = trflowWaiting($this, team: true);
    trflowTrash($this, $this->rizky);

    trflowDecide($this, $r, CompetitionStatus::Dipublikasikan);

    expect(trflowStatus($r))->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo($this->rizkyUser, TeamInvitation::class);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});
