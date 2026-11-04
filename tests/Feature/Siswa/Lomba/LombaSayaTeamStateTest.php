<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Actions\Registrations\InviteMembers;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * SV1 (second browser check of 2026-10-30), Lomba saya:
 * - a team left with fewer than two people (D-36) after its invitation expired (D-50) or a member declined said
 *   "Semua anggota sudah konfirmasi." although ConfirmMembership never moves such a team on. The screen now asks
 *   ConfirmMembership::teamIsShort(), the rule advanceIfTeamReady() uses, and says what the ketua can still do;
 * - a registration that ended (cancelled by the ketua, or with its rejected lomba, D-148) still listed its unanswered or
 *   never-invited anggota as "Menunggu konfirmasi".
 * Every flow runs through the real Actions. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
});

/** @param list<Student> $members */
function ltsTeam(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        RegistrationType::Tim,
        array_map(fn (Student $s): string => $s->getKey(), $members),
        $t->guru->id,
    );
}

function ltsPage(Student $viewer, Registration $registration): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(LombaSaya::class, ['registration' => $registration->fresh()]);
}

/** @return array<string, mixed> the list card of $registration */
function ltsCard(Testable $page, Registration $registration): array
{
    return collect($page->viewData('cards'))->firstWhere('id', (string) $registration->getKey());
}

/** @return array<string, string> member name => state of the team card */
function ltsMemberStates(Testable $page): array
{
    return collect($page->viewData('detail')['members'])->mapWithKeys(fn (array $m): array => [$m['name'] => $m['state']])->all();
}

it('says a team left alone after a decline is short and lets the ketua invite a replacement until the window closes (D-36)', function (): void {
    $registration = ltsTeam($this, F::teamCompetition(3), [$this->dimas]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);
    $registration = $registration->fresh();

    // The Action's rule: the team does not go on, and the screen reads the same rule.
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::teamIsShort($registration))->toBeTrue();

    $until = $registration->answersCloseAt()->locale('id')->translatedFormat('j M Y, H.i');
    $page = ltsPage($this->nadia, $registration);
    $detail = $page->viewData('detail');

    expect($detail['teamShort'])->toBeTrue()
        ->and([$detail['chip'], $detail['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange'])
        ->and($detail['waitingText'])->toBe('Tim kurang dari 2 orang, jadi pendaftaran belum bisa diteruskan untuk persetujuan. Undang anggota pengganti sebelum '.$until.', atau batalkan pendaftaran ini.')
        ->and($detail['inviteHref'])->not->toBeNull()
        ->and($detail['canCancel'])->toBeTrue();
    expect([ltsCard($page, $registration)['chip'], ltsCard($page, $registration)['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange']);
    $page->assertSee('Tim kurang anggota')
        ->assertSee($detail['waitingText'])
        ->assertSeeHtml('href="'.e($detail['inviteHref']).'"')
        ->assertSee('Batalkan pendaftaran')
        ->assertDontSee('Semua anggota sudah konfirmasi.');

    // A replacement makes it a team again; once it confirmed, ConfirmMembership moves it on and the screen says so.
    app(InviteMembers::class)->handle(F::user($this->nadia), $registration, [$this->keisha->getKey()]);
    $page = ltsPage($this->nadia, $registration);
    expect(ConfirmMembership::teamIsShort($registration->fresh()))->toBeFalse()
        ->and($page->viewData('detail')['teamShort'])->toBeFalse()
        ->and($page->viewData('detail')['waitingText'])->toBe('Menunggu konfirmasi anggota tim.');
    $page->assertDontSee('Tim kurang anggota');

    app(ConfirmMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    expect(ltsPage($this->nadia, $registration)->viewData('detail')['waitingText'])->toBe('Semua anggota sudah konfirmasi.');
});

it('says a team left alone after its invitation expired is short and can only be cancelled (D-36, D-50)', function (): void {
    $lomba = F::teamCompetition(2);
    $registration = ltsTeam($this, $lomba, [$this->dimas]);
    $lomba->forceFill(['registration_closes_at' => now()->subHour()])->save();
    app(ExpirePendingInvitations::class)->handle($registration);
    $registration = $registration->fresh();

    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::teamIsShort($registration))->toBeTrue();

    $page = ltsPage($this->nadia, $registration);
    $detail = $page->viewData('detail');

    // Before SV1 the only loaded member (the ketua, confirmed) made it read "Semua anggota sudah konfirmasi.".
    expect($detail['teamShort'])->toBeTrue()
        ->and([$detail['chip'], $detail['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange'])
        ->and($detail['waitingText'])->toBe('Tim kurang dari 2 orang, jadi pendaftaran belum bisa diteruskan untuk persetujuan. Anggota pengganti tidak bisa diundang lagi, jadi batalkan pendaftaran ini.')
        ->and($detail['inviteHref'])->toBeNull()
        ->and($detail['canCancel'])->toBeTrue();
    $page->assertSee('Tim kurang anggota')
        ->assertSee($detail['waitingText'])
        ->assertDontSee('Undang anggota pengganti')
        ->assertDontSee('Semua anggota sudah konfirmasi.');
});

it('keeps the waiting line for a team that still has enough people (D-36)', function (): void {
    $registration = ltsTeam($this, F::teamCompetition(3), [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration);

    // Nadia and the invitee Keisha are two people: not short, only waiting.
    expect(ConfirmMembership::teamIsShort($registration->fresh()))->toBeFalse();

    $page = ltsPage($this->nadia, $registration);
    expect($page->viewData('detail')['teamShort'])->toBeFalse()
        ->and($page->viewData('detail')['waitingText'])->toBe('Menunggu konfirmasi anggota tim.')
        ->and(ltsCard($page, $registration)['chip'])->toBe(RegistrationStatus::MenungguKonfirmasi->label());
    $page->assertDontSee('Tim kurang anggota');
});

it('counts the team in the database, whatever members a screen loaded, and never for an individu registration', function (): void {
    $team = ltsTeam($this, F::teamCompetition(3), [$this->dimas]);
    $short = $team->fresh();
    $short->setRelation('members', $short->members()->whereKey('none')->get());

    expect(ConfirmMembership::teamIsShort($short))->toBeFalse();

    app(DeclineMembership::class)->handle(F::user($this->dimas), $team);
    $loaded = $team->fresh()->load('members');
    expect(ConfirmMembership::teamIsShort($loaded))->toBeTrue()
        // advanceIfTeamReady()'s rule: everyone left confirmed, but one person is not a team.
        ->and(ConfirmMembership::teamIsReady($loaded->members))->toBeFalse();

    $individu = app(CreateRegistration::class)->handle(F::user($this->keisha), F::openCompetition(), RegistrationType::Individu, [], $this->guru->id);
    expect(ConfirmMembership::teamIsShort($individu))->toBeFalse();
});

it('shows no waiting chip for an invitee who never answered once the ketua cancelled the team', function (): void {
    $registration = ltsTeam($this, F::teamCompetition(3), [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration);

    $live = ltsPage($this->nadia, $registration);
    expect(ltsMemberStates($live))->toBe(['Nadia Putri Anindya' => 'confirmed', 'Dimas Arya Nugroho' => 'confirmed', 'Keisha Larasati' => 'pending']);
    $live->assertSee('Menunggu konfirmasi');

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    $ended = ltsPage($this->nadia, $registration);
    expect(ltsMemberStates($ended))->toBe(['Nadia Putri Anindya' => 'confirmed', 'Dimas Arya Nugroho' => 'confirmed', 'Keisha Larasati' => 'not_joined']);
    $ended->assertSee('Tidak ikut')->assertDontSee('Menunggu konfirmasi');

    // The invitee herself sees the same: she did not join.
    $own = ltsPage($this->keisha, $registration);
    expect(collect($own->viewData('detail')['members'])->firstWhere('me', true)['state'])->toBe('not_joined');
    $own->assertSee('Tidak ikut')->assertDontSee('Menunggu konfirmasi');
});

it('shows the never-invited anggota as not invited once the team went with its rejected lomba (D-148)', function (): void {
    $lomba = F::pendingCompetition(F::user($this->nadia), F::usulanAttributes([
        'participation' => Participation::Tim, 'max_team_size' => 3,
    ]));
    $registration = ltsTeam($this, $lomba, [$this->dimas, $this->keisha]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);

    // While the lomba waits, the anggota are invited later (FS1 QA round 2).
    expect(ltsMemberStates(ltsPage($this->nadia, $registration)))
        ->toBe(['Nadia Putri Anindya' => 'confirmed', 'Dimas Arya Nugroho' => 'later', 'Keisha Larasati' => 'later']);

    app(VerifyCompetition::class)->handle(F::staff(RoleName::Kesiswaan, 'Kesiswaan Contoh'), $lomba->fresh(), CompetitionStatus::Ditolak);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    $page = ltsPage($this->nadia, $registration);
    expect(ltsMemberStates($page))->toBe(['Nadia Putri Anindya' => 'confirmed', 'Dimas Arya Nugroho' => 'not_invited', 'Keisha Larasati' => 'not_invited']);
    $page->assertSee('Tidak diundang')
        ->assertSee('jadi pendaftarannya ikut dibatalkan.')
        ->assertDontSee('Menunggu konfirmasi')
        ->assertDontSee('Diundang setelah lomba diverifikasi');
});

/**
 * SV1 review: the list decides "Tim kurang anggota" from the count registrations() loads (`active_members_count`), so no
 * COUNT query runs per card; the detail still asks ConfirmMembership::teamIsShort() in the database.
 *
 * @return array{0: Testable, 1: int} the page opened on $selected and the member COUNT queries of that request
 */
function ltsCountQueries(Student $viewer, Registration $selected): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $page = ltsPage($viewer, $selected);
        // The query of ConfirmMembership::teamIsShort(); the list's `active_members_count` is a subquery of its select.
        $counts = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/^select count\(\*\) as ["`]?aggregate["`]? from ["`]?registration_members["`]?/i', $sql) === 1)
            ->count();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return [$page, $counts];
}

/** A team of Nadia that waits for answers; $invitees = anggota with an open invitation (none = short, D-36). */
function ltsWaitingTeam(object $t, string $name, array $invitees = []): Registration
{
    $registration = Registration::factory()->status(RegistrationStatus::MenungguKonfirmasi)->create([
        'competition_id' => F::teamCompetition(3, ['name' => $name])->getKey(),
        'leader_student_id' => $t->nadia->getKey(),
        'type' => RegistrationType::Tim,
    ]);

    foreach ($invitees as $invitee) {
        RegistrationMember::factory()->create(['registration_id' => $registration->getKey(), 'student_id' => $invitee->getKey(), 'invited_at' => now()]);
    }

    return $registration->fresh();
}

it('decides the short-team chip of the list from the loaded count, without a count query per card (SV1 review)', function (): void {
    $first = ltsWaitingTeam($this, 'OSN Biologi 2026');

    [$one, $countsForOne] = ltsCountQueries($this->nadia, $first);
    expect([ltsCard($one, $first)['chip'], ltsCard($one, $first)['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange']);

    // Three more waiting teams: two short, one with an open invitation (two people, not short).
    $short = [ltsWaitingTeam($this, 'KIR Nasional 2026'), ltsWaitingTeam($this, 'ISPO 2027')];
    $full = ltsWaitingTeam($this, 'Kompetisi Biologi Nasional 2026', [$this->dimas]);

    [$four, $countsForFour] = ltsCountQueries($this->nadia, $first);

    // The detail of the selected team counts once in the database (ConfirmMembership::teamIsShort(), so the filter sees
    // that query); the list cards add nothing (before: one per card, 2 and 5 here).
    expect($countsForOne)->toBe(1)
        ->and($countsForFour)->toBe($countsForOne)
        ->and($four->viewData('detail')['teamShort'])->toBeTrue();

    foreach ([$first, ...$short] as $registration) {
        expect([ltsCard($four, $registration)['chip'], ltsCard($four, $registration)['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange']);
    }
    expect(ltsCard($four, $full)['chip'])->toBe(RegistrationStatus::MenungguKonfirmasi->label())
        // The loaded count is the database rule's: teamIsShort() agrees for every card.
        ->and(collect([$first, ...$short])->every(fn (Registration $r): bool => ConfirmMembership::teamIsShort($r)))->toBeTrue()
        ->and(ConfirmMembership::teamIsShort($full))->toBeFalse();
    $four->assertSee(LombaSaya::SHORT_TEAM_CHIP);

    // A team member who still has to answer sees the invitation chip, not the short-team one, on the same count.
    $dimasPage = ltsPage($this->dimas, $full);
    expect(ltsCard($dimasPage, $full)['chip'])->toBe('Konfirmasi undangan');
});

it('decides a short team from a count the caller passes with the same rule as the database count (D-36)', function (): void {
    $team = ltsTeam($this, F::teamCompetition(3), [$this->dimas]);
    $individu = app(CreateRegistration::class)->handle(F::user($this->keisha), F::openCompetition(), RegistrationType::Individu, [], $this->guru->id);

    // The rule itself: fewer than MIN_TEAM_SIZE people is short, a team only.
    expect(ConfirmMembership::teamIsShortWith($team, ConfirmMembership::MIN_TEAM_SIZE - 1))->toBeTrue()
        ->and(ConfirmMembership::teamIsShortWith($team, ConfirmMembership::MIN_TEAM_SIZE))->toBeFalse()
        ->and(ConfirmMembership::teamIsShortWith($individu, 1))->toBeFalse();

    // With the count the screens load (members who did not decline), it agrees with teamIsShort() before and after a decline.
    $people = fn (Registration $r): int => $r->members()->whereNull('declined_at')->count();
    expect(ConfirmMembership::teamIsShortWith($team, $people($team)))->toBe(ConfirmMembership::teamIsShort($team))->toBeFalse();

    app(DeclineMembership::class)->handle(F::user($this->dimas), $team);
    $team = $team->fresh();
    expect(ConfirmMembership::teamIsShortWith($team, $people($team)))->toBe(ConfirmMembership::teamIsShort($team))->toBeTrue()
        // Lomba saya's list rule (status + the loaded count) uses it too.
        ->and(LombaSaya::isShortTeam($team, $people($team)))->toBeTrue()
        ->and(LombaSaya::isShortTeam($team))->toBeTrue();
});
