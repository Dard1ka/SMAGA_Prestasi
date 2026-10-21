<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Providers\NavigationServiceProvider;
use App\Support\Navigation;
use App\Support\VerificationArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| FS1 package C (D-139): Humas verifies reports on its own addresses (humas.verifikasi.*), with its own menu item and
| badge key. Kesiswaan and Admin keep kesiswaan.verifikasi.*; the screen is area-aware. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
});

dataset('verification screens', [
    'humas index' => ['humas.verifikasi.index', [RoleName::Humas]],
    'humas show' => ['humas.verifikasi.show', [RoleName::Humas]],
    'kesiswaan index' => ['kesiswaan.verifikasi.index', [RoleName::Kesiswaan, RoleName::Admin]],
    'kesiswaan show' => ['kesiswaan.verifikasi.show', [RoleName::Kesiswaan, RoleName::Admin]],
]);

it('opens each verification area for its roles only (D-139 role × route matrix)', function (string $route, array $allowed): void {
    $report = F::pendingReport();
    $url = str_ends_with($route, '.show') ? route($route, $report) : route($route);

    foreach (RoleName::staff() as $role) {
        $response = $this->actingAs(F::staff($role, 'Staf '.$role->value))->get($url);
        in_array($role, $allowed, true)
            ? $response->assertOk()->assertSee('Olimpiade Kimia Nasional Universitas Diponegoro 2026')
            : $response->assertForbidden();
    }

    $this->actingAs(F::student('Raka Pradipta', 'XII-B')->user)->get($url)->assertForbidden();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
})->with('verification screens');

it('keeps a Humas user inside humas.* on the screen, after a decision and after a colleague decided (D-139)', function (): void {
    $first = F::pendingReport();
    $second = F::pendingReport(F::student('Raka Pradipta', 'XI-D'));
    $second->forceFill(['submitted_at' => now()->subMinutes(5)])->save(); // newest first (D-164): $second is selected

    $html = $this->actingAs($this->humas)->get(route('humas.verifikasi.show', $first))->assertOk()
        ->assertSee('Antrean publikasi')->getContent();
    expect($html)->not->toMatch('#href="[^"]*/kesiswaan/#');
    $this->actingAs($this->humas)->get('/humas/verifikasi-hasil/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

    $screen = Livewire::actingAs($this->humas)->test(VerifikasiHasil::class)
        ->assertSet('area', VerificationArea::HUMAS)
        ->assertSet('selectedId', $second->getKey())
        ->call('select', $first->getKey())
        ->assertDispatched('sth-address', url: route('humas.verifikasi.show', $first))
        ->set('checks', F::allChecks())
        ->call('verify')
        ->assertHasNoErrors()
        ->assertSet('selectedId', $second->getKey())
        ->assertDispatched('sth-address', url: route('humas.verifikasi.show', $second));

    expect($first->fresh()->verified_by)->toBe($this->humas->getKey());

    // First decision wins (decidedElsewhere): Kesiswaan asked for a revision meanwhile; nothing is left in the queue.
    app(RequestReportRevision::class)->handle(F::staff(RoleName::Kesiswaan), $second->fresh(), 'Sertifikat buram, kirim ulang.');
    $screen->set('note', 'Sertifikat buram.')->call('requestRevision')
        ->assertDispatched('sth-toast', message: 'Laporan ini sudah diputuskan. Antrean sudah diperbarui.', tone: 'warning')
        ->assertSet('selectedId', null)
        ->assertDispatched('sth-address', url: route('humas.verifikasi.index'));

    Livewire::actingAs(F::staff(RoleName::Kesiswaan, 'Pak Bayu Nugraha'))->test(VerifikasiHasil::class)->assertSet('area', VerificationArea::KESISWAAN);
    expect(fn () => Livewire::actingAs($this->humas)->test(VerifikasiHasil::class)->set('area', VerificationArea::KESISWAAN))->toThrow(Exception::class);
});

it('keeps the decision away from a Humas who is the guru pembimbing of the report (§3c-10)', function (): void {
    $humasGuru = F::staff(RoleName::Humas, 'Pak Hendra Kusuma');
    $humasGuru->assignRole(RoleName::Guru->value);
    $report = F::pendingReport(mentor: $humasGuru->fresh());

    Livewire::actingAs($humasGuru->fresh())->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->assertSee('Laporan binaan sendiri diverifikasi petugas lain.')
        ->assertDontSeeHtml('wire:click="verify"')
        ->assertDontSeeHtml('wire:click="requestRevision"')
        ->set('note', 'Sertifikat buram.')
        ->call('requestRevision')
        ->assertForbidden();

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertNothingSent();
});

it('counts the Humas queue in the menu, on other pages, in the live counts and after a decision (D-100, D-127)', function (): void {
    F::pendingReport();
    F::pendingReport(F::student('Raka Pradipta', 'XI-D'));

    expect(Navigation::badgeKeys('humas'))->toBe(['humas.publikasi', 'humas.verifikasi', 'humas.jadwal'])
        ->and(NavigationServiceProvider::badges($this->humas, 'humas'))->toMatchArray(['humas.verifikasi' => 2])
        ->and(NavigationServiceProvider::liveCounts($this->humas))->toMatchArray(['humas.verifikasi' => 2])
        ->and(NavigationServiceProvider::badges(F::staff(RoleName::TataUsaha, 'Bu Sari'), 'humas'))->toBe([]);

    $html = $this->actingAs($this->humas)->get(route('notifikasi.index'))->assertOk()->getContent();
    expect($html)->toMatch('/data-sth-badge="humas\.verifikasi"[^>]*>\s*2\s*</');

    Livewire::actingAs($this->humas)->test(VerifikasiHasil::class)
        ->set('checks', F::allChecks())->call('verify')
        ->assertDispatched('sth-badges', badges: ['humas.verifikasi' => 1]);
});

it('asks Humas for 2FA before the verification screen when the rule is on (SECURITY §3a)', function (): void {
    config(['fortify.sth.require_staff_2fa' => true]);

    $this->actingAs($this->humas)->get(route('humas.verifikasi.index'))->assertRedirect(route('two-factor.setup'));
    $this->humas->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($this->humas->fresh())->get(route('humas.verifikasi.index'))->assertOk();
});
