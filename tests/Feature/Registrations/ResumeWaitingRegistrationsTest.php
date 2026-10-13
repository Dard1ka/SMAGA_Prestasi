<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionRejected;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RegistrationCancelled;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 Paket B (D-148, part 2): when Kesiswaan decides a lomba, its waiting registrations (`menunggu_lomba`) move in the
 * same transaction. Published → individu to the approvers, tim invites its anggota; rejected → cancelled, and the
 * submitter gets ONE notice naming both. Notices after commit. Fictional data only.
 */

uses(RefreshDatabase::class);

const RWR_BASE_BODY = 'Kesiswaan sudah memverifikasi lomba yang dikirim. Lomba ini sekarang tampil di katalog.';

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Kesiswaan Contoh');
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    $this->rizkyUser = F::user($this->rizky);
});

/** Nadia's queued lomba with her waiting registration (individu, or a team with Rizky). */
function rwrWaiting(object $t, bool $team = false, array $attributes = []): Registration
{
    $lomba = F::pendingCompetition($t->nadiaUser, F::usulanAttributes(array_merge(
        $team ? ['participation' => Participation::Tim, 'max_team_size' => 3] : [],
        $attributes,
    )));

    return app(CreateRegistration::class)->handle(
        $t->nadiaUser,
        $lomba,
        $team ? RegistrationType::Tim : RegistrationType::Individu,
        $team ? [$t->rizky->getKey()] : [],
        $t->guru->getKey(),
    );
}

function rwrDecide(object $t, Registration $registration, CompetitionStatus $decision, bool $confirmPastDates = false): Competition
{
    return app(VerifyCompetition::class)->handle($t->kesiswaan, Competition::query()->findOrFail($registration->competition_id), $decision, null, $confirmPastDates);
}

it('sends an individu registration on to the approvers when the lomba is published', function (): void {
    $r = rwrWaiting($this);

    rwrDecide($this, $r, CompetitionStatus::Dipublikasikan);

    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class, fn ($n) => $n->registration->is($r));
    Notification::assertNotSentTo($this->kesiswaan, RegistrationAwaitingPrincipal::class);
    Notification::assertSentToTimes($this->nadiaUser, CompetitionPublished::class, 1);
    Notification::assertSentTo($this->nadiaUser, CompetitionPublished::class,
        fn (CompetitionPublished $n) => $n->body === RWR_BASE_BODY.' Pendaftaranmu diteruskan untuk persetujuan.');

    $audit = Activity::query()->where('event', 'registration.resumed')->sole();
    expect($audit->subject_id)->toBe($r->getKey())
        ->and($audit->causer_id)->toBe($this->kesiswaan->getKey())
        ->and($audit->properties->all())->toBe(['from' => 'menunggu_lomba', 'to' => 'menunggu_kepsek', 'competition_id' => $r->competition_id]);
});

it('invites the team when the lomba is published', function (): void {
    $this->travelTo(now()->startOfMinute());
    $r = rwrWaiting($this, team: true);

    rwrDecide($this, $r, CompetitionStatus::Dipublikasikan);

    $member = $r->members()->where('role', MemberRole::Anggota)->sole();
    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($member->invited_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(Gate::forUser($this->rizkyUser)->allows('respond', $r->fresh()))->toBeTrue();
    Notification::assertSentTo($this->rizkyUser, TeamInvitation::class, fn ($n) => $n->registration->is($r));
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo($this->nadiaUser, CompetitionPublished::class,
        fn (CompetitionPublished $n) => $n->body === RWR_BASE_BODY.' Undangan tim sudah dikirim ke anggota.');

    // The invitation works: Rizky joins, the team goes on to the approvers.
    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());
    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);

    // The anggota now sees the team in Lomba saya.
    $this->actingAs($this->rizkyUser)->get(route('siswa.lomba-saya.show', $r))->assertOk();
});

it('cancels waiting registrations of a rejected lomba with one combined notice', function (): void {
    $r = rwrWaiting($this, team: true);

    rwrDecide($this, $r, CompetitionStatus::Ditolak);

    expect($r->fresh()->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and($r->fresh()->cancelled_at)->not->toBeNull();
    Notification::assertSentToTimes($this->nadiaUser, CompetitionRejected::class, 1);
    Notification::assertSentTo($this->nadiaUser, CompetitionRejected::class,
        fn (CompetitionRejected $n) => $n->body === CompetitionRejected::NO_REASON.' Pendaftaranmu untuk lomba ini ikut dibatalkan.');
    Notification::assertNotSentTo($this->nadiaUser, RegistrationCancelled::class);
    Notification::assertNothingSentTo($this->rizkyUser);
    Notification::assertNothingSentTo($this->guru);
    Notification::assertNothingSentTo($this->kepsek);

    $audit = Activity::query()->where('event', 'registration.cancelled')->sole();
    expect($audit->causer_id)->toBe($this->kesiswaan->getKey())
        ->and($audit->properties->all())->toBe(['from' => 'menunggu_lomba', 'reason' => 'competition_rejected']);

    // Lomba saya says why, and the anggota who was never invited still does not see it.
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $r))
        ->assertOk()->assertSee('Usulan lomba ini ditolak Kesiswaan, jadi pendaftarannya ikut dibatalkan.')
        ->assertDontSee('Kamu bisa mendaftar lagi selama pendaftaran lomba masih dibuka.');
    $this->actingAs($this->rizkyUser)->get(route('siswa.lomba-saya.show', $r))->assertNotFound();
});

it('names the reason and the cancelled registration in the one rejection notice', function (): void {
    $r = rwrWaiting($this);

    app(VerifyCompetition::class)->handle($this->kesiswaan, Competition::query()->findOrFail($r->competition_id), CompetitionStatus::Ditolak, 'Lomba ini sudah lewat.');

    Notification::assertSentTo($this->nadiaUser, CompetitionRejected::class,
        fn (CompetitionRejected $n) => $n->body === 'Alasan Kesiswaan: Lomba ini sudah lewat. Pendaftaranmu untuk lomba ini ikut dibatalkan.');
});

it('leaves a registration the leader cancelled while the lomba waited alone', function (CompetitionStatus $decision): void {
    $r = rwrWaiting($this, team: true);
    app(CancelRegistration::class)->handle($this->nadiaUser, $r);

    rwrDecide($this, $r, $decision);

    expect($r->fresh()->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and(Activity::query()->whereIn('event', ['registration.resumed'])->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'registration.cancelled')->count())->toBe(1);
    Notification::assertNothingSentTo($this->rizkyUser);
    Notification::assertNothingSentTo($this->kepsek);
    Notification::assertNothingSentTo($this->guru);

    if ($decision === CompetitionStatus::Dipublikasikan) {
        Notification::assertSentTo($this->nadiaUser, CompetitionPublished::class, fn (CompetitionPublished $n) => $n->body === RWR_BASE_BODY);
    } else {
        Notification::assertSentTo($this->nadiaUser, CompetitionRejected::class, fn (CompetitionRejected $n) => $n->body === CompetitionRejected::NO_REASON);
    }
})->with([
    'published' => [CompetitionStatus::Dipublikasikan],
    'rejected' => [CompetitionStatus::Ditolak],
]);

it('continues waiting registrations even when the registration deadline passed before publishing', function (): void {
    $individu = rwrWaiting($this);
    $team = rwrWaiting($this, team: true, attributes: ['name' => 'Lomba Robotik Contoh 2027']);

    $this->travelTo(now()->addDays(21));

    rwrDecide($this, $individu, CompetitionStatus::Dipublikasikan, confirmPastDates: true);
    rwrDecide($this, $team, CompetitionStatus::Dipublikasikan, confirmPastDates: true);

    expect($individu->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($team->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(Registration::query()->where('status', RegistrationStatus::Dibatalkan)->exists())->toBeFalse();
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo($this->rizkyUser, TeamInvitation::class);
});

it('rolls everything back when publishing is refused', function (): void {
    $r = rwrWaiting($this, team: true, attributes: ['location' => null]);

    expect(fn () => rwrDecide($this, $r, CompetitionStatus::Dipublikasikan))->toThrow(ValidationException::class);

    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($r->members()->where('role', MemberRole::Anggota)->sole()->invited_at)->toBeNull()
        ->and(Activity::query()->where('event', 'registration.resumed')->exists())->toBeFalse();
    Notification::assertNothingSent();
});
