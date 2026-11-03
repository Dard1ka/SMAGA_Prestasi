<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Persetujuan;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * QA9: a row of the open Persetujuan screen that was cancelled by the ketua or decided by the Kepala Sekolah on another
 * device used to answer a full-screen 404 (findVisible() filtered on menunggu_kepsek). The screen now says what happened
 * and refreshes the list; a registration it never showed is still a 404.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
});

function qa9PersetujuanCancel(Registration $registration): void
{
    $registration->forceFill(['status' => RegistrationStatus::Dibatalkan, 'cancelled_at' => now()])->save();
}

it('tells the Kepala Sekolah that the ketua cancelled a row instead of a 404 (QA9)', function (string $method): void {
    $registration = F::registration(names: ['Nadia Putri Anindya']);
    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)->assertSee('Nadia Putri Anindya');

    qa9PersetujuanCancel($registration);

    $component->call($method, $registration->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Pendaftaran ini sudah dibatalkan siswa. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('rejecting', null)
        ->assertDontSee('Nadia Putri Anindya');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);
})->with(['approve', 'openReject']);

it('tells the Kepala Sekolah that a row was decided on another device while the reject form was open (QA9)', function (): void {
    $registration = F::registration(names: ['Hana Safira']);
    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('openReject', $registration->getKey())
        ->set('rejectNote', 'Jadwal bentrok dengan penilaian tengah semester.');

    app(ApproveRegistration::class)->handle($this->kepsek, $registration);

    $component->call('reject')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Pendaftaran ini sudah diputuskan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('rejecting', null)
        ->assertSet('rejectNote', '');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($registration->fresh()->principal_note)->toBeNull();
});

it('counts ticked rows that were cancelled meanwhile in the bulk approve (QA9)', function (): void {
    $a = F::registration(names: ['Nadia Putri Anindya']);
    $b = F::registration(names: ['Hana Safira']);
    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->set('selected', [$a->getKey(), $b->getKey()]);

    qa9PersetujuanCancel($b);

    $component->call('approveSelected')
        ->assertDispatched('sth-toast', message: '1 pendaftaran disetujui. 1 sudah diputuskan atau dibatalkan sebelumnya.', tone: 'warning')
        ->assertSet('selected', []);

    expect($a->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($b->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);
});

it('does not ask to pick rows when every ticked row was decided or cancelled meanwhile (QA9)', function (): void {
    $a = F::registration(names: ['Nadia Putri Anindya']);
    $b = F::registration(names: ['Hana Safira']);
    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->set('selected', [$a->getKey(), $b->getKey()]);

    qa9PersetujuanCancel($a);
    app(ApproveRegistration::class)->handle($this->kepsek, $b);

    $component->call('approveSelected')
        ->assertNotDispatched('sth-toast', message: 'Pilih pendaftaran dulu.', tone: 'warning')
        ->assertDispatched('sth-toast', message: '2 pendaftaran sudah diputuskan atau dibatalkan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('selected', []);
});

it('still answers 404 for a decided row the screen never showed (QA9)', function (): void {
    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('approve', F::registration(RegistrationStatus::Dibatalkan)->getKey())
        ->assertNotFound();

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('openReject', F::registration(RegistrationStatus::Disetujui)->getKey())
        ->assertNotFound();
});
