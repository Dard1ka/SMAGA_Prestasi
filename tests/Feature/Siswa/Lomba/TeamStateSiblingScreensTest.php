<?php

declare(strict_types=1);

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RegistrationCancelled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * SV1 R3 (third browser check): Lomba saya had learnt two rules that its sibling screens still missed.
 * 1. A registration that ended waits for nobody: an anggota who never answered "Tidak ikut", one never invited "Tidak
 *    diundang" (LombaSaya::memberState()). Siswa › Undangan tim and Guru › detail binaan still said "Menunggu
 *    konfirmasi"; both now use LombaSaya::memberChip() and keep their own "Tidak menjawab", "Menolak" and confirmed.
 * 2. A team left with fewer than two people (D-36, ConfirmMembership::teamIsShort()) does not go on. Detail lomba ›
 *    Pendaftaran lomba and the guru's binaan chip still read "Menunggu konfirmasi anggota"; they now show the chip and
 *    line of Lomba saya ("Tim kurang anggota": orange for the ketua, gray for the guru).
 * 3. The students' "Pendaftaran dibatalkan" bell row opened Lomba saya › Berjalan, where the cancelled registration is
 *    not listed; it opens that registration now.
 * Every flow runs through the real Actions. Fictional data only (DESIGN-SPEC-v2 §5).
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
    $this->rizky = F::student('Rizky Pratama', 'XI-5');
});

/** @param list<Student> $members a team of Nadia with $members invited and $this->guru as guru pembimbing */
function tssTeam(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $lomba,
        RegistrationType::Tim,
        array_map(fn (Student $s): string => $s->getKey(), $members),
        $t->guru->id,
    );
}

/** @return array<string, array{0: string, 1: string}> member name => chip [text, tone] on Siswa › Undangan tim */
function tssUndangan(Student $viewer, Registration $registration): array
{
    return collect(tssUndanganPage($viewer, $registration)->viewData('members'))
        ->mapWithKeys(fn (array $m): array => [$m['name'] => $m['state']])->all();
}

function tssUndanganPage(Student $viewer, Registration $registration): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(KonfirmasiTim::class, ['registration' => $registration->fresh()]);
}

function tssDetailPage(User $guru, Registration $registration): Testable
{
    return Livewire::actingAs($guru)->test(BinaanDetail::class, ['registration' => $registration->fresh()]);
}

/** @return array<string, array{0: string, 1: string}|null> member name => chip [text, tone] on Guru › detail binaan */
function tssGuruStates(User $guru, Registration $registration): array
{
    return collect(tssDetailPage($guru, $registration)->viewData('members'))
        ->mapWithKeys(fn (array $m): array => [$m['name'] => $m['state']])->all();
}

/** A team of four that waits: Dimas confirmed, Keisha has not answered, Rizky declined. */
function tssMixedTeam(object $t): Registration
{
    $registration = tssTeam($t, F::teamCompetition(4), [$t->dimas, $t->keisha, $t->rizky]);
    app(ConfirmMembership::class)->handle(F::user($t->dimas), $registration->fresh());
    app(DeclineMembership::class)->handle(F::user($t->rizky), $registration->fresh());

    $registration = $registration->fresh();
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(ConfirmMembership::teamIsShort($registration))->toBeFalse();

    return $registration;
}

// 1. Ended registrations on Undangan tim and Guru › detail binaan --------------------------------------------------

it('shows an invitee who never answered as "Tidak ikut" on Undangan tim once the ketua cancelled the team', function (): void {
    $registration = tssMixedTeam($this);

    // While the team waits, Keisha's invitation is open.
    expect(tssUndangan($this->dimas, $registration)['Keisha Larasati'])->toBe(['Menunggu konfirmasi', 'gray']);

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    $expected = [
        'Nadia Putri Anindya' => ['Sudah konfirmasi', 'green'],
        'Dimas Arya Nugroho' => ['Sudah konfirmasi', 'green'],
        'Keisha Larasati' => LombaSaya::MEMBER_STATE_CHIPS['not_joined'],
        'Rizky Pratama' => ['Menolak', 'red'],
    ];

    // A teammate and the invitee herself (her own row too) read the same as on Lomba saya, never "Menunggu konfirmasi".
    foreach ([$this->dimas, $this->keisha] as $viewer) {
        expect(tssUndangan($viewer, $registration))->toBe($expected);
        tssUndanganPage($viewer, $registration)->assertSee('Tidak ikut')->assertDontSee('Menunggu konfirmasi');
    }

    expect(LombaSaya::MEMBER_STATE_CHIPS['not_joined'])->toBe(['Tidak ikut', 'gray']);

    // Lomba saya's own team card says the same words for her.
    Livewire::actingAs(F::user($this->keisha))->test(LombaSaya::class, ['registration' => $registration->fresh()])
        ->assertSee(LombaSaya::MEMBER_STATE_CHIPS['not_joined'][0])
        ->assertDontSee('Menunggu konfirmasi');
});

it('shows the guru an invitee who never answered as "Tidak ikut" once the ketua cancelled the team', function (): void {
    $registration = tssMixedTeam($this);

    expect(tssGuruStates($this->guru, $registration)['Keisha Larasati'])->toBe(['Menunggu konfirmasi', 'gray']);

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration->fresh());

    expect(tssGuruStates($this->guru, $registration))->toBe([
        'Nadia Putri Anindya' => ['Terkonfirmasi', 'green'],
        'Dimas Arya Nugroho' => ['Terkonfirmasi', 'green'],
        'Keisha Larasati' => LombaSaya::MEMBER_STATE_CHIPS['not_joined'],
        'Rizky Pratama' => ['Menolak', 'red'],
    ]);
    tssDetailPage($this->guru, $registration)->assertSee('Tidak ikut')->assertDontSee('Menunggu konfirmasi');
});

it('keeps "Tidak menjawab" for an invitation that expired before the registration ended (QA10)', function (): void {
    $lomba = F::teamCompetition(3);
    $registration = tssTeam($this, $lomba, [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    $lomba->forceFill(['registration_closes_at' => now()->subHour()])->save();
    app(ExpirePendingInvitations::class)->handle($registration->fresh());

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration->fresh());
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);

    expect(tssUndangan($this->dimas, $registration)['Keisha Larasati'])->toBe(['Tidak menjawab', 'gray'])
        ->and(tssGuruStates($this->guru, $registration)['Keisha Larasati'])->toBe(['Tidak menjawab', 'gray']);
});

it('gives every unanswered state the chip of the Lomba saya rule (one source)', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas]);
    $dimas = $registration->fresh()->members->firstWhere('student_id', $this->dimas->getKey());

    expect(LombaSaya::memberChip($dimas, RegistrationStatus::MenungguKonfirmasi))->toBe(['Menunggu konfirmasi', 'gray'])
        ->and(LombaSaya::memberChip($dimas, RegistrationStatus::Dibatalkan))->toBe(['Tidak ikut', 'gray'])
        ->and(LombaSaya::memberChip($dimas, RegistrationStatus::Ditolak))->toBe(['Tidak ikut', 'gray']);

    $dimas->invited_at = null;
    expect(LombaSaya::memberChip($dimas, RegistrationStatus::MenungguLomba))->toBe(['Diundang setelah lomba diverifikasi', 'gray'])
        ->and(LombaSaya::memberChip($dimas, RegistrationStatus::Dibatalkan))->toBe(['Tidak diundang', 'gray']);

    $dimas->confirmed_at = now();
    expect(LombaSaya::memberState($dimas, RegistrationStatus::Dibatalkan))->toBe('confirmed');
});

// 2. A team short of members on Detail lomba › Pendaftaran lomba and for the guru -------------------------------------

function tssPanel(Student $viewer, Registration $registration): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(Form::class, ['competitionId' => $registration->competition_id]);
}

it('shows the ketua of a short team the chip and line of Lomba saya on the Pendaftaran lomba panel (D-36)', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    $registration = $registration->fresh();
    expect(ConfirmMembership::teamIsShort($registration))->toBeTrue();

    $panel = tssPanel($this->nadia, $registration);
    $status = $panel->viewData('status');
    $lombaSaya = Livewire::actingAs(F::user($this->nadia))->test(LombaSaya::class, ['registration' => $registration])->viewData('detail');
    $until = $registration->answersCloseAt()->locale('id')->translatedFormat('j M Y, H.i');

    expect([$status['label'], $status['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange'])
        ->and([$status['label'], $status['tone']])->toBe([$lombaSaya['chip'], $lombaSaya['tone']])
        ->and($status['teamShort'])->toBeTrue()
        ->and($status['teamAction'])->toBeTrue()
        ->and($status['canInvite'])->toBeTrue()
        ->and($status['waitingText'])->toBe('Tim kurang dari 2 orang, jadi pendaftaran belum bisa diteruskan untuk persetujuan. Undang anggota pengganti sebelum '.$until.', atau batalkan pendaftaran ini.')
        ->and($status['waitingText'])->toBe($lombaSaya['waitingText'])
        ->and($status['membersText'])->toBe('Tim kurang dari 2 orang');

    $panel->assertSee(LombaSaya::SHORT_TEAM_CHIP)
        ->assertSee($status['waitingText'])
        ->assertSeeHtml('bg-orange-bg text-orange')
        ->assertDontSee(RegistrationStatus::MenungguKonfirmasi->label());
});

it('tells the ketua of a short team to cancel once no replacement can be invited, as Lomba saya does (D-36, D-50)', function (): void {
    $lomba = F::teamCompetition(2);
    $registration = tssTeam($this, $lomba, [$this->dimas]);
    $lomba->forceFill(['registration_closes_at' => now()->subHour()])->save();
    app(ExpirePendingInvitations::class)->handle($registration->fresh());
    $registration = $registration->fresh();
    expect(ConfirmMembership::teamIsShort($registration))->toBeTrue();

    $status = tssPanel($this->nadia, $registration)->viewData('status');
    $lombaSaya = Livewire::actingAs(F::user($this->nadia))->test(LombaSaya::class, ['registration' => $registration])->viewData('detail');

    expect([$status['label'], $status['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange'])
        ->and($status['canInvite'])->toBeFalse()
        ->and($status['canCancel'])->toBeTrue()
        ->and($status['waitingText'])->toBe('Tim kurang dari 2 orang, jadi pendaftaran belum bisa diteruskan untuk persetujuan. Anggota pengganti tidak bisa diundang lagi, jadi batalkan pendaftaran ini.')
        ->and($status['waitingText'])->toBe($lombaSaya['waitingText']);
});

it('keeps the waiting status on the panel for a team that still has enough people', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    $panel = tssPanel($this->nadia, $registration->fresh());
    $status = $panel->viewData('status');

    expect([$status['label'], $status['tone']])->toBe([RegistrationStatus::MenungguKonfirmasi->label(), 'gray'])
        ->and($status['teamShort'])->toBeFalse()
        ->and($status['teamAction'])->toBeFalse()
        ->and($status['waitingText'])->toBeNull()
        ->and($status['membersText'])->toBe('0 dari 1 anggota sudah konfirmasi');
    $panel->assertDontSee(LombaSaya::SHORT_TEAM_CHIP);
});

it('shows a short team to the guru with the gray chip "Tim kurang anggota", in the list and the detail (D-36)', function (): void {
    $short = tssTeam($this, F::teamCompetition(3, ['name' => 'OSN Biologi 2026']), [$this->dimas]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $short->fresh());
    $waiting = tssTeam($this, F::teamCompetition(3, ['name' => 'KIR Nasional 2026']), [$this->keisha]);

    $list = Livewire::actingAs($this->guru)->test(Binaan::class);
    $cards = collect($list->viewData('registrations'))->keyBy('id');

    expect([$cards[(string) $short->getKey()]['chip'], $cards[(string) $short->getKey()]['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'gray'])
        // A team that still has enough people keeps the status chip.
        ->and([$cards[(string) $waiting->getKey()]['chip'], $cards[(string) $waiting->getKey()]['tone']])
        ->toBe([RegistrationStatus::MenungguKonfirmasi->label(), 'gray']);
    $list->assertSee(LombaSaya::SHORT_TEAM_CHIP);

    $detail = tssDetailPage($this->guru, $short);
    expect([$detail->viewData('chip'), $detail->viewData('tone')])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'gray']);
    $detail->assertSee(LombaSaya::SHORT_TEAM_CHIP)->assertDontSee(RegistrationStatus::MenungguKonfirmasi->label());

    expect([tssDetailPage($this->guru, $waiting)->viewData('chip')])->toBe([RegistrationStatus::MenungguKonfirmasi->label()]);
});

it('decides the guru list chip from the loaded members, without a count query per card (D-36)', function (): void {
    foreach (['OSN Biologi 2026', 'KIR Nasional 2026', 'ISPO 2027'] as $name) {
        $registration = tssTeam($this, F::teamCompetition(3, ['name' => $name]), [$this->dimas]);
        app(DeclineMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $list = Livewire::actingAs($this->guru)->test(Binaan::class);
        $counts = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/^select count\(\*\) as ["`]?aggregate["`]? from ["`]?registration_members["`]?/i', $sql) === 1)
            ->count();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($counts)->toBe(0)
        ->and(collect($list->viewData('registrations'))->pluck('chip')->unique()->values()->all())->toBe([LombaSaya::SHORT_TEAM_CHIP]);
});

// 3. The students' cancel notice opens the cancelled registration ----------------------------------------------------

it('links the students\' "Pendaftaran dibatalkan" bell row to the cancelled registration, which they can open', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas, $this->keisha]);
    app(ConfirmMembership::class)->handle(F::user($this->dimas), $registration->fresh());

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration->fresh());

    $show = route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]);

    // The confirmed member and the invitee who never answered are both told, and both may open the registration.
    foreach ([$this->dimas, $this->keisha] as $member) {
        $user = F::user($member);
        $notice = Notification::sent($user, RegistrationCancelled::class)->first();

        expect($notice)->not->toBeNull()
            ->and($notice->audience)->toBe('siswa')
            ->and($notice->toArray($user)['url'])->toBe($show)
            // The students' mail still has no button (one link at most, D-129; it never had one).
            ->and($notice->toMail($user)->actionUrl)->toBeNull();

        $this->actingAs($user)->get($show)
            ->assertOk()
            ->assertSee($registration->competition->name)
            ->assertSee('Pendaftaran dibatalkan');
    }

    // The guru and an approver keep their own addresses.
    expect(Notification::sent($this->guru, RegistrationCancelled::class)->first()->toArray($this->guru)['url'])
        ->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]));
});

// SV1 re-check of the R3 fixes (2026-11-02) ------------------------------------------------------------------------

it('shows a short team on Undangan tim with the chip of Lomba saya, orange for the ketua and gray for the others (D-36)', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas]);
    app(DeclineMembership::class)->handle(F::user($this->dimas), $registration->fresh());
    $registration = $registration->fresh();
    expect(ConfirmMembership::teamIsShort($registration))->toBeTrue();

    // The ketua: the orange chip Lomba saya, the panel and the Beranda show, never the raw waiting status.
    $ketua = tssUndanganPage($this->nadia, $registration);
    expect($ketua->viewData('teamChip'))->toBe(['chip' => LombaSaya::SHORT_TEAM_CHIP, 'tone' => 'orange']);
    $ketua->assertSee(LombaSaya::SHORT_TEAM_CHIP)->assertDontSee(RegistrationStatus::MenungguKonfirmasi->label());

    // The anggota who declined still opens the page: the same chip, gray.
    $anggota = tssUndanganPage($this->dimas, $registration);
    expect($anggota->viewData('teamChip'))->toBe(['chip' => LombaSaya::SHORT_TEAM_CHIP, 'tone' => 'gray']);
    $anggota->assertDontSee(RegistrationStatus::MenungguKonfirmasi->label());
});

it('keeps the waiting status on Undangan tim while the team still has enough people', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas, $this->keisha]);

    expect(tssUndanganPage($this->dimas, $registration)->viewData('teamChip'))
        ->toBe(['chip' => RegistrationStatus::MenungguKonfirmasi->label(), 'tone' => RegistrationStatus::MenungguKonfirmasi->tone()]);
});

it('moves Lomba saya to the Selesai tab after the ketua cancels, so the list shows the cancelled card', function (): void {
    $registration = tssTeam($this, F::teamCompetition(3), [$this->dimas]);

    // Before: the only registration of the ketua, under Berjalan.
    $screen = Livewire::actingAs(F::user($this->nadia))->test(LombaSaya::class, ['registration' => $registration->fresh()])
        ->assertSet('tab', 'berjalan');

    $screen->call('cancel')
        ->assertDispatched('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'success')
        ->assertSet('tab', 'selesai')
        ->assertDontSee('Belum ada lomba berjalan');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and(collect($screen->viewData('cards'))->pluck('id')->all())->toContain((string) $registration->getKey());
});
