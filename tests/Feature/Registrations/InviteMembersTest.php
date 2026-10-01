<?php

declare(strict_types=1);

use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Actions\Registrations\InviteMembers;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Jobs\AutoPublishPublications;
use App\Jobs\ExpireInvitations;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * B3 · backlog #9: InviteMembers (replacement after a decline, D-36) + invitation expiry at
 * registration_closes_at (ExpirePendingInvitations / ExpireInvitations job). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    $this->hana = F::student('Hana Safira', 'X-3');
});

/** Team registration of Nadia (ketua) with the given anggota invited. */
function b3Team(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        RegistrationType::Tim,
        array_map(fn ($s) => $s->getKey(), $members),
        $t->guru->id,
    );
}

function b3Invite(object $t, Registration $registration, array $students, $as = null): Registration
{
    return app(InviteMembers::class)->handle(F::user($as ?? $t->nadia), $registration, array_map(fn ($s) => $s->getKey(), $students));
}

function b3MembersError(Closure $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) ($e->errors()['members'][0] ?? array_values($e->errors())[0][0]);
    }

    throw new RuntimeException('No validation error.');
}

// InviteMembers ------------------------------------------------------------------------------------

it('lets the ketua invite a replacement after a decline; the team goes on once everyone confirmed', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);

    b3Invite($this, $registration->fresh(), [$this->rizky]);

    $row = RegistrationMember::query()->where('registration_id', $registration->getKey())->where('student_id', $this->rizky->getKey())->firstOrFail();
    expect($row->role)->toBe(MemberRole::Anggota)
        ->and($row->invited_at)->not->toBeNull()
        ->and($row->confirmed_at)->toBeNull()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    Notification::assertSentTo(F::user($this->rizky), TeamInvitation::class);
    $audit = Activity::query()->where('event', 'registration.members_invited')->firstOrFail();
    expect($audit->causer_id)->toBe(F::user($this->nadia)->id)
        ->and($audit->properties['student_ids'])->toBe([$this->rizky->getKey()]);

    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    app(ConfirmMembership::class)->handle(F::user($this->rizky), $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('rescues a team where only the ketua is left (D-36)', function (): void {
    $registration = b3Team($this, F::teamCompetition(2), [$this->dimas]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    b3Invite($this, $registration->fresh(), [$this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->members()->whereNull('declined_at')->count())->toBe(2);
});

it('keeps the CreateRegistration member rules when inviting', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);
    $registration = $registration->fresh();

    // Someone who declined this team cannot be invited again.
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->dimas])))->toContain('sudah menolak');
    // Already in the team.
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->keisha])))->toContain('sudah ada di tim');
    // The ketua herself.
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->nadia])))->toContain('ketua');
    // Team size: 2 left + 2 new > 3.
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->rizky, $this->hana])))->toContain('maksimal 3');
    // Not active (disabled account).
    $this->hana->user->forceFill(['status' => UserStatus::Disabled])->save();
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->hana])))->toContain('tidak aktif');
    // Already in another running registration of the same lomba.
    app(CreateRegistration::class)->handle(F::user($this->rizky), $lomba, RegistrationType::Tim, [F::student('Bima Satria Wicaksono')->getKey()], $this->guru->id);
    expect(b3MembersError(fn () => b3Invite($this, $registration, [$this->rizky])))->toContain('sudah terdaftar');
    // Nothing chosen.
    expect(b3MembersError(fn () => app(InviteMembers::class)->handle(F::user($this->nadia), $registration, [])))->toContain('Pilih siswa');

    expect($registration->members()->count())->toBe(3);
    Notification::assertNotSentTo(F::user($this->hana), TeamInvitation::class);
});

it('allows only the ketua, only while waiting for confirmations and registration is open', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = b3Team($this, $lomba, [$this->dimas, $this->keisha]);

    expect(fn () => b3Invite($this, $registration, [$this->rizky], $this->dimas))->toThrow(AuthorizationException::class);
    expect(F::user($this->dimas)->can('invite', $registration))->toBeFalse()
        ->and(F::user($this->nadia)->can('invite', $registration))->toBeTrue()
        ->and($this->guru->can('invite', $registration))->toBeFalse()
        ->and($this->kepsek->can('invite', $registration))->toBeFalse();

    // Registration window closed.
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();
    expect(fn () => b3Invite($this, $registration->fresh(), [$this->rizky]))->toThrow(AuthorizationException::class);

    // Team already went to the Kepala Sekolah.
    $lomba->forceFill(['registration_closes_at' => now()->addDays(5)])->save();
    $registration->forceFill(['status' => RegistrationStatus::MenungguKepsek])->save();
    expect(fn () => b3Invite($this, $registration->fresh(), [$this->rizky]))->toThrow(AuthorizationException::class);

    // Individu registrations have no team.
    $solo = app(CreateRegistration::class)->handle(F::user($this->hana), F::openCompetition(), RegistrationType::Individu, [], $this->guru->id);
    expect(F::user($this->hana)->can('invite', $solo))->toBeFalse();
});

// Screens ------------------------------------------------------------------------------------------

it('shows the team and invites a replacement from the registration panel', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);

    $form = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Undang anggota pengganti')
        ->assertSee('Menolak')
        ->assertSee('sisa 1 tempat');

    $form->set('q', 'Dimas');
    expect($form->get('hasil')[0]['declined'])->toBeTrue();
    $form->call('undang', $this->dimas->getKey());
    expect($registration->members()->count())->toBe(3);

    $form->set('q', 'Rizky')
        ->call('undang', $this->rizky->getKey())
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', tone: 'success');

    expect($registration->members()->where('student_id', $this->rizky->getKey())->exists())->toBeTrue();
    Notification::assertSentTo(F::user($this->rizky), TeamInvitation::class);

    // Members see their status, never the invite controls.
    Livewire::actingAs(F::user($this->keisha))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertDontSee('Undang anggota pengganti');
});

it('links the ketua from Lomba saya to the invite panel', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas]);

    Livewire::actingAs(F::user($this->nadia))->test(LombaSaya::class, ['registration' => $registration])
        ->assertSee('Undang anggota pengganti')
        ->assertSee(route('siswa.lomba.show', $lomba->getKey()).'#h-daftar', false);

    Livewire::actingAs(F::user($this->dimas))->test(LombaSaya::class, ['registration' => $registration])
        ->assertDontSee('Undang anggota pengganti');
});

// Expiry ----------------------------------------------------------------------------------------------

it('expires open invitations after the deadline and sends a complete team to the Kepala Sekolah', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    // Still open: nothing is due.
    expect(ExpirePendingInvitations::due(now()->toImmutable())->count())->toBe(0)
        ->and(app(ExpirePendingInvitations::class)->handle($registration))->toBeNull();

    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();
    expect(ExpirePendingInvitations::due(now()->toImmutable())->pluck('id')->all())->toBe([$registration->getKey()]);

    $result = app(ExpirePendingInvitations::class)->handle($registration);

    expect($result)->toBe(['expired' => 1, 'ready' => true, 'team' => 2])
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->members()->where('student_id', $this->keisha->getKey())->value('declined_at'))->not->toBeNull();

    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo(F::user($this->nadia), InvitationsExpired::class, fn (InvitationsExpired $n): bool => $n->ready && $n->expired === 1);
    $audit = Activity::query()->where('event', 'registration.invitations_expired')->firstOrFail();
    expect($audit->causer_id)->toBeNull()->and($audit->properties['by'])->toBe('scheduler');

    // Idempotent.
    expect(app(ExpirePendingInvitations::class)->handle($registration))->toBeNull();
    expect(Activity::query()->where('event', 'registration.invitations_expired')->count())->toBe(1);
});

it('tells the ketua when the expired team is too small and keeps it waiting', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = b3Team($this, $lomba, [$this->dimas]);
    $lomba->forceFill(['registration_closes_at' => now()->subHour()])->save();

    app(ExpireInvitations::class)->handle(app(ExpirePendingInvitations::class));

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($registration->members()->whereNull('declined_at')->count())->toBe(1);
    Notification::assertSentTo(F::user($this->nadia), InvitationsExpired::class, function (InvitationsExpired $n): bool {
        return ! $n->ready && str_contains($n->message(), 'Batalkan pendaftaran');
    });
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);

    // The expired invitee can no longer answer; the ketua can still cancel.
    expect(F::user($this->dimas)->can('respond', $registration->fresh()))->toBeFalse()
        ->and(F::user($this->nadia)->can('cancel', $registration->fresh()))->toBeTrue();
});

it('schedules both B3 jobs every five minutes on one server', function (): void {
    $jobs = collect(app(Schedule::class)->events())
        ->filter(fn ($e): bool => $e instanceof CallbackEvent && in_array($e->description, [ExpireInvitations::class, AutoPublishPublications::class], true));

    expect($jobs)->toHaveCount(2);
    $jobs->each(fn (CallbackEvent $e) => expect($e->expression)->toBe('*/5 * * * *')
        ->and($e->withoutOverlapping)->toBeTrue()
        ->and($e->onOneServer)->toBeTrue());
});
