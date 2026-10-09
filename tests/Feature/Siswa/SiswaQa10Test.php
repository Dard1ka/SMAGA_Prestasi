<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Lomba\Detail;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Livewire\Siswa\Portofolio;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * QA10: student screens.
 * - Undangan tim works on a lomba Kesiswaan closed before its deadline; an invitation the scheduler expired is shown as
 *   expired ("Tidak menjawab"), never as the student's own decline; "Ketua tim sudah diberi tahu" only when it is true.
 * - Counts of registrations skip invitations nobody answered (Portofolio, Profil talenta, "N siswa SMAGA").
 * - Daftar lomba: an inactive default guru pembimbing is not pre-filled, and the Action's refusal is shown.
 * - A rejected report ends the tracker at Laporan hasil without points, and Riwayat says so.
 * - Cari lomba: a stale ?page= shows the last page; the order follows its comment.
 * - Detail lomba of a proposal Kesiswaan closed, rejected or has not checked shows that status, not "Dibuka".
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->withoutVite();
});

afterEach(fn () => HasilFixtures::resetToday());

function qa10RegSiswaBoot(object $t): void
{
    F::roles();
    $t->guru = F::staff(RoleName::Guru);
    $t->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $t->nadia = F::student();
    $t->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $t->keisha = F::student('Keisha Larasati', 'XI-6');
    User::query()->whereKey([$t->nadia->user_id, $t->dimas->user_id, $t->keisha->user_id])->update(['tour_seen_at' => now()]);
}

/** Nadia (ketua) invites the given students to a team of the lomba. */
function qa10RegSiswaTeam(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(F::user($t->nadia), $lomba, RegistrationType::Tim, array_map(fn ($s) => $s->getKey(), $members), $t->guru->id);
}

// Undangan tim ------------------------------------------------------------------------------------------------------

it('shows "Ikut tim" on a lomba Kesiswaan closed before its deadline, and the answer works (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $lomba = F::teamCompetition(3);
    $registration = qa10RegSiswaTeam($this, $lomba, [$this->dimas]);
    app(CloseCompetition::class)->handle($this->kesiswaan, $lomba);

    Livewire::actingAs(F::user($this->dimas))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Ikut tim ini?')
        ->call('ikut')
        ->assertHasNoErrors()
        ->assertRedirect(route('siswa.undangan.show', $registration));

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('shows an expired invitation as expired, never as the student\'s own decline (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $lomba = F::teamCompetition(3);
    $registration = qa10RegSiswaTeam($this, $lomba, [$this->dimas, $this->keisha]);
    app(DeclineMembership::class)->handle(F::user($this->keisha), $registration->fresh());
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();
    app(ExpirePendingInvitations::class)->handle($registration);

    Livewire::actingAs(F::user($this->dimas))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Undangan ini sudah tidak berlaku karena batas pendaftaran lewat.')
        ->assertDontSee('Kamu sudah menolak undangan ini.')
        ->assertDontSee('Ikut tim ini?');

    Livewire::actingAs(F::user($this->nadia))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSeeInOrder(['Dimas Arya Nugroho', 'Tidak menjawab', 'Keisha Larasati', 'Menolak']);

    // A real decline still reads as one.
    Livewire::actingAs(F::user($this->keisha))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Kamu sudah menolak undangan ini.');

    // The ketua's panel after the deadline was extended: the expired member did not answer, Keisha declined.
    $lomba->forceFill(['registration_closes_at' => now()->addDays(5)])->save();
    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Undang anggota pengganti')
        ->assertSeeInOrder(['Dimas Arya Nugroho', 'Tidak menjawab', 'Keisha Larasati', 'Menolak']);
});

it('says the deadline passed on an invitation nobody expired yet (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $lomba = F::teamCompetition(3);
    $registration = qa10RegSiswaTeam($this, $lomba, [$this->dimas]);
    $lomba->forceFill(['registration_closes_at' => now()->subMinute()])->save();

    Livewire::actingAs(F::user($this->dimas))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertDontSee('Ikut tim ini?')
        ->assertSee('Undangan ini sudah tidak berlaku karena batas pendaftaran lewat.');
});

it('claims the ketua was told about a decline only when the ketua can log in (QA10)', function (bool $leaderActive, string $toast): void {
    qa10RegSiswaBoot($this);
    $registration = qa10RegSiswaTeam($this, F::teamCompetition(3), [$this->dimas]);

    if (! $leaderActive) {
        F::user($this->nadia)->forceFill(['status' => UserStatus::Disabled])->save();
    }

    $component = Livewire::actingAs(F::user($this->dimas))->test(KonfirmasiTim::class, ['registration' => $registration]);
    $leaderActive
        ? $component->assertSee('Ketua tim akan diberi tahu.')
        : $component->assertDontSee('Ketua tim akan diberi tahu.');

    $component->call('tolak')->assertRedirect(route('siswa.beranda'));

    expect(session('toast'))->toBe(['tone' => 'info', 'message' => $toast]);
})->with([
    'ketua active' => [true, 'Undangan ditolak. Ketua tim sudah diberi tahu.'],
    'ketua disabled' => [false, 'Undangan ditolak.'],
]);

// Counts --------------------------------------------------------------------------------------------------------------

it('counts registrations the student joined, never an unanswered invitation (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $robotik = F::teamCompetition(3, ['name' => 'Olimpiade Robotik Nasional 2026']);
    // Dimas invites Nadia; she has not answered.
    app(CreateRegistration::class)->handle(F::user($this->dimas), $robotik, RegistrationType::Tim, [$this->nadia->getKey()], $this->guru->id);
    // Nadia's own registration.
    app(CreateRegistration::class)->handle(F::user($this->nadia), F::openCompetition(['name' => 'Lomba Esai Sains 2026']), RegistrationType::Individu, [], $this->guru->id);

    Livewire::actingAs(F::user($this->nadia))->test(Portofolio::class)
        ->assertViewHas('registrationCount', 1)
        ->assertViewHas('history', fn (array $history): bool => count($history) === 1 && $history[0]['title'] === 'Lomba Esai Sains 2026');

    Livewire::actingAs(F::user($this->nadia))->test(ProfilTalenta::class)
        ->assertViewHas('lombaCount', 1);

    expect(LombaPresenter::registrants([$robotik->getKey()])[$robotik->getKey()])->toBe(['Dimas Arya Nugroho']);
    Livewire::actingAs(F::user($this->keisha))->test(Detail::class, ['competition' => $robotik])
        ->assertSee('1</span> siswa SMAGA sudah mendaftar', false);
});

// Daftar lomba: guru pembimbing ---------------------------------------------------------------------------------------

it('pre-fills only an active guru as the lomba\'s guru pembimbing (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $gone = F::staff(RoleName::Guru, 'Bambang Wicaksono, S.Pd.');
    $gone->forceFill(['status' => UserStatus::Disabled])->save();

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => F::openCompetition(['default_mentor_id' => $gone->id])->getKey()])
        ->assertSet('mentorId', null);

    Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => F::openCompetition(['name' => 'Lomba Esai Sains 2026', 'default_mentor_id' => $this->guru->id])->getKey()])
        ->assertSet('mentorId', $this->guru->id);
});

it('shows the refusal when the chosen guru pembimbing is no longer active (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    $lomba = F::openCompetition(['default_mentor_id' => $this->guru->id]);
    $component = Livewire::actingAs(F::user($this->nadia))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('setuju', true);

    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    $component->call('kirim')
        ->assertHasErrors(['mentorId'])
        ->assertSee('Pilih guru pembimbing.');

    expect(Registration::query()->count())->toBe(0);
});

// A rejected report ---------------------------------------------------------------------------------------------------

it('ends the tracker at Laporan hasil without points for a rejected report (QA10)', function (): void {
    $this->blade('<x-sth.tracker :status="$s" :report="$r" :dates="[5 => \'12 Okt\']" />', ['s' => RegistrationStatus::Selesai, 'r' => ReportStatus::Ditolak])
        ->assertSee('class="trk-step s-end"', false)
        ->assertSee('laporan ditolak, tanpa poin')
        ->assertSee('Tanpa poin')
        ->assertSee('tidak dicapai')
        ->assertDontSee('aria-current="step"', false);

    $this->blade('<x-sth.tracker :status="$s" report="ditolak" compact />', ['s' => RegistrationStatus::Selesai])
        ->assertSee('Laporan ditolak, tanpa poin')
        ->assertSee('<span class="num">5</span> dari 6', false);

    // Other callers pass no report: unchanged.
    $this->blade('<x-sth.tracker status="selesai" compact />')->assertSee('<span class="num">6</span> dari 6', false)->assertDontSee('tanpa poin');
    $this->blade('<x-sth.tracker :status="$s" report="diverifikasi" />', ['s' => RegistrationStatus::Selesai])->assertDontSee('s-up', false);
});

it('shows a rejected report on Lomba saya and Portofolio › Riwayat without a "Selesai" claim (QA10)', function (): void {
    HasilFixtures::boot();
    HasilFixtures::today('2026-10-20');
    $nadia = HasilFixtures::student();
    $registration = HasilFixtures::registration($nadia, RegistrationStatus::Selesai);
    Report::factory()->create([
        'registration_id' => $registration->getKey(),
        'claimed_result' => ResultType::Juara2,
        'submitted_by' => $nadia->user_id,
        'status' => ReportStatus::Ditolak,
    ]);
    $nadia->user->forceFill(['tour_seen_at' => now()])->save();

    Livewire::actingAs($nadia->user)->test(LombaSaya::class, ['registration' => $registration])
        ->assertSee('Laporan ditolak, tanpa poin')
        ->assertSee('class="trk-step s-end"', false);

    Livewire::actingAs($nadia->user)->test(Portofolio::class)
        ->assertViewHas('history', fn (array $history): bool => $history[0]['chip'] === 'Laporan ditolak' && $history[0]['tone'] === 'red')
        ->assertSee('Laporan ditolak');
});

// Cari lomba ----------------------------------------------------------------------------------------------------------

it('shows the last page of Cari lomba for a stale ?page= instead of an empty catalog (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    F::openCompetition(['name' => 'Lomba Esai Sains 2026']);

    Livewire::withQueryParams(['page' => 4])->actingAs(F::user($this->nadia))->test(Katalog::class)
        ->assertSee('Lomba Esai Sains 2026')
        ->assertDontSee('Belum ada lomba di katalog');
});

it('lists open lomba first (closing soonest), then upcoming, then closed (most recent first) (QA10)', function (): void {
    qa10RegSiswaBoot($this);
    F::openCompetition(['name' => 'Buka Sepuluh Hari', 'registration_closes_at' => now()->addDays(10)]);
    F::openCompetition(['name' => 'Buka Tiga Hari', 'registration_closes_at' => now()->addDays(3)]);
    F::openCompetition(['name' => 'Segera Dibuka', 'registration_opens_at' => now()->addDay(), 'registration_closes_at' => now()->addDays(5)]);
    F::openCompetition(['name' => 'Tutup Sebulan', 'registration_opens_at' => now()->subDays(60), 'registration_closes_at' => now()->subDays(30)]);
    F::openCompetition(['name' => 'Tutup Kemarin Lusa', 'registration_opens_at' => now()->subDays(40), 'registration_closes_at' => now()->subDays(2)]);

    Livewire::actingAs(F::user($this->nadia))->test(Katalog::class)
        ->assertViewHas('cards', fn (array $cards): bool => array_column($cards, 'title') === [
            'Buka Tiga Hari', 'Buka Sepuluh Hari', 'Segera Dibuka', 'Tutup Kemarin Lusa', 'Tutup Sebulan',
        ]);
});

// Detail lomba of the proposer ----------------------------------------------------------------------------------------

it('shows the status of a proposal instead of "Dibuka" to the student who proposed it (QA10)', function (CompetitionStatus $status, string $chip, string $title): void {
    qa10RegSiswaBoot($this);
    $lomba = F::openCompetition();
    $lomba->forceFill(['status' => $status, 'submitted_by' => F::user($this->nadia)->id])->save();

    Livewire::actingAs(F::user($this->nadia))->test(Detail::class, ['competition' => $lomba])
        ->assertViewHas('window', fn (array $window): bool => $window['label'] === $chip)
        ->assertDontSee('Dibuka · ')
        ->assertSee($title);
})->with([
    'closed early' => [CompetitionStatus::Ditutup, 'Ditutup', 'Ditutup Kesiswaan'],
    'rejected' => [CompetitionStatus::Ditolak, 'Ditolak', 'Usulan ditolak'],
    'waiting' => [CompetitionStatus::MenungguVerifikasi, 'Menunggu verifikasi', 'Belum tampil di katalog'],
]);
