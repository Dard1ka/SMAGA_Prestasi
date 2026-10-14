<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\Lomba\Usulkan;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 QA round 1 (track QA1), student lomba screens: Detail lomba at 390 px and 1366 px, Cari lomba › Usulan saya, the
| Usulkan lomba page after "Kirim pendaftaran", the Pendaftaran lomba panel past the approval, individu wording and a
| registration window with one date. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

/** The rendered page without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qaPlain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

/** A registration of $student (ketua) for $competition in $status. */
function fs1qaRegistration(Competition $competition, Student $student, RegistrationStatus $status, RegistrationType $type = RegistrationType::Individu): Registration
{
    return Registration::factory()->create([
        'competition_id' => $competition->getKey(),
        'leader_student_id' => $student->getKey(),
        'type' => $type,
        'status' => $status,
    ]);
}

it('lets the "Kategori penyelenggara" tag of a government lomba wrap on a phone (LOMBA-01)', function (): void {
    $lomba = F::openCompetition(['weight_category' => WeightCategory::B, 'event_status' => EventStatus::PemerintahNonPuspresnas]);

    $html = fs1qaPlain((string) $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $lomba))->assertOk()->getContent());

    preg_match('/<span class="([^"]*)">\s*Kategori penyelenggara B · Pemerintah \(non-Puspresnas\)/', $html, $tag);
    expect($tag[1] ?? '')->toContain('chip')->toContain('chip-wrap');
});

it('words the Juara I estimate without "tim" for an individu lomba and with it for a team lomba (LOMBA-07)', function (): void {
    $individu = F::openCompetition(['name' => 'Lomba Individu Fiktif 2026', 'participation' => Participation::Individu]);
    $tim = F::teamCompetition(3, ['name' => 'Lomba Tim Fiktif 2026']);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $individu))->assertOk()
        ->assertSee('Perkiraan poin jika Juara I')
        ->assertDontSee('tiap anggota tim dapat poin penuh');

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $tim))->assertOk()
        ->assertSee('(tiap anggota tim dapat poin penuh)');
});

it('keeps the tab row of Detail lomba from scrolling up and down (LOMBA-10)', function (): void {
    $lomba = F::openCompetition();

    $html = (string) $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $lomba))->assertOk()->getContent();
    preg_match('/<div role="tablist"\s+aria-label="Info lomba"[^>]*class="([^"]*)"/', $html, $row);

    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match('/\n    \.stabs \{(.*?)\n    \}/s', $css, $stabs);
    preg_match('/\n    \.stab \{(.*?)\n    \}/s', $css, $stab);

    expect($row[1] ?? '')->toBe('stabs')
        ->and($stabs[1] ?? '')->toContain('overflow-x: auto;')->toContain('overflow-y: hidden;')
        ->toContain('box-shadow: inset 0 -1px 0 var(--color-line);')
        // A tab that reached 1px below its row was what made the row scroll.
        ->and($stab[1] ?? '')->not->toContain('margin-bottom: -1px;');
});

it('names which end of the registration a single date is (LOMBA-13)', function (): void {
    $closesOnly = F::openCompetition(['registration_opens_at' => null, 'registration_closes_at' => '2026-10-25 23:59:59']);
    $opensOnly = F::openCompetition(['registration_opens_at' => '2026-10-12 00:00:00', 'registration_closes_at' => null]);
    $both = F::openCompetition(['registration_opens_at' => '2026-10-15 00:00:00', 'registration_closes_at' => '2026-11-01 23:59:59']);

    expect(LombaPresenter::pendaftaran($closesOnly))->toBe('Sampai 25 Okt 2026')
        ->and(LombaPresenter::pendaftaran($opensOnly))->toBe('Mulai 12 Okt 2026')
        ->and(LombaPresenter::pendaftaran($both))->toBe('15 Okt – 1 Nov 2026')
        ->and(LombaPresenter::pendaftaran(F::openCompetition(['registration_opens_at' => null, 'registration_closes_at' => null])))->toBeNull();

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $closesOnly))->assertOk()
        ->assertSee('Sampai 25 Okt 2026');
});

it('words the Cari lomba subtitle for both tabs (LOMBA-03)', function (): void {
    F::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Menunggu Fiktif']);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index', ['tab' => 'usulan']))->assertOk()
        ->assertSee('Lomba di katalog sudah diperiksa Kesiswaan.')
        ->assertDontSee('Semua lomba sudah diperiksa Kesiswaan.');
});

it('shows status and "Lihat" of an own proposal without a sideways scroll on a phone (LOMBA-05)', function (): void {
    $c = F::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Menunggu Fiktif']);

    $html = fs1qaPlain(Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'usulan')->html());

    // No floor below sm: the table fits a 390 px card.
    expect($html)->toContain('table-fixed sm:min-w-[520px]')->not->toContain('table-fixed min-w-[520px]')
        // The status is under the name below sm, and its own column only from sm up.
        ->toMatch('/class="chip c-gray chip-sm chip-wrap mt-1 sm:hidden">\s*Menunggu verifikasi/')
        ->toContain('<td class="hidden sm:table-cell"><span class="chip c-')
        ->toContain('href="'.route('siswa.lomba.show', $c).'"');
});

it('says the invitation of a member waits for Kesiswaan when the lomba still waits (LOMBA-04)', function (): void {
    $own = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['name' => 'Lomba Tim Usulan Fiktif', 'participation' => Participation::Tim, 'max_team_size' => 3]));
    $published = F::teamCompetition(3, ['name' => 'Lomba Tim Terbit Fiktif']);
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $own->getKey()])
        ->set('type', 'tim')->set('q', 'Dimas')->call('tambah', $dimas->getKey())
        ->assertSee('diundang setelah Kesiswaan memverifikasi lomba')
        ->assertDontSee('diundang setelah kamu kirim');

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $published->getKey()])
        ->set('type', 'tim')->set('q', 'Dimas')->call('tambah', $dimas->getKey())
        ->assertSee('diundang setelah kamu kirim');
});

it('drops "Belum mau mendaftar sekarang? … Nanti saja" once the registration is sent (LOMBA-04)', function (): void {
    $t = Livewire::actingAs($this->nadiaUser)->test(Usulkan::class);

    foreach (F::usulan() as $k => $v) {
        $t->set("form.$k", $v);
    }

    $t->set('form.juknis', K::pdf())->call('simpan')->assertHasNoErrors()
        ->assertSee('Belum mau mendaftar sekarang?')->assertSee('Nanti saja');

    // What "Kirim pendaftaran" of the panel leaves behind, then the panel's CHANGED event.
    fs1qaRegistration(Competition::query()->sole(), $this->nadia, RegistrationStatus::MenungguLomba);

    $t->dispatch(PendaftaranForm::CHANGED)
        ->assertDontSee('Belum mau mendaftar sekarang?')->assertDontSee('Nanti saja')
        ->assertSee('Buka Detail lomba')
        ->assertSeeHtml('href="'.route('siswa.lomba.show', Competition::query()->sole()).'"');
});

it('shows the current stage instead of pending steps once the lomba ran (LOMBA-06)', function (): void {
    $lomba = F::openCompetition(['name' => 'Lomba Individu Fiktif 2026']);
    $registration = fs1qaRegistration($lomba, $this->nadia, RegistrationStatus::MenungguLaporan);

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Pendaftaran disetujui')->assertDontSee('Pendaftaran terkirim')
        ->assertSee('Menunggu laporan')
        ->assertSee('Laporkan hasil')->assertSee('Segera laporkan hasil lombamu.')
        // An individu registration hears nothing about a team.
        ->assertDontSee('Setiap anggota tim')
        ->assertSeeHtml('href="'.route('siswa.lapor', $registration).'"')
        // Both earlier steps are done (check marks), no pending number left.
        ->assertDontSeeHtml('<span class="num">1</span>')
        ->assertDontSeeHtml('<span class="num">2</span>');
});

it('asks for the corrected report, and tells a member that the ketua reports (LOMBA-06)', function (): void {
    $lomba = F::teamCompetition(3, ['name' => 'Lomba Tim Fiktif 2026']);
    $registration = fs1qaRegistration($lomba, $this->nadia, RegistrationStatus::MenungguLaporan, RegistrationType::Tim);
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $member = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $dimas->getKey(), 'invited_at' => now()]);
    $member->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();

    $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat belum terbaca.', 'submitted_by' => $this->nadiaUser->getKey(), 'submitted_at' => now()])->save();

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Laporan hasil perlu diperbaiki')
        ->assertSee('Perbaiki laporan')
        ->assertSeeHtml('href="'.route('siswa.lapor', $registration).'"');

    Livewire::actingAs(F::user($dimas))->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Kamu sudah terdaftar')
        ->assertSee('Ketua tim memperbaiki laporan dari akunnya.')
        ->assertDontSeeHtml('href="'.route('siswa.lapor', $registration).'"');
});

it('marks the surat tugas step done once the letters are out, and names the running lomba (LOMBA-06)', function (): void {
    $lomba = F::openCompetition();
    $registration = fs1qaRegistration($lomba, $this->nadia, RegistrationStatus::SuratTerbit);

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertDontSeeHtml('<span class="num">2</span>')
        ->assertDontSee('Lomba berlangsung');

    $registration->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Lomba berlangsung')
        ->assertSee('Setelah lomba selesai, laporkan hasilnya di Lomba saya.');
});

it('keeps the steps as they were before the approval', function (): void {
    $lomba = F::openCompetition();
    fs1qaRegistration($lomba, $this->nadia, RegistrationStatus::MenungguKepsek);

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Pendaftaran terkirim')
        ->assertSeeHtml('<span class="num">1</span>')
        ->assertSeeHtml('<span class="num">2</span>')
        ->assertDontSee('Laporkan hasil');
});
