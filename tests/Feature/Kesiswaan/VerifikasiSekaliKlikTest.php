<?php

declare(strict_types=1);

use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| FS1 fix wave, the QA-04 pattern on Verifikasi hasil: "Verifikasi dan beri poin" was disabled until the round trip of
| the fourth tick came back, so a click right after the tick did nothing. The button is never disabled by the ticks
| now: the click carries the last tick, and VerifyReport answers missing checks under the fieldset. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->nadia = F::student('Nadia Putri Anindya', 'XI-D');
});

it('keeps "Verifikasi dan beri poin" clickable before the four ticks and answers missing ticks under the checks', function (): void {
    $report = F::pendingReport($this->nadia);
    $panel = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class, ['report' => $report]);

    expect(preg_match('/<button[^>]*wire:click="verify"[^>]*>/', $panel->html(), $match))->toBe(1)
        ->and($match[0])->not->toMatch('/\sdisabled[\s=>]/');

    $three = F::allChecks();
    unset($three['peringkat_sesuai']);
    $panel->set('checks', $three)->call('verify')
        ->assertHasErrors(['checks' => 'Lengkapi 4 pemeriksaan bukti dulu.'])
        ->assertSee('Lengkapi 4 pemeriksaan bukti dulu.');

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);
});

it('verifies with one click that carries the fourth tick', function (): void {
    $report = F::pendingReport($this->nadia);
    $three = F::allChecks();
    unset($three['peringkat_sesuai']);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class, ['report' => $report])
        ->set('checks', $three)
        ->update(calls: [['method' => 'verify', 'params' => [], 'path' => '']], updates: ['checks.peringkat_sesuai' => true])
        ->assertHasNoErrors();

    expect($report->fresh()->status)->toBe(ReportStatus::Diverifikasi);
});
