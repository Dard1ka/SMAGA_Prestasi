<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\ApproveRevision;
use App\Actions\Registrations\CancelRevision;
use App\Actions\Registrations\ChangeMentor;
use App\Actions\Registrations\ChangeRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\RejectRevision;
use App\Actions\Registrations\RequestRevision;
use App\Actions\Registrations\RespondToRevision;
use App\Actions\Registrations\RevisionRules;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RevisionStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Sekolah\RevisiPendaftaran;
use App\Models\Competition;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RemovedFromTeam;
use App\Notifications\Registrations\RevisionAnswered;
use App\Notifications\Registrations\RevisionAwaitingDecision;
use App\Notifications\Registrations\RevisionDecided;
use App\Notifications\Registrations\RevisionInvitation;
use App\Notifications\Registrations\RevisionWithdrawn;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| SV8, D-199 (the lead, 2026-11-09): "siswa boleh mengajukan revisi dalam kondisi apapun entah belum di acc tu atau
| kesiswaan maupun sudah di acc. Kalau sudah di acc siswa tetap bisa revisi namun perlu persetujuan TU atau kesiswaan
| dulu untuk acc revisi tersebut". Before the approval the ketua's change applies at once (ChangeRegistration); after it
| the ketua asks for a revision (RequestRevision), new members accept first (RespondToRevision), then Tata Usaha or
| Kesiswaan decides (ApproveRevision / RejectRevision; first decision wins) and the ketua may cancel meanwhile
| (CancelRevision). Fictional people only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->hendra = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $this->retno = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->tu = F::staff(RoleName::TataUsaha, 'Sri Handayani');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Siti Rahmawati, S.Pd.');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->humas = F::staff(RoleName::Humas, 'Dewi Kartika');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    $this->lomba = F::teamCompetition(4, ['name' => 'Lomba Karya Tulis Ilmiah Nasional 2026']);
});

/** Nadia's team with the given anggota invited (menunggu_konfirmasi), guided by Pak Hendra. */
function rvTeam(object $t, array $members, ?Competition $lomba = null): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba ?? $t->lomba,
        RegistrationType::Tim,
        array_map(fn (Student $s): string => (string) $s->getKey(), $members),
        $t->hendra->id,
    );
}

/** Every invited anggota confirms: the team waits for the approval (menunggu_kepsek). */
function rvWaiting(object $t, array $members): Registration
{
    $registration = rvTeam($t, $members);

    foreach ($members as $member) {
        app(ConfirmMembership::class)->handle(F::user($member), $registration->fresh());
    }

    return $registration->fresh();
}

/** Approved by the Kepala Sekolah (disetujui), optionally moved on to $status (with a letter for surat_terbit). */
function rvApproved(object $t, array $members, RegistrationStatus $status = RegistrationStatus::Disetujui): Registration
{
    $registration = rvWaiting($t, $members);
    app(ApproveRegistration::class)->handle($t->kepsek, $registration);

    if ($status !== RegistrationStatus::Disetujui) {
        $registration->fresh()->forceFill(['status' => $status])->save();
    }

    return $registration->fresh();
}

function rvError(Closure $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('No ValidationException thrown.');
}

/** @return list<string> the student ids of the team (not declined), sorted */
function rvTeamIds(Registration $registration): array
{
    return $registration->members()->whereNull('declined_at')->pluck('student_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
}

function rvIds(Student ...$students): array
{
    return collect($students)->map(fn (Student $s): string => (string) $s->getKey())->sort()->values()->all();
}

function rvRequest(object $t, Registration $registration, array $add = [], array $remove = [], ?int $mentor = null, string $reason = 'Dimas sakit, diganti Keisha yang siap.'): RegistrationRevision
{
    return app(RequestRevision::class)->handle(
        F::user($t->nadia),
        $registration,
        array_map(fn (Student $s): string => (string) $s->getKey(), $add),
        array_map(fn (Student $s): string => (string) $s->getKey(), $remove),
        $mentor,
        $reason,
    );
}

// Before the approval: the change applies at once -----------------------------------------------------------------------

it('invites a new anggota and takes one out at once while the team waits for its members', function (): void {
    $registration = rvTeam($this, [$this->dimas, $this->keisha]);
    Notification::fake();

    $result = app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [(string) $this->rizky->getKey()], [(string) $this->keisha->getKey()], null);

    $registration->refresh();
    $rizkyRow = $registration->members()->where('student_id', $this->rizky->getKey())->first();

    expect($result['status'])->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(rvTeamIds($registration))->toBe(rvIds($this->nadia, $this->dimas, $this->rizky))
        ->and($rizkyRow->role)->toBe(MemberRole::Anggota)
        ->and($rizkyRow->invited_at)->not->toBeNull()
        ->and($rizkyRow->confirmed_at)->toBeNull()
        ->and(RegistrationRevision::query()->count())->toBe(0);

    Notification::assertSentTo(F::user($this->rizky), TeamInvitation::class);
    Notification::assertSentTo(F::user($this->keisha), RemovedFromTeam::class, fn (RemovedFromTeam $n): bool => $n->deciderRole === null
        && str_contains($n->toArray(F::user($this->keisha))['message'], 'Nadia Putri Anindya mengeluarkanmu dari tim'));
    Notification::assertNotSentTo(F::user($this->dimas), RemovedFromTeam::class);

    $audit = Activity::query()->where('event', ChangeRegistration::EVENT)->sole();
    expect($audit->properties['added'])->toBe([(string) $this->rizky->getKey()])
        ->and($audit->properties['removed'])->toBe([(string) $this->keisha->getKey()])
        ->and(json_encode($audit->properties))->not->toContain('Keisha')
        ->and(AuditLog::EVENT_LABELS[ChangeRegistration::EVENT])->toBe('Pendaftaran diubah ketua sebelum disetujui');
});

it('sends a registration waiting for the approval back to its members when it invites someone new', function (): void {
    $registration = rvWaiting($this, [$this->dimas]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::fake();

    app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [(string) $this->keisha->getKey()], [], null);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(Registration::query()->awaitingPrincipal()->count())->toBe(0);
    Notification::assertSentTo(F::user($this->keisha), TeamInvitation::class);

    // The approver who still has the old screen open reads why it left the queue.
    expect(rvError(fn () => app(ApproveRegistration::class)->handle($this->kepsek, $registration->fresh())))
        ->toBe(ApproveRegistration::MSG_BACK_TO_MEMBERS);

    // Keisha accepts: the team goes back to the approvers, who are told again.
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo($this->tu, RegistrationAwaitingPrincipal::class);
});

it('keeps a registration in the approval queue when the ketua only removes a member or changes the guru', function (): void {
    $registration = rvWaiting($this, [$this->dimas, $this->keisha]);
    Notification::fake();

    app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [], [(string) $this->keisha->getKey()], $this->retno->id);

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and((int) $registration->mentor_id)->toBe((int) $this->retno->id)
        ->and(rvTeamIds($registration))->toBe(rvIds($this->nadia, $this->dimas));

    Notification::assertSentTo(F::user($this->keisha), RemovedFromTeam::class);
    // A guru change before the approval tells nobody (the guru hears with the approval, D-132); nor the approvers again.
    Notification::assertNothingSentTo($this->retno);
    Notification::assertNothingSentTo($this->hendra);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);

    $mentorRow = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole();
    expect($mentorRow->properties['source'])->toBe(ChangeRegistration::MENTOR_SOURCE)
        ->and($mentorRow->properties['from'])->toBe((int) $this->hendra->id)
        ->and($mentorRow->properties['to'])->toBe((int) $this->retno->id);

    // Approved without another guru: the screens do not say "Ditetapkan …" for the ketua's own choice.
    app(ApproveRegistration::class)->handle($this->kepsek, $registration->fresh());
    expect(ApproveRegistration::mentorWasReplaced($registration->fresh()))->toBeFalse();
});

it('forwards the team for the approval when the ketua takes out the last open invitation', function (): void {
    $registration = rvTeam($this, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    Notification::fake();

    $result = app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration->fresh(), [], [(string) $this->keisha->getKey()], null);

    expect($result['ready'])->toBeTrue()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo(F::user($this->keisha), RemovedFromTeam::class);
});

it('tells nobody while the lomba waits for Kesiswaan (D-148)', function (): void {
    $own = F::pendingCompetition(F::user($this->nadia), F::usulanAttributes([
        'participation' => Participation::Tim,
        'max_team_size' => 4,
    ]));
    $registration = rvTeam($this, [$this->dimas], $own);
    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);
    Notification::fake();

    app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [(string) $this->keisha->getKey()], [(string) $this->dimas->getKey()], null);

    $keisha = $registration->members()->where('student_id', $this->keisha->getKey())->sole();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($keisha->invited_at)->toBeNull()
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->keisha));
    Notification::assertNothingSent();
});

it('refuses a change that breaks the team rules', function (): void {
    $registration = rvTeam($this, [$this->dimas]);
    $user = F::user($this->nadia);
    $change = fn (array $add = [], array $remove = [], ?int $mentor = null) => rvError(fn () => app(ChangeRegistration::class)
        ->handle($user, $registration->fresh(), array_map(fn (Student $s) => (string) $s->getKey(), $add), array_map(fn (Student $s) => (string) $s->getKey(), $remove), $mentor));

    $hana = F::student('Hana Safira', 'XI-3');
    $bayu = F::student('Bayu Pratama', 'XI-2');
    rvTeam((object) ['nadia' => $bayu, 'hendra' => $this->hendra, 'lomba' => $this->lomba], [F::student('Citra Ayu', 'XI-5')]);

    expect($change())->toBe(RevisionRules::MSG_NOTHING)
        ->and($change([], [], $this->hendra->id))->toBe(RevisionRules::MSG_NOTHING)
        ->and($change([], [$this->dimas]))->toBe(RevisionRules::MSG_TOO_FEW)
        ->and($change([], [$this->nadia]))->toBe(RevisionRules::MSG_LEADER_REMOVE)
        ->and($change([$this->nadia]))->toBe(RevisionRules::MSG_LEADER_ADD)
        ->and($change([], [$this->keisha]))->toBe(RevisionRules::MSG_NOT_MEMBER)
        ->and($change([$this->dimas]))->toBe('Dimas Arya Nugroho sudah ada di tim ini.')
        ->and($change([$bayu]))->toBe('Bayu Pratama sudah terdaftar di lomba ini.')
        ->and($change([$this->keisha, $this->rizky, $hana]))->toBe('Tim maksimal 4 orang, termasuk ketua.')
        ->and(rvError(fn () => app(ChangeRegistration::class)->handle($user, $registration->fresh(), [], [], 999999)))->toBe(ApproveRegistration::MSG_MENTOR);

    // Someone who declined this team is not invited again (the rule of InviteMembers).
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    expect($change([$this->dimas]))->toBe('Dimas Arya Nugroho sudah menolak undangan tim ini.');
});

it('refuses an individu registration any member and changes only its guru', function (): void {
    $lomba = F::openCompetition(['name' => 'Olimpiade Sains Nasional 2026']);
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), $lomba, RegistrationType::Individu, [], $this->hendra->id);

    expect(rvError(fn () => app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [(string) $this->dimas->getKey()], [], null)))
        ->toBe(RevisionRules::MSG_INDIVIDU);

    app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [], [], $this->retno->id);

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->id)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('invites nobody after the registration deadline but still removes and changes the guru', function (): void {
    $registration = rvWaiting($this, [$this->dimas, $this->keisha]);
    $this->lomba->forceFill(['registration_closes_at' => now()->subDay()])->save();

    expect(rvError(fn () => app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration->fresh(), [(string) $this->rizky->getKey()], [], null)))
        ->toBe(RevisionRules::MSG_WINDOW);

    app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration->fresh(), [], [(string) $this->keisha->getKey()], $this->retno->id);

    expect(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas))
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('lets only the ketua change it, and sends an approved registration to the revision', function (): void {
    $registration = rvWaiting($this, [$this->dimas]);

    expect(fn () => app(ChangeRegistration::class)->handle(F::user($this->dimas), $registration, [], [], $this->retno->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeRegistration::class)->handle($this->kesiswaan, $registration, [], [], $this->retno->id))
        ->toThrow(AuthorizationException::class)
        ->and(Gate::forUser(F::user($this->nadia))->allows('change', $registration))->toBeTrue()
        ->and(Gate::forUser(F::user($this->nadia))->allows('requestRevision', $registration))->toBeFalse();

    app(ApproveRegistration::class)->handle($this->kepsek, $registration);

    expect(rvError(fn () => app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration->fresh(), [], [], $this->retno->id)))
        ->toBe(RevisionRules::MSG_NOW_APPROVED)
        ->and(Gate::forUser(F::user($this->nadia))->allows('change', $registration->fresh()))->toBeFalse()
        ->and(Gate::forUser(F::user($this->nadia))->allows('requestRevision', $registration->fresh()))->toBeTrue()
        // An anggota never asks for a revision; only the ketua does.
        ->and(Gate::forUser(F::user($this->dimas))->allows('requestRevision', $registration->fresh()))->toBeFalse()
        ->and(fn () => app(RequestRevision::class)->handle(F::user($this->dimas), $registration->fresh(), [], [], $this->retno->id, 'Guru pembimbing diganti.'))
        ->toThrow(AuthorizationException::class);
});

// After the approval: a revision ------------------------------------------------------------------------------------------

it('stores a revision and leaves the approved team as it is until the decision', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    Notification::fake();

    $revision = rvRequest($this, $registration, [$this->keisha], [$this->dimas], $this->retno->id);

    expect($revision->status)->toBe(RevisionStatus::Pending)
        ->and($revision->additions())->toBe([(string) $this->keisha->getKey()])
        ->and($revision->removals())->toBe([(string) $this->dimas->getKey()])
        ->and($revision->mentorTo())->toBe((int) $this->retno->id)
        ->and($revision->mentorFrom())->toBe((int) $this->hendra->id)
        ->and($revision->ready_at)->toBeNull()
        ->and((int) $revision->requested_by)->toBe((int) F::user($this->nadia)->id)
        // The approved team and its guru stay valid while the revision waits.
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas))
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->id)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);

    Notification::assertSentTo(F::user($this->keisha), RevisionInvitation::class, fn (RevisionInvitation $n): bool => $n->revisionId === (string) $revision->getKey());
    // Not ready yet: Keisha accepts first.
    Notification::assertNotSentTo($this->tu, RevisionAwaitingDecision::class);
    Notification::assertNothingSentTo(F::user($this->dimas));

    $audit = Activity::query()->where('event', RequestRevision::EVENT)->sole();
    expect($audit->properties['revision_id'])->toBe((string) $revision->getKey())
        ->and(json_encode($audit->properties))->not->toContain('sakit');
});

it('asks Tata Usaha and Kesiswaan at once for a revision that invites nobody', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    Notification::fake();

    $revision = rvRequest($this, $registration, mentor: $this->retno->id);

    expect($revision->ready_at)->not->toBeNull()
        ->and(RegistrationRevision::query()->awaitingDecision()->count())->toBe(1);
    Notification::assertSentTo([$this->tu, $this->kesiswaan], RevisionAwaitingDecision::class);
    Notification::assertNotSentTo([$this->kepsek, $this->admin, $this->humas], RevisionAwaitingDecision::class);
});

it('refuses a reason shorter than 10 or longer than 500 characters', function (): void {
    $registration = rvApproved($this, [$this->dimas]);

    expect(rvError(fn () => rvRequest($this, $registration, mentor: $this->retno->id, reason: 'Ganti')))->toBe(RevisionRules::MSG_REASON_SHORT)
        ->and(rvError(fn () => rvRequest($this, $registration, mentor: $this->retno->id, reason: str_repeat('a', 501))))->toBe(RevisionRules::MSG_REASON_LONG)
        ->and(RegistrationRevision::query()->count())->toBe(0);
});

it('keeps one pending revision per registration', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    rvRequest($this, $registration, mentor: $this->retno->id);

    expect(Gate::forUser(F::user($this->nadia))->allows('requestRevision', $registration->fresh()))->toBeFalse()
        ->and(rvError(fn () => rvRequest($this, $registration->fresh(), [$this->keisha])))->toBe(RevisionRules::MSG_PENDING)
        ->and(RegistrationRevision::query()->pending()->count())->toBe(1);
});

it('accepts a revision in every status between the approval and the report, and refuses it after', function (RegistrationStatus $status, ?string $refusal): void {
    $registration = rvApproved($this, [$this->dimas], $status);

    if ($refusal === null) {
        expect(rvRequest($this, $registration, mentor: $this->retno->id)->status)->toBe(RevisionStatus::Pending);

        return;
    }

    expect(Gate::forUser(F::user($this->nadia))->allows('requestRevision', $registration))->toBeFalse()
        ->and(Gate::forUser(F::user($this->nadia))->allows('change', $registration))->toBeFalse()
        ->and(rvError(fn () => rvRequest($this, $registration, mentor: $this->retno->id)))->toBe($refusal)
        ->and(rvError(fn () => app(ChangeRegistration::class)->handle(F::user($this->nadia), $registration, [], [], $this->retno->id)))->toBe($refusal);
})->with([
    'disetujui' => [RegistrationStatus::Disetujui, null],
    'surat_terbit' => [RegistrationStatus::SuratTerbit, null],
    'berlangsung' => [RegistrationStatus::Berlangsung, null],
    'menunggu_laporan' => [RegistrationStatus::MenungguLaporan, null],
    'laporan_dikirim' => [RegistrationStatus::LaporanDikirim, RevisionRules::MSG_REPORT_SENT],
    'selesai' => [RegistrationStatus::Selesai, RevisionRules::MSG_REPORT_SENT],
    'dibatalkan' => [RegistrationStatus::Dibatalkan, RevisionRules::MSG_CANCELLED],
    'ditolak' => [RegistrationStatus::Ditolak, RevisionRules::MSG_REJECTED],
]);

it('lets an invited student accept, which makes the revision ready for the decision', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha, $this->rizky]);
    Notification::fake();

    app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision);

    expect($revision->fresh()->answerOf((string) $this->keisha->getKey()))->toBe('confirmed')
        ->and($revision->fresh()->ready_at)->toBeNull();
    Notification::assertSentTo(F::user($this->nadia), RevisionAnswered::class, fn (RevisionAnswered $n): bool => $n->accepted && ! $n->ready);
    Notification::assertNotSentTo($this->tu, RevisionAwaitingDecision::class);

    // Only once, and only the invitee.
    expect(Gate::forUser(F::user($this->keisha))->allows('respond', $revision->fresh()))->toBeFalse()
        ->and(Gate::forUser(F::user($this->dimas))->allows('respond', $revision->fresh()))->toBeFalse()
        ->and(fn () => app(RespondToRevision::class)->confirm(F::user($this->dimas), $revision->fresh()))->toThrow(AuthorizationException::class);

    app(RespondToRevision::class)->confirm(F::user($this->rizky), $revision->fresh());

    expect($revision->fresh()->ready_at)->not->toBeNull()
        // Still not in the team: the decision comes first.
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas));
    Notification::assertSentTo([$this->tu, $this->kesiswaan], RevisionAwaitingDecision::class);
    Notification::assertSentTo(F::user($this->nadia), RevisionAnswered::class, fn (RevisionAnswered $n): bool => $n->ready);
});

it('ends the revision when an invited student declines, and tells the ketua and the other invitee', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha, $this->rizky]);
    Notification::fake();

    app(RespondToRevision::class)->decline(F::user($this->keisha), $revision);

    expect($revision->fresh()->status)->toBe(RevisionStatus::Cancelled)
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas))
        ->and(Gate::forUser(F::user($this->nadia))->allows('requestRevision', $registration->fresh()))->toBeTrue();
    Notification::assertSentTo(F::user($this->nadia), RevisionAnswered::class, fn (RevisionAnswered $n): bool => ! $n->accepted);
    Notification::assertSentTo(F::user($this->rizky), RevisionWithdrawn::class, fn (RevisionWithdrawn $n): bool => $n->why === RevisionWithdrawn::DECLINED);
    Notification::assertNotSentTo(F::user($this->keisha), RevisionWithdrawn::class);
    expect(Activity::query()->where('event', RespondToRevision::EVENT_DECLINED)->count())->toBe(1);
});

it('refuses the acceptance of a student who joined another team of the lomba meanwhile', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha]);
    rvTeam((object) ['nadia' => F::student('Bayu Pratama', 'XI-2'), 'hendra' => $this->hendra, 'lomba' => $this->lomba], [$this->keisha]);
    $other = Registration::query()->where('leader_student_id', '!=', $this->nadia->getKey())->sole();
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $other);

    expect(rvError(fn () => app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision)))->toBe(RespondToRevision::MSG_ELSEWHERE)
        ->and($revision->fresh()->ready_at)->toBeNull();
});

it('does not invite a student another pending revision of the lomba already invites', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    rvRequest($this, $registration, [$this->keisha]);

    $bayu = F::student('Bayu Pratama', 'XI-2');
    $citra = F::student('Citra Ayu', 'XI-5');
    $team = (object) ['nadia' => $bayu, 'hendra' => $this->hendra, 'lomba' => $this->lomba, 'kepsek' => $this->kepsek];
    $other = rvApproved($team, [$citra]);

    expect(rvError(fn () => rvRequest($team, $other, [$this->keisha])))->toBe('Keisha Larasati sedang diundang ke tim lain untuk lomba ini.');
});

it('applies an approved revision in one go and tells everyone but the replaced guru', function (): void {
    $registration = rvApproved($this, [$this->dimas, $this->rizky]);
    $revision = rvRequest($this, $registration, [$this->keisha], [$this->dimas], $this->retno->id);
    app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision);
    Notification::fake();

    app(ApproveRevision::class)->handle($this->tu, $revision->fresh(), 'Sudah dicek.');

    $registration->refresh();
    $keisha = $registration->members()->where('student_id', $this->keisha->getKey())->sole();

    expect($revision->fresh()->status)->toBe(RevisionStatus::Approved)
        ->and((int) $revision->fresh()->decided_by)->toBe((int) $this->tu->id)
        ->and($revision->fresh()->note)->toBe('Sudah dicek.')
        ->and(rvTeamIds($registration))->toBe(rvIds($this->nadia, $this->rizky, $this->keisha))
        ->and($keisha->role)->toBe(MemberRole::Anggota)
        ->and($keisha->confirmed_at)->not->toBeNull()
        ->and((int) $registration->mentor_id)->toBe((int) $this->retno->id)
        ->and($registration->status)->toBe(RegistrationStatus::Disetujui)
        // Not "Ditetapkan Tata Usaha": the ketua asked for this guru.
        ->and(ApproveRegistration::mentorWasReplaced($registration))->toBeFalse();

    Notification::assertSentTo(F::user($this->nadia), RevisionDecided::class, fn (RevisionDecided $n): bool => $n->audience === 'ketua' && $n->approved);
    Notification::assertSentTo(F::user($this->rizky), RevisionDecided::class, fn (RevisionDecided $n): bool => $n->audience === 'anggota');
    Notification::assertSentTo(F::user($this->keisha), RevisionDecided::class, fn (RevisionDecided $n): bool => $n->audience === 'baru');
    Notification::assertSentTo(F::user($this->dimas), RemovedFromTeam::class, fn (RemovedFromTeam $n): bool => $n->deciderRole === 'Tata Usaha');
    Notification::assertSentTo($this->retno, RevisionDecided::class, fn (RevisionDecided $n): bool => $n->audience === 'guru' && $n->mentorChanged);
    // D-134: the guru who was replaced is told nothing; no letter yet, so Tata Usaha gets no Koreksi notice either.
    Notification::assertNothingSentTo($this->hendra);
    Notification::assertNothingSentTo($this->tu);
    Notification::assertNotSentTo($this->kesiswaan, RevisionDecided::class);

    $audit = Activity::query()->where('event', ApproveRevision::EVENT)->sole();
    $mentor = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->where('properties->source', ApproveRevision::MENTOR_SOURCE)->sole();
    expect($audit->properties['added'])->toBe([(string) $this->keisha->getKey()])
        ->and($audit->properties['removed'])->toBe([(string) $this->dimas->getKey()])
        ->and($audit->properties['letters_issued'])->toBeFalse()
        ->and(json_encode($audit->properties))->not->toContain('dicek')
        ->and($mentor->properties['to'])->toBe((int) $this->retno->id)
        ->and(AuditLog::MENTOR_SOURCE_LABELS[ApproveRevision::MENTOR_SOURCE])->toBe('Guru pembimbing diganti lewat revisi');
});

it('tells Tata Usaha to make a Koreksi when the letters were issued, and leaves the letters alone', function (): void {
    $registration = rvApproved($this, [$this->dimas], RegistrationStatus::SuratTerbit);
    $letter = Letter::factory()->create(['registration_id' => $registration->getKey()]);
    $printed = $letter->fresh()->printed;
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);
    Notification::fake();

    app(ApproveRevision::class)->handle($this->kesiswaan, $revision);

    expect($letter->fresh()->revoked_at)->toBeNull()
        ->and($letter->fresh()->printed)->toBe($printed)
        ->and(Activity::query()->where('event', ApproveRevision::EVENT)->sole()->properties['letters_issued'])->toBeTrue();
    Notification::assertSentTo($this->tu, RevisionDecided::class, fn (RevisionDecided $n): bool => $n->audience === 'tu'
        && str_contains($n->toArray($this->tu)['message'], 'perlu dibuat Koreksi')
        && $n->deciderRole === 'Kesiswaan');
    // Pak Hendra stays without a word (replaced), Bu Retno is appointed.
    Notification::assertNothingSentTo($this->hendra);
    Notification::assertSentTo($this->retno, RevisionDecided::class);
});

it('refuses the approval while an invited student has not accepted', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha]);

    expect(rvError(fn () => app(ApproveRevision::class)->handle($this->tu, $revision)))->toBe(ApproveRevision::MSG_WAITING)
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Pending);
});

// 2026-11-09: a guru Kesiswaan chose after the revision was asked for is never overwritten ------------------------------

it('refuses a revision that changes the guru once Kesiswaan changed the guru since it was asked for', function (): void {
    $agus = F::staff(RoleName::Guru, 'Agus Prasetyo, S.Pd.');
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration->fresh(), $agus->id);
    Notification::fake();

    expect(ApproveRevision::MSG_MENTOR_CHANGED)->toBe('Guru pembimbing pendaftaran ini sudah diganti sejak revisi ini diajukan. Tolak revisi ini; ketua bisa mengajukan revisi baru.')
        ->and(ApproveRevision::mentorChangedSince($revision->fresh(), $registration->fresh()))->toBeTrue();

    foreach ([$this->tu, $this->kesiswaan] as $decider) {
        try {
            app(ApproveRevision::class)->handle($decider, $revision->fresh());
            $this->fail('The approval went through.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['revision' => [ApproveRevision::MSG_MENTOR_CHANGED]]);
        }
    }

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $agus->id)
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Pending)
        ->and(Activity::query()->where('event', ApproveRevision::EVENT)->exists())->toBeFalse();
    Notification::assertNothingSent();

    // Kesiswaan picked the very guru the revision asks for: still a choice made after it, so still refused.
    $other = rvApproved((object) ['nadia' => $b = F::student('Bayu Pratama', 'XI-2'), 'hendra' => $this->hendra, 'kepsek' => $this->kepsek, 'lomba' => $this->lomba], [F::student('Citra Ayu', 'XI-5')]);
    $sameGuru = rvRequest((object) ['nadia' => $b], $other, mentor: $this->retno->id);
    app(ChangeMentor::class)->handle($this->kesiswaan, $other->fresh(), $this->retno->id);

    expect(rvError(fn () => app(ApproveRevision::class)->handle($this->tu, $sameGuru->fresh())))->toBe(ApproveRevision::MSG_MENTOR_CHANGED);

    // Rejecting stays possible.
    app(RejectRevision::class)->handle($this->tu, $revision->fresh(), 'Guru pembimbing sudah diganti Kesiswaan.');
    expect($revision->fresh()->status)->toBe(RevisionStatus::Rejected);
});

it('still approves a revision that leaves the guru alone after Kesiswaan changed the guru', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha]);
    app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision);
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration->fresh(), $this->retno->id);

    expect(ApproveRevision::mentorChangedSince($revision->fresh(), $registration->fresh()))->toBeFalse();

    app(ApproveRevision::class)->handle($this->tu, $revision->fresh());

    expect($revision->fresh()->status)->toBe(RevisionStatus::Approved)
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas, $this->keisha))
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->id);
});

it('shows the stale guru choice on Revisi pendaftaran and answers "Setujui" with a warning toast', function (): void {
    $agus = F::staff(RoleName::Guru, 'Agus Prasetyo, S.Pd.');
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);
    $id = (string) $revision->getKey();
    $toast = fn (string $event, array $params): bool => $params['tone'] === 'warning'
        && $params['message'] === ApproveRevision::MSG_MENTOR_CHANGED.' Daftar sudah diperbarui.';

    // The question asked before Kesiswaan's change: the confirm step gets the refusal as a toast, the list redraws.
    $screen = Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class)->call('askApprove', $id)
        ->assertDispatched('sth-modal-open');
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration->fresh(), $agus->id);

    $screen->call('approve')
        ->assertDispatched('sth-toast', $toast)
        ->assertDispatched('sth-modal-close')
        ->assertSee(ApproveRevision::MSG_MENTOR_STALE.' Revisi ini hanya bisa ditolak.');

    // A fresh screen offers only "Tolak"; a forged "Setujui" gets the same toast and no question.
    Livewire::actingAs($this->kesiswaan)->test(RevisiPendaftaran::class)
        ->assertSee(ApproveRevision::MSG_MENTOR_STALE.' Revisi ini hanya bisa ditolak.')
        ->assertDontSee("askApprove('".$id."')", false)
        ->call('askApprove', $id)
        ->assertDispatched('sth-toast', $toast)
        ->assertNotDispatched('sth-modal-open');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Pending)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $agus->id);
});

it('rejects a revision with a note for the ketua and tells the invited students', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha], [], $this->retno->id);
    Notification::fake();

    expect(rvError(fn () => app(RejectRevision::class)->handle($this->kesiswaan, $revision, 'Tdk')))->toBe(RejectRevision::MSG_NOTE);

    app(RejectRevision::class)->handle($this->kesiswaan, $revision, 'Keisha sudah ikut lomba lain di hari yang sama.');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Rejected)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->id)
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas));
    Notification::assertSentTo(F::user($this->nadia), RevisionDecided::class, fn (RevisionDecided $n): bool => ! $n->approved
        && str_contains($n->toArray(F::user($this->nadia))['message'], 'Alasan: Keisha sudah ikut lomba lain'));
    Notification::assertSentTo(F::user($this->keisha), RevisionWithdrawn::class, fn (RevisionWithdrawn $n): bool => $n->why === RevisionWithdrawn::REJECTED
        && ! str_contains($n->toArray(F::user($this->keisha))['message'], 'hari yang sama'));
    Notification::assertNothingSentTo($this->retno);
    expect(json_encode(Activity::query()->where('event', RejectRevision::EVENT)->sole()->properties))->not->toContain('Keisha');
});

it('lets the ketua cancel a pending revision', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha]);
    Notification::fake();

    expect(fn () => app(CancelRevision::class)->handle(F::user($this->dimas), $revision))->toThrow(AuthorizationException::class);

    app(CancelRevision::class)->handle(F::user($this->nadia), $revision);

    expect($revision->fresh()->status)->toBe(RevisionStatus::Cancelled)
        ->and(rvTeamIds($registration->fresh()))->toBe(rvIds($this->nadia, $this->dimas))
        ->and(rvError(fn () => app(CancelRevision::class)->handle(F::user($this->nadia), $revision->fresh())))->toBe('Revisi ini sudah dibatalkan.')
        ->and(rvError(fn () => app(ApproveRevision::class)->handle($this->tu, $revision->fresh())))->toBe('Revisi ini sudah dibatalkan.')
        ->and(rvError(fn () => app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision->fresh())))->toBe(RespondToRevision::MSG_NOT_VALID);
    Notification::assertSentTo(F::user($this->keisha), RevisionWithdrawn::class, fn (RevisionWithdrawn $n): bool => $n->why === RevisionWithdrawn::CANCELLED);
    expect(Activity::query()->where('event', CancelRevision::EVENT)->count())->toBe(1);
});

it('lets the first decision win', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);

    app(ApproveRevision::class)->handle($this->tu, $revision);

    expect(rvError(fn () => app(RejectRevision::class)->handle($this->kesiswaan, $revision, 'Tidak perlu diganti.')))
        ->toBe('Revisi ini sudah disetujui oleh Sri Handayani (Tata Usaha).')
        ->and(rvError(fn () => app(ApproveRevision::class)->handle($this->kesiswaan, $revision)))
        ->toBe('Revisi ini sudah disetujui oleh Sri Handayani (Tata Usaha).')
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Approved);
});

it('refuses the approval once the report was sent, but still lets it be rejected', function (): void {
    $registration = rvApproved($this, [$this->dimas], RegistrationStatus::MenungguLaporan);
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);
    $registration->fresh()->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save();

    expect(rvError(fn () => app(ApproveRevision::class)->handle($this->tu, $revision)))->toBe(RevisionRules::MSG_REPORT_SENT);

    app(RejectRevision::class)->handle($this->tu, $revision, 'Laporan sudah dikirim.');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Rejected)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->id);
});

it('checks the team rules again at the approval', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, [$this->keisha]);
    app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision);
    // Kesiswaan lowers "Maks anggota" to 2 meanwhile.
    $this->lomba->forceFill(['max_team_size' => 2])->save();

    expect(rvError(fn () => app(ApproveRevision::class)->handle($this->tu, $revision->fresh())))->toBe('Tim maksimal 2 orang, termasuk ketua.')
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Pending);
});

it('lets only Tata Usaha and Kesiswaan decide a revision', function (string $who, bool $allowed): void {
    $registration = rvApproved($this, [$this->dimas]);
    $revision = rvRequest($this, $registration, mentor: $this->retno->id);
    $actor = match ($who) {
        'siswa' => F::user($this->nadia),
        'guru' => $this->hendra,
        default => $this->{$who},
    };

    expect(Gate::forUser($actor)->allows('viewQueue', RegistrationRevision::class))->toBe($allowed)
        ->and(Gate::forUser($actor)->allows('decide', $revision))->toBe($allowed)
        ->and($actor->can(Permission::RegistrationReviseDecide->value))->toBe($allowed);

    if ($allowed) {
        app(RejectRevision::class)->handle($actor, $revision, 'Belum perlu diganti.');
        expect($revision->fresh()->status)->toBe(RevisionStatus::Rejected);

        return;
    }

    expect(fn () => app(ApproveRevision::class)->handle($actor, $revision))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectRevision::class)->handle($actor, $revision, 'Belum perlu diganti.'))->toThrow(AuthorizationException::class)
        ->and($revision->fresh()->status)->toBe(RevisionStatus::Pending);
})->with([
    'Tata Usaha' => ['tu', true],
    'Kesiswaan' => ['kesiswaan', true],
    'Kepala Sekolah' => ['kepsek', false],
    'Admin' => ['admin', false],
    'Humas' => ['humas', false],
    'guru' => ['guru', false],
    'siswa (the ketua)' => ['siswa', false],
]);

it('gives the new permission to Tata Usaha and Kesiswaan only (re-run RoleSeeder)', function (): void {
    $holders = collect(RoleName::cases())
        ->filter(fn (RoleName $role): bool => in_array(Permission::RegistrationReviseDecide, $role->defaultPermissions(), true))
        ->map(fn (RoleName $role): string => $role->value)
        ->values()
        ->all();

    expect($holders)->toBe(['kesiswaan', 'tata_usaha'])
        ->and(Permission::RegistrationReviseDecide->value)->toBe('registration.revise_decide');
});

it('cascades the revisions with a deleted registration', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    rvRequest($this, $registration, mentor: $this->retno->id);

    DB::table('registration_members')->where('registration_id', $registration->getKey())->delete();
    DB::table('registrations')->where('id', $registration->getKey())->delete();

    expect(RegistrationRevision::query()->count())->toBe(0);
});

it('never lists the new members of a pending revision in the team before the approval', function (): void {
    $registration = rvApproved($this, [$this->dimas]);
    rvRequest($this, $registration, [$this->keisha]);

    expect(RegistrationMember::query()->where('student_id', $this->keisha->getKey())->exists())->toBeFalse()
        ->and(Registration::query()->forStudent($this->keisha)->exists())->toBeFalse();
    Notification::assertNotSentTo(F::user($this->keisha), RegistrationApproved::class);
});
