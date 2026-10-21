<?php

declare(strict_types=1);

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Lomba\Detail;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Registration;
use App\Notifications\Registrations\MemberResponded;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RegistrationCancelled;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->admin = F::staff(RoleName::Admin, 'Operator');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-D');
    $this->keisha = F::student('Keisha Larasati', 'XI-F');
    $this->rizky = F::student('Rizky Maulana', 'XI-A');
});

function w2a1Register(object $t, $student, $competition, RegistrationType $type, array $members = [], ?int $mentor = null): Registration
{
    return app(CreateRegistration::class)->handle(F::user($student), $competition, $type, array_map(fn ($s) => $s->getKey(), $members), $mentor ?? $t->guru->id);
}

it('sends an individu registration straight to approval and notifies the approvers (Kepala Sekolah and Tata Usaha), not Admin', function (): void {
    $lomba = F::openCompetition();
    $tu = F::staff(RoleName::TataUsaha, 'Tata Usaha Contoh');

    $registration = w2a1Register($this, $this->nadia, $lomba, RegistrationType::Individu);

    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->members()->count())->toBe(1);
    $leader = $registration->members()->first();
    expect($leader->role)->toBe(MemberRole::Ketua)->and($leader->confirmed_at)->not->toBeNull();

    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
    Notification::assertSentTo($tu, RegistrationAwaitingPrincipal::class); // D-150
    Notification::assertNotSentTo($this->admin, RegistrationAwaitingPrincipal::class); // D-01
    expect(Activity::query()->where('event', 'registration.created')->exists())->toBeTrue();
});

it('creates a team waiting for confirmation and invites every member', function (): void {
    $lomba = F::teamCompetition(3);

    $registration = w2a1Register($this, $this->nadia, $lomba, RegistrationType::Tim, [$this->dimas, $this->keisha]);

    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($registration->members()->where('role', MemberRole::Anggota)->whereNull('confirmed_at')->count())->toBe(2);
    Notification::assertSentTo(F::user($this->dimas), TeamInvitation::class);
    Notification::assertSentTo(F::user($this->keisha), TeamInvitation::class);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('rejects registrations that break the lomba rules', function (callable $arrange, string $field): void {
    [$lomba, $type, $members, $mentor] = $arrange($this);

    try {
        w2a1Register($this, $this->nadia, $lomba, $type, $members, $mentor);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(Registration::query()->count())->toBe(0);
})->with([
    'tim on an individu lomba' => [fn ($t) => [F::openCompetition(), RegistrationType::Tim, [$t->dimas], null], 'type'],
    'individu on a tim lomba' => [fn ($t) => [F::teamCompetition(), RegistrationType::Individu, [], null], 'type'],
    'team without members' => [fn ($t) => [F::teamCompetition(), RegistrationType::Tim, [], null], 'members'],
    'team larger than max' => [fn ($t) => [F::teamCompetition(2), RegistrationType::Tim, [$t->dimas, $t->keisha], null], 'members'],
    'mentor is not a guru' => [fn ($t) => [F::openCompetition(), RegistrationType::Individu, [], $t->kepsek->id], 'mentor'],
    'mentor missing' => [fn ($t) => [F::openCompetition(), RegistrationType::Individu, [], 0], 'mentor'],
]);

it('refuses a lomba whose registration window is closed or not yet open', function (array $window): void {
    $lomba = F::openCompetition($window);

    expect(fn () => w2a1Register($this, $this->nadia, $lomba, RegistrationType::Individu))->toThrow(AuthorizationException::class);
})->with([
    'closed' => [['registration_closes_at' => now()->subDay()]],
    'not yet open' => [['registration_opens_at' => now()->addDays(3)]],
]);

it('refuses an unpublished lomba of another submitter', function (): void {
    $lomba = F::pendingCompetition(F::user($this->dimas), F::usulanAttributes());

    expect(fn () => w2a1Register($this, $this->nadia, $lomba, RegistrationType::Individu))->toThrow(AuthorizationException::class);
});

it('blocks a second registration of the same student for the same lomba', function (): void {
    $lomba = F::teamCompetition(3);
    w2a1Register($this, $this->nadia, $lomba, RegistrationType::Tim, [$this->dimas]);

    // Nadia again (policy: busy) …
    expect(fn () => w2a1Register($this, $this->nadia, $lomba, RegistrationType::Tim, [$this->keisha]))->toThrow(AuthorizationException::class);

    // … and Keisha inviting Dimas, who is already invited elsewhere.
    try {
        w2a1Register($this, $this->keisha, $lomba, RegistrationType::Tim, [$this->dimas]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['members'][0])->toContain('Dimas Arya Nugroho');
    }
});

it('only invites active students', function (): void {
    $lomba = F::teamCompetition(3);
    F::user($this->keisha)->forceFill(['status' => UserStatus::Archived])->save();

    expect(fn () => w2a1Register($this, $this->nadia, $lomba, RegistrationType::Tim, [$this->keisha]))->toThrow(ValidationException::class);
});

it('moves the team to the Kepala Sekolah when every member confirmed', function (): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);

    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertSentTo(F::user($this->nadia), MemberResponded::class, fn ($n) => $n->accepted);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);

    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('lets only the invitee answer an invitation', function (string $who): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas]);
    $actor = match ($who) {
        'leader' => $this->nadia,
        'outsider' => $this->rizky,
    };

    expect(fn () => app(ConfirmMembership::class)->handle(F::user($actor), $registration))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeclineMembership::class)->handle(F::user($actor), $registration))->toThrow(AuthorizationException::class);
    expect($registration->members()->whereNotNull('confirmed_at')->count())->toBe(1);
})->with(['leader', 'outsider']);

it('does not accept a confirmation twice or after the registration window', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = w2a1Register($this, $this->nadia, $lomba, RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);

    expect(fn () => app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration))->toThrow(AuthorizationException::class);

    // QA10: RegistrationPolicy::respond checks the deadline too (so "Ikut tim" only shows while it works): refused there.
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();
    expect(fn () => app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration))->toThrow(AuthorizationException::class);
});

it('continues without a member who declines when the rest confirmed', function (): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);

    app(DeclineMembership::class)->handle(F::user($this->keisha), $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->members()->whereNotNull('declined_at')->count())->toBe(1);
    Notification::assertSentTo(F::user($this->nadia), MemberResponded::class, fn ($n) => ! $n->accepted);
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);
});

it('keeps waiting when only the leader is left', function (): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas]);

    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
    Notification::assertNotSentTo($this->kepsek, RegistrationAwaitingPrincipal::class);

    // A declined student is free to join another team of the same lomba.
    $other = w2a1Register($this, $this->keisha, $registration->competition, RegistrationType::Tim, [$this->dimas]);
    expect($other->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
});

it('lets the leader cancel while waiting for members or the Kepala Sekolah', function (RegistrationStatus $status): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas]);
    $registration->forceFill(['status' => $status])->save();

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

    $fresh = $registration->fresh();
    expect($fresh->status)->toBe(RegistrationStatus::Dibatalkan)->and($fresh->cancelled_at)->not->toBeNull();
    Notification::assertSentTo(F::user($this->dimas), RegistrationCancelled::class, fn (RegistrationCancelled $n) => $n->audience === 'siswa');
    Notification::assertSentTo($this->guru, RegistrationCancelled::class, fn (RegistrationCancelled $n) => $n->audience === 'guru');
    Notification::assertNotSentTo(F::user($this->nadia), RegistrationCancelled::class);
    Notification::assertNotSentTo($this->admin, RegistrationCancelled::class);

    // The Kepala Sekolah only hears about a cancel of something they were asked to approve.
    $status === RegistrationStatus::MenungguKepsek
        ? Notification::assertSentTo($this->kepsek, RegistrationCancelled::class, fn (RegistrationCancelled $n) => $n->audience === 'kepsek')
        : Notification::assertNotSentTo($this->kepsek, RegistrationCancelled::class);

    // Everyone may register again afterwards.
    expect(w2a1Register($this, $this->nadia, $registration->competition, RegistrationType::Tim, [$this->dimas])->status)
        ->toBe(RegistrationStatus::MenungguKonfirmasi);
})->with([RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek]);

it('does not let a member or a decided registration be cancelled', function (): void {
    $registration = w2a1Register($this, $this->nadia, F::teamCompetition(3), RegistrationType::Tim, [$this->dimas]);

    expect(fn () => app(CancelRegistration::class)->handle(F::user($this->dimas), $registration))->toThrow(AuthorizationException::class);

    $registration->forceFill(['status' => RegistrationStatus::Disetujui])->save();
    expect(fn () => app(CancelRegistration::class)->handle(F::user($this->nadia), $registration))->toThrow(AuthorizationException::class);
});

it('registers for a lomba open to both individu and tim either way', function (): void {
    $lomba = F::openCompetition(['participation' => Participation::Keduanya, 'max_team_size' => 3]);

    expect(w2a1Register($this, $this->nadia, $lomba, RegistrationType::Individu)->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and(w2a1Register($this, $this->keisha, $lomba, RegistrationType::Tim, [$this->rizky])->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
});

// QA6: explore round 3 after PR 14 (2026-10-07) ----------------------------------------------------

it('updates "N siswa SMAGA sudah mendaftar" on Detail lomba right after registering or cancelling (QA6)', function (): void {
    $lomba = F::openCompetition();

    $detail = Livewire::actingAs(F::user($this->nadia))->test(Detail::class, ['competition' => $lomba])
        ->assertDontSee('siswa SMAGA sudah mendaftar');

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $this->guru->id)
        ->set('setuju', true)
        ->set('phone', '081234567890')
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertDispatched('pendaftaran-berubah')
        ->call('batalkan')
        ->assertDispatched('pendaftaran-berubah');

    w2a1Register($this, $this->dimas, $lomba, RegistrationType::Individu);

    // The panel is a child component: the page reads the count again when the panel says so.
    $detail->dispatch('pendaftaran-berubah')->assertSee('1</span> siswa SMAGA sudah mendaftar', false);
});
