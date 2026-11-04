<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\BinaanDetail;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| SV1 review (round 3 had fixed Lomba saya only): Guru › Siswa binaan and its detail showed the guru pembimbing the
| tracker's orange "perlu tindakan" at Laporan hasil, although a guru never reports (only the ketua does,
| ReportPolicy::create). The guru now sees Laporan hasil as the current phase; every other phase keeps the state its
| status gives. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $this->citra = F::student('Citra Wulandari', 'XI-2');
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-2');
});

/** @return array{0: Testable, 1: array<string, mixed>} the Siswa binaan list of $guru on $tab and its card of $registration */
function gbtList(User $guru, Registration $registration, string $tab = 'aktif'): array
{
    $page = Livewire::withQueryParams(['tab' => $tab])->actingAs($guru)->test(Binaan::class);

    return [$page, collect($page->viewData('registrations'))->firstWhere('id', (string) $registration->getKey())];
}

it('shows the guru Laporan hasil as the current phase, never "perlu tindakan", on the list and the detail', function (bool $team): void {
    $registration = F::registration($this->citra, RegistrationStatus::MenungguLaporan, members: $team ? [$this->dimas] : [], mentor: $this->guru);

    [$list, $card] = gbtList($this->guru, $registration);

    expect($card['trackerState'])->toBe('current');
    $list->assertSee('Laporan hasil')
        ->assertSeeHtml('sg sg-cur')
        ->assertDontSeeHtml('sg sg-act')
        ->assertDontSee('perlu tindakan');

    $detail = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);

    expect($detail->viewData('trackerState'))->toBe('current');
    $detail->assertSeeHtml('class="trk-step s-cur"')
        ->assertDontSeeHtml('class="trk-step s-act"')
        ->assertDontSee('perlu tindakan')
        // The alert still says the report is awaited from the student's account.
        ->assertSee('Laporan hasil belum dikirim');
})->with([
    'tim' => [true],
    'individu' => [false],
]);

it('leaves every other phase to the state its status gives', function (RegistrationStatus $status, string $step): void {
    $registration = F::registration($this->citra, $status, members: [$this->dimas], mentor: $this->guru);

    [, $card] = gbtList($this->guru, $registration, $status->isFinal() ? 'selesai' : 'aktif');
    expect($card['trackerState'])->toBeNull();

    $detail = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);
    expect($detail->viewData('trackerState'))->toBeNull();
    $detail->assertSeeHtml($step);
})->with([
    'berlangsung' => [RegistrationStatus::Berlangsung, 'class="trk-step s-cur"'],
    'laporan dikirim' => [RegistrationStatus::LaporanDikirim, 'class="trk-step s-cur"'],
    'selesai' => [RegistrationStatus::Selesai, 'class="trk-step s-done"'],
]);

/*
| Review fixes: the chip beside that tracker still said the orange "Menunggu laporan" / "Perlu revisi" (= needs action,
| DESIGN-SPEC-v3) to a guru who never reports. The guru now reads the gray of waiting on the student, worded for staff.
| And both guru trackers end at Laporan hasil for a rejected report, as Lomba saya does (QA10, D-34).
*/

/** A report of $registration sent by the ketua, in $status. */
function gbtReport(Registration $registration, ReportStatus $status): Report
{
    $report = Report::factory()->create([
        'registration_id' => $registration->getKey(),
        'claimed_result' => ResultType::Finalis,
        'submitted_by' => $registration->leader?->user_id,
    ]);
    $report->forceFill(['status' => $status, 'note' => $status === ReportStatus::Diverifikasi ? null : 'Sertifikat buram, upload ulang.'])->save();

    return $report;
}

it('gives the guru a gray waiting chip while the student reports or revises, never the orange request', function (?ReportStatus $report, array $chip): void {
    $registration = F::registration($this->citra, RegistrationStatus::MenungguLaporan, members: [$this->dimas], mentor: $this->guru);
    if ($report !== null) {
        gbtReport($registration, $report);
    }

    [$list, $card] = gbtList($this->guru, $registration);
    expect([$card['chip'], $card['tone']])->toBe($chip)
        ->and($chip[1])->toBe('gray');
    $list->assertSee($chip[0]);

    $detail = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);
    expect([$detail->viewData('chip'), $detail->viewData('tone')])->toBe($chip);
    $detail->assertSee($chip[0])->assertDontSee('kamu');
})->with([
    'menunggu laporan' => [null, Binaan::REPORT_CHIP],
    'laporan perlu revisi' => [ReportStatus::PerluRevisi, Binaan::REVISION_CHIP],
]);

it('keeps every other chip of the guru as it was', function (RegistrationStatus $status, ?ReportStatus $report, array $chip): void {
    $registration = F::registration($this->citra, $status, members: [$this->dimas], mentor: $this->guru);
    if ($report !== null) {
        gbtReport($registration, $report);
    }

    [, $card] = gbtList($this->guru, $registration, $status->isFinal() ? 'selesai' : 'aktif');
    expect([$card['chip'], $card['tone']])->toBe($chip);

    $detail = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);
    expect([$detail->viewData('chip'), $detail->viewData('tone')])->toBe($chip);
})->with([
    'berlangsung' => [RegistrationStatus::Berlangsung, null, [RegistrationStatus::Berlangsung->label(), RegistrationStatus::Berlangsung->tone()]],
    'laporan dikirim' => [RegistrationStatus::LaporanDikirim, ReportStatus::Dikirim, ['Laporan menunggu verifikasi', 'blue']],
    'selesai' => [RegistrationStatus::Selesai, ReportStatus::Diverifikasi, [RegistrationStatus::Selesai->label(), 'green']],
    'laporan ditolak' => [RegistrationStatus::Selesai, ReportStatus::Ditolak, ['Laporan ditolak', 'red']],
]);

it('ends the guru tracker at Laporan hasil without points for a rejected report, as Lomba saya (QA10, D-34)', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::Selesai, members: [$this->dimas], mentor: $this->guru);
    gbtReport($registration, ReportStatus::Ditolak);

    [$list, $card] = gbtList($this->guru, $registration, 'selesai');
    expect($card['reportStatus'])->toBe(ReportStatus::Ditolak);
    // Compact: the fifth segment is the red end, the line says why; before, all six segments read "done".
    $list->assertSeeText('Tahap 5 dari 6 · Laporan ditolak, tanpa poin')
        ->assertSeeHtml('sg bg-red');

    $detail = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);
    expect($detail->viewData('reportStatus'))->toBe(ReportStatus::Ditolak);
    $detail->assertSeeHtml('class="trk-step s-end"')
        ->assertSee('Tanpa poin')
        ->assertSee('laporan ditolak, tanpa poin');
});

it('keeps the tracker of a verified report all done for the guru', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::Selesai, members: [$this->dimas], mentor: $this->guru);
    gbtReport($registration, ReportStatus::Diverifikasi);

    [$list, $card] = gbtList($this->guru, $registration, 'selesai');
    expect($card['reportStatus'])->toBe(ReportStatus::Diverifikasi);
    $list->assertSeeText('Tahap 6 dari 6 · Poin & apresiasi')->assertDontSeeHtml('sg bg-red');

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration])
        ->assertDontSeeHtml('class="trk-step s-end"')
        ->assertDontSee('Tanpa poin');
});
