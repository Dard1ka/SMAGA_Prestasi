<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kepsek\Persetujuan;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * QA10 (D-109): "Penolakan terkirim ke siswa." although RejectRegistration only tells the members who can log in. The
 * toast now follows RejectRegistration::studentsTold(). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
});

/** Disables the accounts of the registration's members with these names. */
function qa10RegKepsekDisable(Registration $registration, array $names): void
{
    User::query()
        ->whereIn('name', $names)
        ->whereHas('student.memberships', fn ($m) => $m->where('registration_id', $registration->getKey()))
        ->update(['status' => UserStatus::Disabled]);
}

it('claims a notice in the reject toast only for the students who can log in (QA10)', function (array $disabled, string $toast): void {
    $registration = F::registration(RegistrationStatus::MenungguKepsek, ['Nadia Putri Anindya', 'Hana Safira']);
    qa10RegKepsekDisable($registration, $disabled);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('openReject', $registration->getKey())
        ->set('rejectNote', 'Jadwal bentrok dengan penilaian tengah semester.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: $toast, tone: 'success');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Ditolak);
})->with([
    'everyone told' => [[], 'Penolakan terkirim ke siswa.'],
    'one told' => [['Hana Safira'], 'Penolakan terkirim ke siswa yang akunnya aktif.'],
    'nobody told' => [['Nadia Putri Anindya', 'Hana Safira'], 'Pendaftaran ditolak. Akun siswa tidak aktif, jadi tidak ada notifikasi.'],
]);

it('says on the decided row that the students were told only when they were (QA10)', function (array $disabled, string $see, string $dontSee): void {
    $registration = F::registration(RegistrationStatus::MenungguKepsek, ['Nadia Putri Anindya', 'Hana Safira']);
    qa10RegKepsekDisable($registration, $disabled);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('openReject', $registration->getKey())
        ->set('rejectNote', 'Jadwal bentrok dengan penilaian tengah semester.')
        ->call('reject')
        ->assertSee($see)
        ->assertDontSee($dontSee);
})->with([
    'told' => [[], 'Ditolak · siswa diberi tahu', 'Ditolak · tanpa notifikasi'],
    'nobody told' => [['Nadia Putri Anindya', 'Hana Safira'], 'Ditolak · tanpa notifikasi', 'Ditolak · siswa diberi tahu'],
]);
