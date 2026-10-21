<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\VerifyReport;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Guru\BinaanDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| FS1 QA round 2 (track QA2), Guru › Detail binaan: the Peserta card of an individu registration carries no team role and
| no team confirmation chip (QA-DC-03), and the tracker dates step 6 "Poin & apresiasi" only once the report was
| verified, not on the day a revision was asked (QA-DC-04). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $this->citra = F::student('Citra Wulandari', 'XI-B');
});

it('shows the sole participant of an individu registration without "Ketua" or "Terkonfirmasi" (QA-DC-03)', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::MenungguLaporan, mentor: $this->guru);

    $page = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration]);

    expect($page->viewData('members'))->toBe([
        ['name' => 'Citra Wulandari', 'meta' => 'XI-B', 'state' => null],
    ]);

    $page->assertSee('Peserta')
        ->assertDontSee('Ketua')
        ->assertDontSee('Terkonfirmasi');
});

it('keeps the roles and the confirmation chips of a team', function (): void {
    $registration = F::registration($this->citra, RegistrationStatus::MenungguLaporan, members: [F::student('Dimas Arya Nugroho', 'XI-B')], mentor: $this->guru);

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $registration])
        ->assertSee('Anggota tim')
        ->assertSee('XI-B · Ketua')
        ->assertSee('XI-B · Anggota')
        ->assertSee('Terkonfirmasi');
});

it('leaves step 6 without a date after a revision request (QA-DC-04)', function (): void {
    $report = K::pendingReport($this->citra, [], ResultType::Juara2, $this->guru);

    app(RequestReportRevision::class)->handle(K::staff(RoleName::Humas, 'Bapak Humas'), $report, 'Foto sertifikat buram');

    $report->refresh();
    expect($report->verified_at)->not->toBeNull()
        ->and($report->registration->status)->toBe(RegistrationStatus::MenungguLaporan);

    $dates = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $report->registration])
        ->viewData('trackerDates');

    expect($dates)->not->toHaveKey(6)
        ->and($dates)->toHaveKey(5);
});

it('dates step 6 on the day the report was verified', function (): void {
    $report = K::pendingReport($this->citra, [], ResultType::Juara2, $this->guru);

    app(VerifyReport::class)->handle(K::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu'), $report, ResultType::Juara2, K::allChecks());

    $report->refresh();
    $dates = Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $report->registration])
        ->viewData('trackerDates');

    expect($dates[6] ?? null)->toBe($report->verified_at->locale('id')->translatedFormat('j M'));
});
