<?php

declare(strict_types=1);

use App\Actions\Registrations\AwaitReport;
use App\Actions\Reports\RequestReportRevision;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Guru\BinaanDetail;
use App\Models\Report;
use App\Notifications\Reports\ReportWindowOpened;
use App\Notifications\Verification\BinaanReportDecided;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| FS1 QA round 1 (LOMBA-07, QA-DC-01): an individu registration has no team and no ketua tim. The report-window mail, the
| guru's revision mail and the guru's Detail binaan banner word it for one student; a team keeps the team sentences.
| The "diverifikasi" mail already switched ($team ? 'Setiap anggota mendapat' : 'Poinnya'). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $this->citra = F::student('Citra Wulandari', 'XI-B');
});

afterEach(fn () => F::resetToday());

it('asks an individu student for the report without team wording (LOMBA-07)', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::Berlangsung, mentor: $this->guru);

    app(AwaitReport::class)->handle($registration, CarbonImmutable::parse('2026-09-25'));

    Notification::assertSentTo($this->citra->user, ReportWindowOpened::class, function (ReportWindowOpened $n): bool {
        $body = (string) $n->toArray($this->citra->user)['body'];

        return $body === 'Segera laporkan hasil lombamu. Poin diberikan setelah diverifikasi.'
            && ! str_contains(implode(' ', $n->toMail($this->citra->user)->introLines), 'anggota tim');
    });
});

it('keeps the team sentence for a team registration', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::Berlangsung, members: [F::student('Dimas Arya Nugroho')]);

    app(AwaitReport::class)->handle($registration, CarbonImmutable::parse('2026-09-25'));

    Notification::assertSentTo($this->citra->user, ReportWindowOpened::class, fn (ReportWindowOpened $n): bool => $n->toArray($this->citra->user)['body']
        === 'Segera laporkan hasil lombamu. Setiap anggota tim mendapat poin penuh setelah diverifikasi.');
});

it('reads a notice queued before the team flag as a team notice', function (): void {
    // A payload serialized by the old class had no `team` property: the declared default keeps the old sentence.
    $payload = str_replace('s:4:"team";b:0;', '', serialize(new ReportWindowOpened('01abc', 'Lomba Fiktif', false)));
    $payload = (string) preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':', $payload);
    $old = unserialize($payload);

    expect($old)->toBeInstanceOf(ReportWindowOpened::class)
        ->and($old->team)->toBeTrue()
        ->and($old->toArray(new stdClass)['body'])->toContain('Setiap anggota tim mendapat poin penuh');
});

it('tells the guru that the student, not a ketua tim, fixes an individu report (QA-DC-01)', function (): void {
    $report = K::pendingReport($this->citra, [], ResultType::Juara2, $this->guru);

    app(RequestReportRevision::class)->handle(K::staff(RoleName::Humas, 'Bapak Humas'), $report, 'Foto sertifikat buram');

    Notification::assertSentTo($this->guru, BinaanReportDecided::class, function (BinaanReportDecided $n): bool {
        $body = (string) $n->toArray($this->guru)['body'];

        return str_ends_with($body, 'Siswa memperbaiki laporan dari akunnya.') && ! str_contains($body, 'Ketua tim');
    });
});

it('keeps "Ketua tim" in the guru revision notice of a team report', function (): void {
    $report = K::pendingReport($this->citra, [F::student('Dimas Arya Nugroho')], ResultType::Juara2, $this->guru);

    app(RequestReportRevision::class)->handle(K::staff(RoleName::Humas, 'Bapak Humas'), $report, 'Foto sertifikat buram');

    Notification::assertSentTo($this->guru, BinaanReportDecided::class, fn (BinaanReportDecided $n): bool => str_ends_with((string) $n->toArray($this->guru)['body'], 'Ketua tim memperbaiki laporan dari akunnya.'));
});

it('words the Detail binaan banners for one student (QA-DC-01)', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::MenungguLaporan, mentor: $this->guru);

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration])
        ->assertSee('Siswa mengirim laporan dari akunnya.')
        ->assertDontSee('Ketua tim');

    $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat belum terbaca.', 'submitted_by' => $this->citra->user_id, 'submitted_at' => now()])->save();

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration])
        ->assertSee('Siswa memperbaiki laporan dari akunnya.')
        ->assertDontSee('Ketua tim');
});
