<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionRejected;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Providers\NavigationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 integration of Paket B Tasks 1-11 with Tasks 12-16: a registration that waits for its lomba (`menunggu_lomba`,
| D-148) is no approval case yet (neutral approval wording and the Tata Usaha queue of D-150), the approval of a
| registration resumed from it is open to Tata Usaha too, and the notices and screens that say who decided the lomba
| name that role (StaffRoleLabel, as D-139/D-150 do). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->tu = F::staff(RoleName::TataUsaha, 'Sri Handayani, S.E.');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
});

/** Nadia's queued lomba "ISPO 2027" with her waiting registration (individu, or a team with Rizky). */
function mlrWaiting(object $t, bool $team = false): Registration
{
    $lomba = F::pendingCompetition($t->nadiaUser, F::usulanAttributes(
        $team ? ['name' => 'ISPO 2027', 'participation' => Participation::Tim, 'max_team_size' => 3] : ['name' => 'ISPO 2027'],
    ));

    return app(CreateRegistration::class)->handle(
        $t->nadiaUser,
        $lomba,
        $team ? RegistrationType::Tim : RegistrationType::Individu,
        $team ? [$t->rizky->getKey()] : [],
        $t->guru->getKey(),
    );
}

function mlrDecide(object $t, Registration $registration, CompetitionStatus $decision, ?object $actor = null, ?string $note = null): Competition
{
    return app(VerifyCompetition::class)->handle(
        $actor ?? $t->kesiswaan,
        Competition::query()->findOrFail($registration->competition_id),
        $decision,
        $note,
    );
}

it('keeps a waiting registration out of Persetujuan and its count, for Kepala Sekolah and Tata Usaha', function (): void {
    $r = mlrWaiting($this);
    expect($r->status)->toBe(RegistrationStatus::MenungguLomba);

    foreach ([[$this->kepsek, 'kepala_sekolah'], [$this->tu, 'tata_usaha']] as [$approver, $role]) {
        expect(NavigationServiceProvider::badges($approver, $role)['kepsek.persetujuan'] ?? 0)->toBe(0);
        Livewire::actingAs($approver)->test(Persetujuan::class)->assertDontSee('ISPO 2027');
    }

    // Published: now it waits for the approval, on both screens and in both counts.
    mlrDecide($this, $r, CompetitionStatus::Dipublikasikan);

    foreach ([[$this->kepsek, 'kepala_sekolah'], [$this->tu, 'tata_usaha']] as [$approver, $role]) {
        expect(NavigationServiceProvider::badges($approver, $role)['kepsek.persetujuan'])->toBe(1);
        Livewire::actingAs($approver)->test(Persetujuan::class)->assertSee('ISPO 2027');
    }
});

it('shows the waiting registration as a lomba verification, not an approval, on the panel and Lomba saya', function (): void {
    $r = mlrWaiting($this);

    Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $r->competition_id])
        ->assertSee('Menunggu verifikasi lomba')
        ->assertSeeInOrder(['Kesiswaan memverifikasi lomba', 'Sekolah menyetujui', 'Tata Usaha membuat surat tugas'])
        ->assertDontSee('Menunggu persetujuan');

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $r))->assertOk()
        ->assertSeeHtml('<h3 id="setuju-judul" class="title-card">Verifikasi lomba</h3>')
        ->assertSee('Menunggu verifikasi lomba')
        ->assertSee('Pendaftaran diteruskan setelah Kesiswaan memverifikasi lomba ini.')
        ->assertDontSee('Menunggu persetujuan');

    // Once the lomba is published the same card speaks of the approval, in the neutral D-150 wording.
    mlrDecide($this, $r, CompetitionStatus::Dipublikasikan);

    Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $r->competition_id])
        ->assertSee('Menunggu persetujuan')
        ->assertDontSee('Kesiswaan memverifikasi lomba');

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $r))->assertOk()
        ->assertSeeHtml('<h3 id="setuju-judul" class="title-card">Persetujuan</h3>')
        ->assertSee('Menunggu persetujuan sekolah.')
        ->assertDontSee('Pendaftaran diteruskan setelah Kesiswaan memverifikasi lomba ini.');
});

it('numbers the team steps after the lomba verification while the lomba waits', function (): void {
    $r = mlrWaiting($this, team: true);

    Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $r->competition_id])
        ->assertSeeInOrder(['Kesiswaan memverifikasi lomba', 'Anggota konfirmasi', 'Sekolah menyetujui', 'Tata Usaha membuat surat tugas'])
        ->assertSeeHtml('<span class="num">4</span>');
});

it('lets Tata Usaha approve a registration resumed from menunggu_lomba, with a link to its own queue', function (): void {
    $r = mlrWaiting($this);

    mlrDecide($this, $r, CompetitionStatus::Dipublikasikan);

    expect($r->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
    Notification::assertSentTo($this->tu, RegistrationAwaitingPrincipal::class,
        fn (RegistrationAwaitingPrincipal $n): bool => $n->registration->is($r) && str_ends_with((string) $n->toArray($this->tu)['url'], '/tu/persetujuan'));
    Notification::assertSentTo($this->kepsek, RegistrationAwaitingPrincipal::class,
        fn (RegistrationAwaitingPrincipal $n): bool => str_ends_with((string) $n->toArray($this->kepsek)['url'], '/kepsek/persetujuan'));
    Notification::assertNotSentTo($this->admin, RegistrationAwaitingPrincipal::class);

    $this->actingAs($this->tu)->get(route('tu.persetujuan.index'))->assertOk()->assertSee('ISPO 2027');

    Livewire::actingAs($this->tu)->test(Persetujuan::class)
        ->call('approve', $r->getKey())
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean surat tugas.');

    $r->refresh();
    expect($r->status)->toBe(RegistrationStatus::Disetujui)
        ->and((int) $r->principal_decision_by)->toBe((int) $this->tu->getKey());
    Notification::assertSentTo($this->nadiaUser, RegistrationApproved::class,
        fn (RegistrationApproved $n): bool => str_starts_with((string) $n->toArray($this->nadiaUser)['message'], 'Pendaftaranmu di ISPO 2027 disetujui oleh Sri Handayani, S.E. (Tata Usaha).'));
});

it('names the role that decided the lomba in the combined notices, on Lomba saya and in Usulan saya', function (): void {
    // An Admin rejects the lomba Nadia already registered for.
    $rejected = mlrWaiting($this);
    mlrDecide($this, $rejected, CompetitionStatus::Ditolak, $this->admin);

    Notification::assertSentTo($this->nadiaUser, CompetitionRejected::class, fn (CompetitionRejected $n): bool => $n->body
        === 'Admin tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu. Pendaftaranmu untuk lomba ini ikut dibatalkan.');
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $rejected))->assertOk()
        ->assertSee('Usulan lomba ini ditolak Admin, jadi pendaftarannya ikut dibatalkan.')
        ->assertDontSee('ditolak Kesiswaan');

    // Kesiswaan rejects a second one with a reason: the wording stays the Kesiswaan one.
    $second = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['name' => 'OPSI 2027']));
    $secondRegistration = app(CreateRegistration::class)->handle($this->nadiaUser, $second, RegistrationType::Individu, [], $this->guru->getKey());
    mlrDecide($this, $secondRegistration, CompetitionStatus::Ditolak, null, 'Bukan lomba tingkat kota.');

    Notification::assertSentTo($this->nadiaUser, CompetitionRejected::class, fn (CompetitionRejected $n): bool => $n->body
        === 'Alasan Kesiswaan: Bukan lomba tingkat kota. Pendaftaranmu untuk lomba ini ikut dibatalkan.');
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $secondRegistration))->assertOk()
        ->assertSee('Usulan lomba ini ditolak Kesiswaan, jadi pendaftarannya ikut dibatalkan.');

    // An Admin publishes a third: the publish notice names the Admin and where the registration went.
    $published = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['name' => 'LKTI 2027']));
    $publishedRegistration = app(CreateRegistration::class)->handle($this->nadiaUser, $published, RegistrationType::Individu, [], $this->guru->getKey());
    mlrDecide($this, $publishedRegistration, CompetitionStatus::Dipublikasikan, $this->admin);

    Notification::assertSentTo($this->nadiaUser, CompetitionPublished::class, fn (CompetitionPublished $n): bool => $n->body
        === 'Admin sudah memverifikasi lomba yang dikirim. Lomba ini sekarang tampil di katalog. Pendaftaranmu diteruskan untuk persetujuan.');

    // Usulan saya names the role next to the reason of a rejected usulan.
    $withNote = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['name' => 'Olimpiade Fiktif 2027']));
    app(VerifyCompetition::class)->handle($this->admin, $withNote, CompetitionStatus::Ditolak, 'Juknis tidak terbaca.');

    Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'usulan')
        ->assertSee('Catatan Admin: Juknis tidak terbaca.')
        ->assertDontSee('Catatan Kesiswaan: Juknis tidak terbaca.');
});

it('keeps the Kesiswaan wording of the lomba notices when no decider is given', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());

    expect(CompetitionRejected::for($lomba)->body)->toBe(CompetitionRejected::NO_REASON)
        ->and(CompetitionRejected::for($lomba, 'Info lama.')->body)->toBe('Alasan Kesiswaan: Info lama.')
        ->and(CompetitionPublished::for($lomba, true)->body)->toBe(CompetitionPublished::BODY)
        ->and(CompetitionRejected::for($lomba, null, false, $this->kesiswaan)->body)->toBe(CompetitionRejected::NO_REASON);
});
