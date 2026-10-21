<?php

declare(strict_types=1);

use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Beranda;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 * UX2 (the lead, 2026-10-20): Verifikasi hasil (Kesiswaan and Humas, one screen) and the Kesiswaan home list the newest
 * report first, and the newest one opens by itself. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

function antreanReport(string $name, string $sentAt): Report
{
    $report = F::pendingReport(F::student($name, 'XI-D'), [], ResultType::Juara3, null, F::competition(['name' => 'Lomba '.$name]));
    $report->forceFill(['submitted_at' => $sentAt])->save();

    return $report->fresh();
}

it('lists Verifikasi hasil newest first and opens the newest report', function (): void {
    $old = antreanReport('Nadia Putri Anindya', '2026-10-10 08:00:00');
    $new = antreanReport('Raka Pradipta', '2026-10-18 08:00:00');
    $middle = antreanReport('Salsa Maharani', '2026-10-14 08:00:00');

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $new->getKey())
        ->assertSeeHtmlInOrder([
            'wire:key="q-'.$new->getKey().'"',
            'wire:key="q-'.$middle->getKey().'"',
            'wire:key="q-'.$old->getKey().'"',
        ]);

    Livewire::actingAs(F::staff(RoleName::Humas, 'Mas Galih Prasetyo'))->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $new->getKey());
});

it('shows the newest reports first on the Kesiswaan home', function (): void {
    antreanReport('Nadia Putri Anindya', '2026-10-10 08:00:00');
    antreanReport('Raka Pradipta', '2026-10-18 08:00:00');

    Livewire::actingAs($this->kesiswaan)->test(Beranda::class)
        ->assertSeeInOrder(['Lomba Raka Pradipta', 'Lomba Nadia Putri Anindya']);
});
