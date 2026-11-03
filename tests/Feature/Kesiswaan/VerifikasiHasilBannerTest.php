<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| SV1 browser check (2026-10-30): after a decision Verifikasi hasil (Kesiswaan and Humas, one component) selects the next
| report, while the result banner ("Revisi diminta …", "Terverifikasi · N poin") stays above it without saying which
| report it belongs to. The banner now names the lomba and the ketua of the decided report. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    // Newest report first (D-164): the team report opens first, the older solo report is next in the queue.
    $this->solo = F::pendingReport(F::student('Nadia Putri Anindya', 'XI-D'), competition: F::competition(['name' => 'Olimpiade Kimia Fiktif 2026']));
    $this->team = F::pendingReport(
        F::student('Raka Pradipta', 'XII-B'),
        [F::student('Dimas Arya Nugroho', 'XII-B')],
        competition: F::competition(['name' => 'Lomba Debat Fiktif 2026']),
    );
    $this->solo->forceFill(['submitted_at' => now()->subDays(3)])->save();
    $this->team->forceFill(['submitted_at' => now()->subDay()])->save();
});

it('names the decided report in the banner while the panel shows the next one (SV1)', function (RoleName $role): void {
    $screen = Livewire::actingAs(F::staff($role, 'Staf '.$role->value))->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $this->team->getKey())
        ->set('note', 'Foto sertifikat buram, kirim ulang.')
        ->call('requestRevision')
        ->assertHasNoErrors()
        // The panel moved on to the solo report; the banner still speaks of the team report.
        ->assertSet('selectedId', $this->solo->getKey())
        ->assertSet('decision.title', 'Revisi diminta')
        ->assertSet('decision.report', 'Laporan Lomba Debat Fiktif 2026 dari tim Raka Pradipta.')
        ->assertSee('Laporan Lomba Debat Fiktif 2026 dari tim Raka Pradipta.');

    // The last report of the queue: the panel keeps it (now decided), and the banner names it.
    $screen->set('checks', F::allChecks())
        ->call('verify')
        ->assertHasNoErrors()
        ->assertSet('selectedId', $this->solo->getKey())
        ->assertSet('decision.title', 'Terverifikasi · 33,6 poin')
        ->assertSet('decision.report', 'Laporan Olimpiade Kimia Fiktif 2026 dari Nadia Putri Anindya.')
        ->assertSee('Laporan Olimpiade Kimia Fiktif 2026 dari Nadia Putri Anindya.')
        ->assertDontSee('Laporan Lomba Debat Fiktif 2026 dari tim Raka Pradipta.');
})->with(['Kesiswaan' => RoleName::Kesiswaan, 'Humas' => RoleName::Humas]);

it('drops the banner when another report is opened by hand', function (): void {
    Livewire::actingAs(F::staff(RoleName::Kesiswaan))->test(VerifikasiHasil::class)
        ->set('note', 'Foto sertifikat buram, kirim ulang.')
        ->call('requestRevision')
        ->assertSet('decision.report', 'Laporan Lomba Debat Fiktif 2026 dari tim Raka Pradipta.')
        ->call('select', $this->solo->getKey())
        ->assertSet('decision', null)
        ->assertDontSee('dari tim Raka Pradipta.');
});
