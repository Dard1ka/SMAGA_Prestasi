<?php

declare(strict_types=1);

use App\Actions\Accounts\UpdateStudentPhone;
use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Actions\Reports\VerifyReport;
use App\Console\Commands\DemoLombaCommand;
use App\Domain\Accounts\PhoneNumber;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\LetterType;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Database\Seeders\ClassroomSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\TalentSeeder;
use Database\Seeders\WeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\KepalaSekolah\W2cFixtures;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 | smaga_server (the lead, 2026-11-05): "Buatkan satu lomba dummy yang daftar dan selesainya hari ini untuk presentasi
 | mulai dari cara daftar sampai upload hasil pada hari ini juga". One test walks the whole presentation on ONE frozen
 | day (5 Nov 2026, 09:00 WIB) through the Actions the screens call: `sth:lomba:demo` → Pendaftaran lomba → Persetujuan
 | lomba (Tata Usaha) → Surat tugas (both letters, Kirim) → `sth:lomba:demo --majukan` → Lomba saya › Laporan hasil →
 | Verifikasi hasil (Kesiswaan) → selesai with points. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ClassroomSeeder::class, TalentSeeder::class, WeightSeeder::class, SettingSeeder::class]);
    Notification::fake();
    F::today('2026-11-05');
});

afterEach(fn () => F::resetToday());

/** The letter draft as TataUsaha\SuratTugas::draft() builds it from its form (prefilled by LetterDraft::defaults()). */
function dlfDraft(array $form, string $number): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse($form['tanggalSurat']),
        basisFrom: $form['dasarDari'],
        basisNumber: trim($form['dasarNomor']) !== '' ? trim($form['dasarNomor']) : null,
        basisDate: trim($form['dasarTanggal']) !== '' ? CarbonImmutable::parse($form['dasarTanggal']) : null,
        scheduleDay: $form['hari'],
        scheduleDate: $form['tanggal'],
        scheduleTime: $form['pukul'],
        place: $form['tempat'],
        basisSubject: $form['dasarHal'],
    );
}

it('runs the whole presentation on one day: daftar, persetujuan, surat tugas, laporan hasil, poin', function (): void {
    // 1. The people of the presentation.
    F::staff(RoleName::Admin, 'Admin Operator STH');
    $tu = F::staff(RoleName::TataUsaha, 'Sri Handayani');
    $kesiswaan = F::staff(RoleName::Kesiswaan, 'Dewi Kartika Sari');
    $guru = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $nadia = F::student();
    $siswa = $nadia->user;

    expect($nadia->talentApplications()->count())->toBe(1);

    // 2. `php artisan sth:lomba:demo`: published today, open until 23:59, the one-day lomba today.
    $this->artisan('sth:lomba:demo')->assertSuccessful();

    $lomba = Competition::query()->where('name', DemoLombaCommand::nameFor(CarbonImmutable::today()))->sole();

    expect($lomba->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($lomba->bidang)->toBe(Bidang::Sains)
        ->and($lomba->level)->toBe(Level::Provinsi)
        ->and($lomba->weight_category)->toBe(WeightCategory::D)
        ->and($lomba->starts_on?->toDateString())->toBe('2026-11-05')
        ->and($lomba->final_on?->toDateString())->toBe('2026-11-05');

    // 3. Siswa › Detail lomba › Pendaftaran lomba (Siswa\Pendaftaran\Form::kirim()): own No HP, individu, guru pembimbing.
    expect(Gate::forUser($siswa)->allows('view', $lomba))->toBeTrue()
        ->and(Gate::forUser($siswa)->allows('create', [Registration::class, $lomba]))->toBeTrue()
        ->and(MentorChoice::find($guru->getKey())?->getKey())->toBe($guru->getKey());

    app(UpdateStudentPhone::class)->handle($siswa, $nadia, PhoneNumber::normalize('0812 3456 7801'));
    $registration = app(CreateRegistration::class)->handle($siswa, $lomba, RegistrationType::Individu, [], (int) $guru->getKey());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->type)->toBe(RegistrationType::Individu)
        ->and((int) $registration->mentor_id)->toBe((int) $guru->getKey());

    // 4. Tata Usaha › Persetujuan lomba › "Setujui" (Kepsek\Persetujuan, no other guru).
    expect(Gate::forUser($tu)->allows('decide', $registration->fresh()))->toBeTrue();

    app(ApproveRegistration::class)->handle($tu, $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);

    // 5. Tata Usaha › Surat tugas: the signature image set by the Admin, each letter from its prefilled form with a
    // typed number ("Buat PDF"), then "Kirim".
    W2cFixtures::signature();
    $form = LetterDraft::defaults($registration->fresh());

    expect($form['tanggalSurat'])->toBe('2026-11-05')
        ->and($form['hari'])->toBe('Kamis')
        ->and($form['tanggal'])->toBe('5 November 2026')
        ->and($form['tempat'])->toBe('Daring')
        ->and($form['dasarDari'])->not->toBe('');

    app(IssueLetters::class)->handle($tu, $registration->fresh(), LetterType::Siswa, dlfDraft($form, '400.3.8/313/MRD/XI/2026'));
    app(IssueLetters::class)->handle($tu, $registration->fresh(), LetterType::Guru, dlfDraft($form, '400.3.8.6/1681/2026'));

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and(SendLetters::missing($registration->fresh(), $registration->letters()->get()))->toBe([]);

    app(SendLetters::class)->handle($tu, $registration->fresh());

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);

    // 6. `php artisan sth:lomba:demo --majukan`: berlangsung and the report window at once.
    $this->artisan('sth:lomba:demo', ['--majukan' => true])
        ->expectsOutputToContain('Lomba demo dimajukan: 1 pendaftaran sekarang bisa mengirim laporan hasil.')
        ->assertSuccessful();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and(Gate::forUser($siswa)->allows('create', [Report::class, $registration->fresh()]))->toBeTrue();

    // 7. Lomba saya › Laporan hasil (Siswa\LombaSaya\LaporHasil::kirim()). The demo lomba is Sains, so the hasil, the
    // certificate, one photo and the keaslian tick are all the form needs (no research fields as for Riset).
    $report = app(SubmitReport::class)->handle($siswa, $registration->fresh(), new SubmitReportData(
        result: ResultType::Juara1,
        authentic: true,
        certificate: F::pdf('sertifikat-lomba-demo.pdf'),
        photos: [F::jpg('foto-lomba-demo.jpg')],
    ));

    expect($registration->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim)
        ->and($report->fresh()->status)->toBe(ReportStatus::Dikirim);

    // 8. Kesiswaan › Verifikasi hasil › "Verifikasi dan beri poin": four checks ticked, capaian akhir Juara 1.
    expect(Gate::forUser($kesiswaan)->allows('verify', $report->fresh()))->toBeTrue();

    $achievements = app(VerifyReport::class)->handle(
        $kesiswaan,
        $report->fresh(),
        ResultType::Juara1,
        array_fill_keys(array_keys(VerifyReport::CHECKS), true),
        'Sertifikat dan foto sesuai.',
    );

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Selesai)
        ->and($report->fresh()->status)->toBe(ReportStatus::Diverifikasi)
        ->and($achievements)->toHaveCount(1);

    // Provinsi 60 × Juara 1 1,00 × kategori D 0,40 = 24 poin.
    $achievement = Achievement::query()->where('student_id', $nadia->getKey())->sole();

    expect($achievement->competition_id)->toBe($lomba->getKey())
        ->and($achievement->result)->toBe(ResultType::Juara1)
        ->and($achievement->points)->toBe('24.00')
        ->and(CarbonImmutable::now()->toDateString())->toBe('2026-11-05');
});
