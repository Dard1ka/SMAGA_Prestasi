<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\File;
use App\Models\Report;
use App\Notifications\Reports\ReportSubmitted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');

    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->registration = F::registration($this->nadia, members: [$this->dimas]);
    // The lomba of the registration carries its juknis (D-147); the checks below count what a report adds.
    $this->filesBefore = File::query()->count();
});

afterEach(fn () => F::resetToday());

function w2a2ReportData(array $overrides = []): SubmitReportData
{
    return new SubmitReportData(...array_merge([
        'result' => ResultType::MedaliEmas,
        'authentic' => true,
        'certificate' => F::pdf(),
        'photos' => [F::jpg('foto-1.jpg'), F::jpg('foto-2.jpg')],
        'researchTitle' => 'Biofilter sabut kelapa untuk air sumur payau',
        'researchAbstract' => 'Biofilter berbahan sabut kelapa diuji untuk menurunkan kadar garam air sumur.',
        'researchReport' => F::pdf('laporan-penelitian.pdf'),
    ], $overrides));
}

it('lets the ketua send the report: laporan_dikirim, files on the private disk, audit, Kesiswaan notified', function (): void {
    $report = app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData([
        'announcement' => F::jpg('pengumuman.jpg'),
    ]));

    expect($this->registration->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim)
        ->and($report->status)->toBe(ReportStatus::Dikirim)
        ->and($report->claimed_result)->toBe(ResultType::MedaliEmas)
        ->and($report->late)->toBeFalse()
        ->and($report->submitted_by)->toBe($this->nadia->user->getKey())
        ->and($report->research_title)->toBe('Biofilter sabut kelapa untuk air sumur payau')
        ->and($report->files()->where('kind', ReportFileKind::Foto)->count())->toBe(2)
        ->and($report->files()->where('kind', ReportFileKind::Sertifikat)->count())->toBe(1)
        ->and($report->files()->where('kind', ReportFileKind::Pengumuman)->count())->toBe(1)
        ->and($report->files()->where('kind', ReportFileKind::LaporanRiset)->count())->toBe(1);

    $files = File::query()->where('purpose', FilePurpose::ReportEvidence)->get();
    expect($files)->toHaveCount(5)
        ->and(File::query()->count())->toBe($this->filesBefore + 5);
    $files->each(function (File $file): void {
        expect($file->purpose)->toBe(FilePurpose::ReportEvidence)
            ->and($file->disk)->toBe('local')
            ->and($file->owner_user_id)->toBe($this->nadia->user->getKey())
            ->and($file->path)->toStartWith('lampiran/');
        Storage::disk('local')->assertExists($file->path);
    });

    expect(Activity::query()->where('event', 'report.submitted')->where('causer_id', $this->nadia->user->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'registration.laporan_dikirim')->exists())->toBeTrue();

    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class, function (ReportSubmitted $n, array $channels): bool {
        return $n->competitionName === 'WICE 2026' && $n->leaderName === 'Nadia Putri Anindya'
            && $channels === ['mail', 'database'] && $n instanceof ShouldQueue;
    });
});

it('accepts a report long after the final and never marks it late (D-151)', function (): void {
    F::today('2027-01-15'); // months after the final of 24 Sep 2026
    $report = app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData());

    expect($report->fresh()->late)->toBeFalse()
        ->and($this->registration->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);
    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class, fn (ReportSubmitted $n): bool => ! str_contains((string) $n->toArray($this->kesiswaan)['body'], 'batas waktu'));
});

it('only lets the ketua submit, and only while the registration waits for the report', function (): void {
    expect(fn () => app(SubmitReport::class)->handle($this->dimas->user, $this->registration, w2a2ReportData()))
        ->toThrow(AuthorizationException::class);

    $outsider = F::student('Rizky Maulana', 'XI-A');
    expect(fn () => app(SubmitReport::class)->handle($outsider->user, $this->registration, w2a2ReportData()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitReport::class)->handle($this->kesiswaan, $this->registration, w2a2ReportData()))
        ->toThrow(AuthorizationException::class);

    foreach ([RegistrationStatus::Berlangsung, RegistrationStatus::LaporanDikirim, RegistrationStatus::Selesai, RegistrationStatus::SuratTerbit] as $status) {
        $this->registration->forceFill(['status' => $status])->save();

        expect(fn () => app(SubmitReport::class)->handle($this->nadia->user, $this->registration->fresh(), w2a2ReportData()))
            ->toThrow(AuthorizationException::class);
    }

    expect(Report::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe($this->filesBefore);
});

it('requires the authenticity statement, a certificate and at least one photo', function (): void {
    try {
        app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData([
            'authentic' => false,
            'certificate' => null,
            'photos' => [],
        ]));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['asli', 'sertifikat', 'foto']);
    }

    expect(fn () => app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData([
        'photos' => array_map(fn (int $i) => F::jpg("foto-{$i}.jpg"), range(1, 6)),
    ])))->toThrow(ValidationException::class);

    expect($this->registration->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and(File::query()->count())->toBe($this->filesBefore);
});

it('requires title, abstract (max 250 words) and the research report only for riset lomba', function (): void {
    try {
        app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData([
            'researchTitle' => '',
            'researchAbstract' => str_repeat('kata ', 251),
            'researchReport' => null,
        ]));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['judul', 'abstrak', 'laporan']);
    }

    $olahraga = F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]);
    $individual = F::registration(F::student('Yoga Pratama', 'XII-E'), competition: $olahraga);
    $leader = $individual->leader->user;

    $report = app(SubmitReport::class)->handle($leader, $individual, w2a2ReportData([
        'researchTitle' => null,
        'researchAbstract' => null,
        'researchReport' => F::pdf('laporan.pdf'),
        'poster' => F::jpg('poster.jpg'),
    ]));

    expect($report->research_title)->toBeNull()
        ->and($report->research_abstract)->toBeNull()
        ->and($report->files()->whereIn('kind', [ReportFileKind::LaporanRiset, ReportFileKind::Poster])->count())->toBe(0);
});

it('refuses a PDF with active content and a fake image, storing nothing', function (): void {
    $evil = UploadedFile::fake()->createWithContent('sertifikat.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\n%%EOF");

    try {
        app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData(['certificate' => $evil]));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('sertifikat');
    }

    $notImage = UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1;');

    expect(fn () => app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData(['photos' => [$notImage]])))
        ->toThrow(ValidationException::class);

    expect(File::query()->count())->toBe($this->filesBefore)
        ->and(Storage::disk('local')->allFiles('lampiran'))->toBe([])
        ->and($this->registration->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan);
});

it('re-sends after "perlu revisi" on the same report row, replacing only the re-uploaded kinds', function (): void {
    $first = app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData());
    $oldCertificate = $first->files()->where('kind', ReportFileKind::Sertifikat)->value('file_id');
    $oldReport = $first->files()->where('kind', ReportFileKind::LaporanRiset)->value('file_id');

    // What W2-B's RequestReportRevision does.
    $first->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram, upload ulang.'])->save();
    $this->registration->forceFill(['status' => RegistrationStatus::MenungguLaporan])->save();

    $second = app(SubmitReport::class)->handle($this->nadia->user, $this->registration->fresh(), w2a2ReportData([
        'result' => ResultType::MedaliPerak,
        'certificate' => F::pdf('sertifikat-baru.pdf'),
        'photos' => [],
        'researchReport' => null,
    ]));

    expect($second->getKey())->toBe($first->getKey())
        ->and(Report::query()->count())->toBe(1)
        ->and($second->status)->toBe(ReportStatus::Dikirim)
        ->and($second->claimed_result)->toBe(ResultType::MedaliPerak)
        ->and($second->files()->where('kind', ReportFileKind::Sertifikat)->value('file_id'))->not->toBe($oldCertificate)
        ->and($second->files()->where('kind', ReportFileKind::Foto)->count())->toBe(2)
        ->and($second->files()->where('kind', ReportFileKind::LaporanRiset)->value('file_id'))->toBe($oldReport)
        ->and($this->registration->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);

    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class, fn (ReportSubmitted $n): bool => $n->resubmitted);
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('keeps a report that came on time on time when it is sent again after a revision request (QA8)', function (): void {
    F::today('2026-11-08'); // the last day: final 24 Sep + 45 days
    $report = app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData());
    expect($report->late)->toBeFalse();

    // Kesiswaan asked for a revision after the deadline: the student answers it, and is not "late" for that.
    F::today('2026-11-20');
    app(RequestReportRevision::class)->handle($this->kesiswaan, $report->fresh(), 'Sertifikat buram, kirim ulang.');
    $again = app(SubmitReport::class)->handle($this->nadia->user, $this->registration->fresh(), w2a2ReportData());

    expect($again->late)->toBeFalse();
});

it('tells Kesiswaan and Humas about a new report, each with the link of the own area (D-139)', function (): void {
    $humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $both = F::staff(RoleName::Kesiswaan, 'Ibu Ratna Sari');
    $both->assignRole(RoleName::Humas->value);
    $frozen = F::staff(RoleName::Humas, 'Bu Fitri Handayani');
    $frozen->forceFill(['status' => UserStatus::Disabled])->save();
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');

    app(SubmitReport::class)->handle($this->nadia->user, $this->registration, w2a2ReportData());

    Notification::assertSentToTimes($this->kesiswaan, ReportSubmitted::class, 1);
    Notification::assertSentToTimes($humas, ReportSubmitted::class, 1);
    Notification::assertSentToTimes($both, ReportSubmitted::class, 1);
    Notification::assertNothingSentTo($frozen);
    Notification::assertNothingSentTo($guru);
    Notification::assertSentTo($humas, ReportSubmitted::class, fn (ReportSubmitted $n): bool => $n->toArray($humas)['url'] === route('humas.verifikasi.index')
        && $n->toMail($humas)->actionUrl === route('humas.verifikasi.index')
        && $n->toMail($humas)->actionText === 'Buka verifikasi hasil');
    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class, fn (ReportSubmitted $n): bool => $n->toArray($this->kesiswaan)['url'] === route('kesiswaan.verifikasi.index'));
    Notification::assertSentTo($both, ReportSubmitted::class, fn (ReportSubmitted $n): bool => $n->toArray($both)['url'] === route('kesiswaan.verifikasi.index'));
});
