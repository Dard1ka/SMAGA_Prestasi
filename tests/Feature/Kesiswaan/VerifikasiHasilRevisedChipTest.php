<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| FS1 QA-05 (2026-10-13): the status tag of the detail panel says "Laporan diperbaiki" for a report the ketua sent again
| after "Minta revisi" (the bell and the mail already say "Laporan hasil diperbaiki"), "Laporan baru" for a first one.
| Read from the audit row of the revision request (RequestReportRevision::wasRevised()). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->nadia = F::student('Nadia Putri Anindya', 'XI-D');
});

it('tags a report sent for the first time as "Laporan baru"', function (): void {
    $report = F::pendingReport($this->nadia);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class, ['report' => $report])
        ->assertSee('Laporan baru')
        ->assertDontSee('Laporan diperbaiki');

    expect(RequestReportRevision::wasRevised($report))->toBeFalse();
});

it('tags a report sent again after "Minta revisi" as "Laporan diperbaiki"', function (): void {
    $report = F::pendingReport($this->nadia);

    app(RequestReportRevision::class)->handle($this->kesiswaan, $report, 'Foto sertifikat buram, kirim ulang.');

    $resent = app(SubmitReport::class)->handle($this->nadia->user, $report->registration()->firstOrFail(), new SubmitReportData(
        result: ResultType::Juara3,
        authentic: true,
        certificate: UploadedFile::fake()->createWithContent('sertifikat-okn-2026.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"),
        photos: [UploadedFile::fake()->image('foto-1.jpg', 640, 480)],
    ));

    expect($resent->getKey())->toBe($report->getKey())
        ->and($resent->status)->toBe(ReportStatus::Dikirim);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class, ['report' => $resent])
        ->assertSee('Laporan diperbaiki')
        ->assertDontSee('Laporan baru');

    expect(RequestReportRevision::wasRevised($resent))->toBeTrue();
});
