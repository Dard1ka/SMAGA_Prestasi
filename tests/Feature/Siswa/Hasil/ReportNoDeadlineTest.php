<?php

declare(strict_types=1);

use App\Actions\Registrations\AwaitReport;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Domain\Deadlines\ReportFollowUp;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\BatasArsip;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Sekolah\Dashboard;
use App\Livewire\Siswa\Beranda;
use App\Models\Report;
use App\Notifications\Reports\ReportSubmitted;
use App\Notifications\Reports\ReportWindowOpened;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| D-151 (FS1 Paket B): a laporan hasil has no deadline any more. No screen or mail prints a due date, days left,
| "Lewat batas" or "Terlambat"; a report is never marked late. The school dashboard counts "Belum lapor > 14 hari".
| ReportDeadline and the setting key stay in code (unused for display).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->ketua = F::student();
    $this->ketuaUser = $this->ketua->user;
    // WICE 2026: final 24 Sep 2026; under the old rule the report was due 8 Nov 2026.
    $this->registration = F::registration($this->ketua, RegistrationStatus::MenungguLaporan, members: [F::student('Dimas Arya Nugroho')], mentor: $this->guru);
});

afterEach(fn () => F::resetToday());

function noDeadlineReport(): SubmitReportData
{
    return new SubmitReportData(
        result: ResultType::MedaliEmas,
        authentic: true,
        certificate: F::pdf(),
        photos: [F::jpg('foto-1.jpg')],
        researchTitle: 'Biofilter sabut kelapa untuk air sumur payau',
        researchAbstract: 'Biofilter berbahan sabut kelapa diuji untuk menurunkan kadar garam air sumur.',
        researchReport: F::pdf('laporan-penelitian.pdf'),
    );
}

it('counts whole days from the final with ReportFollowUp', function (): void {
    expect(ReportFollowUp::daysSinceFinal('2026-09-24', '2026-09-27'))->toBe(3)
        ->and(ReportFollowUp::daysSinceFinal(CarbonImmutable::parse('2026-09-24'), CarbonImmutable::parse('2026-10-08 23:00')))->toBe(14)
        ->and(ReportFollowUp::daysSinceFinal('2026-09-24', '2026-09-20'))->toBe(-4)
        ->and(ReportFollowUp::REMINDER_DAYS)->toBe([3, 14])
        ->and(ReportFollowUp::NOT_REPORTED_DAYS)->toBe(14)
        ->and(ReportFollowUp::PROMPT)->toBe('Segera laporkan hasil lombamu.');
});

it('never prints a report deadline, days left or "Terlambat" to the student', function (): void {
    F::today('2026-12-30'); // long after the final of 24 Sep 2026

    $this->actingAs($this->ketuaUser)->get(route('siswa.lomba-saya.show', $this->registration))
        ->assertOk()
        ->assertSee('Segera laporkan hasil lombamu.')->assertSee('Segera laporkan')
        ->assertDontSee('Batas')->assertDontSee('hari lagi')->assertDontSee('Lewat batas')
        ->assertDontSee('Terlambat')->assertDontSee('ditandai terlambat');

    $this->actingAs($this->ketuaUser)->get(route('siswa.lapor', $this->registration))
        ->assertOk()
        ->assertSee('Segera laporkan hasil lombamu.')
        ->assertDontSee('Batas')->assertDontSee('terlambat');
});

it('says "Terlambat" nowhere, also for a report flagged late before D-151', function (): void {
    F::today('2026-12-30');
    $report = Report::factory()->create(['registration_id' => $this->registration->getKey(), 'submitted_by' => $this->ketuaUser->getKey()]);
    $report->forceFill(['late' => true, 'submitted_at' => now()])->save();
    $this->registration->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save();

    $this->actingAs($this->ketuaUser)->get(route('siswa.lomba-saya.show', $this->registration))
        ->assertOk()->assertSee('Laporan terkirim')->assertDontSee('Terlambat');

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $this->registration->fresh()])
        ->assertDontSee('Terlambat');
});

it('tells the student before the final to report after the lomba, without a date', function (): void {
    F::today('2026-09-22');
    $this->registration->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    $this->actingAs($this->ketuaUser)->get(route('siswa.lomba-saya.show', $this->registration))
        ->assertOk()
        ->assertSee('Setelah lomba selesai, laporkan hasil lombamu di sini.')
        ->assertDontSee('paling lambat');
});

it('stores a report long after the final without marking it late', function (): void {
    F::today('2026-12-30');

    $report = app(SubmitReport::class)->handle($this->ketuaUser, $this->registration, noDeadlineReport());

    expect($report->fresh()->late)->toBeFalse()
        ->and(Activity::query()->where('event', 'report.submitted')->sole()->properties->has('late'))->toBeFalse();
    Notification::assertSentTo($this->kesiswaan, ReportSubmitted::class, function (ReportSubmitted $n): bool {
        $body = (string) $n->toArray($this->kesiswaan)['body'];

        return ! str_contains($body, 'batas') && str_ends_with($body, 'Mohon diverifikasi.');
    });
});

it('opens the report window with a request, not a date', function (): void {
    $this->registration->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    app(AwaitReport::class)->handle($this->registration, CarbonImmutable::parse('2026-09-25'));

    Notification::assertSentTo($this->ketuaUser, ReportWindowOpened::class, function (ReportWindowOpened $n): bool {
        return $n->toArray($this->ketuaUser)['body'] === 'Segera laporkan hasil lombamu. Setiap anggota tim mendapat poin penuh setelah diverifikasi.';
    });
});

it('shows the guru the waiting report without a date', function (): void {
    F::today('2026-12-30');

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $this->registration])
        ->assertSee('Laporan hasil belum dikirim')
        ->assertSee('Ketua tim mengirim laporan dari akunnya.')
        ->assertSee('Menunggu laporan')
        ->assertDontSee('Batas lapor')->assertDontSee('Terlambat')->assertDontSee('Lewat batas');
});

it('asks for the report on the student home without a date', function (): void {
    F::today('2026-12-30');

    Livewire::actingAs($this->ketuaUser)->test(Beranda::class)
        ->assertSee('Segera laporkan hasil lombamu.')
        ->assertDontSee('terlambat')->assertDontSee('batas 8 Nov');
});

it('counts "Belum lapor > 14 hari" on the school dashboard', function (): void {
    $kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');

    F::today('2026-10-09'); // final 24 Sep + 15 days
    $component = Livewire::actingAs($kepsek)->test(Dashboard::class);
    expect($component->viewData('kpi')['lewat'])->toBe(1)
        ->and(collect($component->viewData('attention'))->pluck('title')->all())->toContain('1 laporan belum dikirim lebih dari 14 hari setelah lomba');
    $component->assertSee('Belum lapor &gt; 14 hari', false)->assertSee('Lomba selesai lebih dari 14 hari lalu');

    F::today('2026-10-08'); // final + 14 days: not yet
    expect(Livewire::actingAs($kepsek)->test(Dashboard::class)->viewData('kpi')['lewat'])->toBe(0);
});

it('leaves the report deadline off the Arsip akun tab', function (): void {
    $admin = F::staff(RoleName::Admin, 'Operator Sekolah');

    Livewire::actingAs($admin)->test(BatasArsip::class)
        ->assertDontSee('Batas laporan hasil')->assertDontSee('batas lapor')
        ->assertSee('Arsip otomatis')->assertSee('Kapan akun siswa diarsipkan.');
});
