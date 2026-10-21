<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * The lead, 2026-10-19: the TU queues "Antrean surat" and "Persetujuan lomba" list the newest first. Persetujuan lomba is
 * the one screen of the Kepala Sekolah and Tata Usaha (D-150), so the order holds for both. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    F::signature();
});

/** An approved registration in the TU queue, approved at the given moment. */
function antreanApproved(User $kepsek, string $name, string $decidedAt): Registration
{
    $registration = F::registration(RegistrationStatus::Disetujui, [$name], null, ['name' => 'Lomba '.$name]);
    $registration->forceFill(['principal_decision_by' => $kepsek->getKey(), 'principal_decided_at' => $decidedAt])->save();

    return $registration->fresh();
}

it('lists the letter queue newest approval first and opens the newest one', function (): void {
    $old = antreanApproved($this->kepsek, 'Nadia Putri Anindya', '2026-10-10 08:00:00');
    $new = antreanApproved($this->kepsek, 'Raka Pradipta', '2026-10-18 09:00:00');
    $middle = antreanApproved($this->kepsek, 'Salsa Maharani', '2026-10-14 10:00:00');

    $this->actingAs($this->tu);

    Livewire::test(SuratTugas::class)
        ->assertSet('registrationId', $new->getKey())
        ->assertSeeHtmlInOrder([
            'wire:key="q-'.$new->getKey().'"',
            'wire:key="q-'.$middle->getKey().'"',
            'wire:key="q-'.$old->getKey().'"',
        ]);
});

it('lists the approval queue newest registration first, for Tata Usaha and the Kepala Sekolah', function (): void {
    $this->travelTo('2026-10-10 08:00:00');
    $old = F::registration(RegistrationStatus::MenungguKepsek, ['Nadia Putri Anindya'], null, ['name' => 'Lomba A']);
    $this->travelTo('2026-10-18 08:00:00');
    $new = F::registration(RegistrationStatus::MenungguKepsek, ['Raka Pradipta'], null, ['name' => 'Lomba B']);
    $this->travelTo('2026-10-14 08:00:00');
    $middle = F::registration(RegistrationStatus::MenungguKepsek, ['Salsa Maharani'], null, ['name' => 'Lomba C']);
    $this->travelTo('2026-10-19 08:00:00');

    $order = [
        'wire:key="reg-'.$new->getKey().'"',
        'wire:key="reg-'.$middle->getKey().'"',
        'wire:key="reg-'.$old->getKey().'"',
    ];

    foreach ([$this->tu, $this->kepsek] as $approver) {
        $this->actingAs($approver);
        Livewire::test(Persetujuan::class)->assertSeeHtmlInOrder($order);
    }
});
