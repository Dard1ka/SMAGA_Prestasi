<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Registrations\TeamInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 Paket B, Task 10a (D-148, lead 2026-10-12): a team resumed after "Pendaftaran ditutup" (or less than three days
 * before it) may answer its invitations for three days from the publish moment (`registrations.answer_until`). A normal
 * team keeps the old rule: answers close at the lomba's registration deadline. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00'));
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

/** Nadia's waiting team (Rizky invited later) on her queued lomba whose registration closes at $closes. */
function rtaWaitingTeam(object $t, CarbonImmutable $closes): Registration
{
    $lomba = F::pendingCompetition($t->nadiaUser, F::usulanAttributes([
        'participation' => Participation::Tim,
        'max_team_size' => 3,
        'registration_closes_at' => $closes,
        'starts_on' => $closes->addDays(10)->format('Y-m-d'),
        'final_on' => $closes->addDays(10)->format('Y-m-d'),
    ]));

    return app(CreateRegistration::class)->handle($t->nadiaUser, $lomba, RegistrationType::Tim, [$t->rizky->getKey()], $t->guru->getKey());
}

function rtaPublish(object $t, Registration $registration): void
{
    app(VerifyCompetition::class)->handle($t->kesiswaan, Competition::query()->findOrFail($registration->competition_id), CompetitionStatus::Dipublikasikan, null, true);
}

it('gives a team resumed after the deadline three days to answer', function (): void {
    $r = rtaWaitingTeam($this, now()->toImmutable()->addDays(5)->endOfDay());
    $this->travelTo(now()->addDays(6)); // "Pendaftaran ditutup" passed one day ago

    rtaPublish($this, $r);

    $publishedAt = now()->toImmutable();
    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($r->fresh()->answer_until?->toDateTimeString())->toBe($publishedAt->addDays(3)->toDateTimeString())
        ->and($r->fresh()->answersCloseAt()?->toDateTimeString())->toBe($publishedAt->addDays(3)->toDateTimeString());

    // The invitation names the three days, not the deadline that already passed.
    $grace = $publishedAt->addDays(3)->locale('id')->translatedFormat('j M Y, H.i');
    Notification::assertSentTo($this->rizkyUser, TeamInvitation::class,
        fn (TeamInvitation $n): bool => str_contains(
            html_entity_decode(implode(' ', array_map('strval', $n->toMail($this->rizkyUser)->introLines))),
            'Konfirmasi sebelum '.$grace.' agar',
        ));

    // Two days later: still open for the anggota, and the expiry job leaves it alone.
    $this->travelTo($publishedAt->addDays(2));
    expect(Gate::forUser($this->rizkyUser)->allows('respond', $r->fresh()))->toBeTrue()
        ->and(ExpirePendingInvitations::due(CarbonImmutable::now())->pluck('id')->all())->toBe([])
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toBeNull();
    Livewire::actingAs($this->rizkyUser)->test(KonfirmasiTim::class, ['registration' => $r])
        ->assertSee('Ikut tim')->assertSee('Batas jawab')->assertSee($grace);
    Livewire::actingAs($this->rizkyUser)->test(PendaftaranForm::class, ['competitionId' => $r->competition_id])
        ->assertSee('Konfirmasi sebelum '.$grace.'.');
    // The ketua may invite a replacement in the same three days; the hint names them, not the passed deadline.
    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $r->competition_id])
        ->assertSee('Undang anggota pengganti')
        ->assertSee('Undangan berlaku sampai '.$grace.'.')
        ->assertDontSee('Undangan berlaku sampai batas pendaftaran.');
    $this->actingAs($this->rizkyUser)->get(route('siswa.beranda'))->assertOk()->assertSee('jawab sebelum '.$grace);

    // A minute after the three days: closed, and the expiry run declines the open invitation as usual.
    $this->travelTo($publishedAt->addDays(3)->addMinute());
    expect(Gate::forUser($this->rizkyUser)->allows('respond', $r->fresh()))->toBeFalse()
        ->and(ExpirePendingInvitations::due(CarbonImmutable::now())->pluck('id')->all())->toBe([$r->getKey()])
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toMatchArray(['expired' => 1, 'ready' => false]);
    expect($r->members()->where('role', MemberRole::Anggota)->sole()->declined_at)->not->toBeNull();
    Notification::assertSentTo($this->nadiaUser, InvitationsExpired::class,
        fn (InvitationsExpired $n): bool => str_contains($n->message(), 'tidak dijawab sampai batas jawab'));
    Livewire::actingAs($this->rizkyUser)->test(KonfirmasiTim::class, ['registration' => $r])
        ->assertSee('Undangan ini sudah tidak berlaku karena batas jawab lewat.');
});

it('lets the anggota of a resumed team join within the three days', function (): void {
    $r = rtaWaitingTeam($this, now()->toImmutable()->addDays(5)->endOfDay());
    $this->travelTo(now()->addDays(6));
    rtaPublish($this, $r);

    $this->travelTo(now()->addDays(2));
    app(ConfirmMembership::class)->handle($this->rizkyUser, $r->fresh());

    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('gives the three days also when the deadline is less than three days away', function (): void {
    $r = rtaWaitingTeam($this, now()->toImmutable()->addDays(5)->endOfDay());
    $this->travelTo(now()->addDays(4)); // the deadline is one day and some hours away

    rtaPublish($this, $r);

    expect($r->fresh()->answer_until?->toDateTimeString())->toBe(now()->toImmutable()->addDays(3)->toDateTimeString());
});

it('leaves answer_until empty when the deadline is more than three days away', function (): void {
    $closes = now()->toImmutable()->addDays(10)->endOfDay();
    $r = rtaWaitingTeam($this, $closes);

    rtaPublish($this, $r);

    expect($r->fresh()->answer_until)->toBeNull()
        ->and($r->fresh()->answersCloseAt()?->toDateTimeString())->toBe($closes->toDateTimeString());
    Livewire::actingAs($this->rizkyUser)->test(KonfirmasiTim::class, ['registration' => $r])->assertDontSee('Batas jawab');
    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $r->competition_id])
        ->assertSee('Undangan berlaku sampai batas pendaftaran.');
});

it('never extends a normal team registration', function (): void {
    $lomba = F::teamCompetition(3, ['registration_closes_at' => now()->addDays(2)->endOfDay()]);
    $r = app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$this->rizky->getKey()], $this->guru->getKey());

    expect($r->fresh()->answer_until)->toBeNull()
        ->and($r->fresh()->answersCloseAt()?->toDateTimeString())->toBe($lomba->registration_closes_at->toDateTimeString());

    // Until the deadline: open. After it: closed and expired, exactly as before.
    $this->travelTo($lomba->registration_closes_at);
    expect(Gate::forUser($this->rizkyUser)->allows('respond', $r->fresh()))->toBeTrue()
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toBeNull();

    $this->travelTo($lomba->registration_closes_at->addMinute());
    expect(Gate::forUser($this->rizkyUser)->allows('respond', $r->fresh()))->toBeFalse()
        ->and(ExpirePendingInvitations::due(CarbonImmutable::now())->pluck('id')->all())->toBe([$r->getKey()])
        ->and(app(ExpirePendingInvitations::class)->handle($r))->toMatchArray(['expired' => 1]);
});

it('does not mass-assign answer_until', function (): void {
    expect(fn () => new Registration(['answer_until' => now()]))->toThrow(MassAssignmentException::class)
        ->and(Registration::ANSWER_GRACE_DAYS)->toBe(3);
});
