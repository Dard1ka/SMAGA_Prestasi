<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\VerifyReport;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->nadia = F::student();
    $this->mentor = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
});

afterEach(fn () => F::resetToday());

function fs1cSentReport(Registration $registration, Student $leader): Report
{
    $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::Dikirim, 'submitted_by' => $leader->user_id, 'submitted_at' => now()->subDay()])->save();

    return $report->fresh();
}

it('names Humas as the decider on Lomba saya and on the guru binaan page (D-139)', function (): void {
    $wice = F::registration($this->nadia, RegistrationStatus::LaporanDikirim, mentor: $this->mentor);
    app(RequestReportRevision::class)->handle($this->humas, fs1cSentReport($wice, $this->nadia), 'Sertifikat belum terbaca.');

    $this->actingAs($this->nadia->user)->get(route('siswa.lomba-saya.show', $wice))->assertOk()
        ->assertSee('Catatan Humas')->assertDontSee('Catatan Kesiswaan');
    $this->actingAs($this->mentor)->get(route('guru.binaan.show', $wice))->assertOk()
        ->assertSee('Catatan Humas ada di Laporan hasil.')->assertDontSee('Catatan Kesiswaan');

    $opsi = F::registration($this->nadia, RegistrationStatus::LaporanDikirim, F::competition(['name' => 'OPSI 2026']));
    app(VerifyReport::class)->handle($this->humas, fs1cSentReport($opsi, $this->nadia), ResultType::Juara2, array_keys(VerifyReport::CHECKS));

    $this->actingAs($this->nadia->user)->get(route('siswa.lomba-saya.show', $opsi))->assertOk()
        ->assertSee('Diverifikasi Humas')->assertDontSee('Diverifikasi Kesiswaan');
});

it('speaks of "sekolah" for a verified report whose verifier account was deleted (FS1 fix wave)', function (): void {
    $opsi = F::registration($this->nadia, RegistrationStatus::LaporanDikirim, F::competition(['name' => 'OPSI 2026']));
    app(VerifyReport::class)->handle($this->humas, fs1cSentReport($opsi, $this->nadia), ResultType::Juara2, array_keys(VerifyReport::CHECKS));
    $this->humas->delete();

    $this->actingAs($this->nadia->user)->get(route('siswa.lomba-saya.show', $opsi))->assertOk()
        ->assertSee('Diverifikasi sekolah')->assertDontSee('Diverifikasi Humas')->assertDontSee('Diverifikasi Kesiswaan');
});

it('speaks of "sekolah" before anybody decided (D-139)', function (): void {
    $wice = F::registration($this->nadia, RegistrationStatus::LaporanDikirim);
    fs1cSentReport($wice, $this->nadia);

    $this->actingAs($this->nadia->user)->get(route('siswa.lomba-saya.show', $wice))->assertOk()
        ->assertSee('Sekolah akan memverifikasi laporanmu.')->assertDontSee('Kesiswaan akan memverifikasi');
    $this->actingAs($this->nadia->user)->get(route('siswa.portofolio'))->assertOk()
        ->assertSee('Prestasi muncul di sini setelah laporan hasilmu diverifikasi sekolah.');
});
