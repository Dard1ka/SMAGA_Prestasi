<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * SV1 (browser check of 2026-10-30): in the dialog "Hapus dari antrean surat?" the field hint said "Minimal 5 karakter.
 * Dikirim ke siswa dan guru pembimbing." while the description above it (and the toast) also names the approver who
 * approved it (D-155), or says that no account is told. Who gets the reason is said once, by the description
 * (CancelApprovedRegistration::audience(), D-109); the hint promises no recipient.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->mentor = F::mentor();
    F::signature();

    $this->queued = function (?int $approverId = null): Registration {
        $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $this->mentor);
        $registration->forceFill(['principal_decision_by' => $approverId ?? $this->kepsek->getKey(), 'principal_decided_at' => now()])->save();

        return $registration->fresh();
    };
});

it('names the receivers of the reason in the description only, the approver included (SV1, D-155)', function (): void {
    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => ($this->queued)()])
        ->call('askCancel')
        ->assertDispatched('sth-modal-open', id: SuratTugas::CANCEL_DIALOG)
        ->assertSee('2 siswa, guru pembimbing, dan Drs. Bambang Hartono, M.Pd. (Kepala Sekolah) yang menyetujuinya diberi tahu beserta alasannya.')
        ->assertSee('Minimal 5 karakter. Tampil di halaman Lomba saya siswa.')
        ->assertDontSee('Dikirim ke siswa dan guru pembimbing');
});

it('keeps the hint free of a promise when Tata Usaha approved it itself or nobody can be told', function (): void {
    // Approved by this Tata Usaha account: the approver is not told (never the actor).
    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => ($this->queued)($this->tu->getKey())])
        ->call('askCancel')
        ->assertSee('2 siswa dan guru pembimbing diberi tahu beserta alasannya.')
        ->assertSee('Minimal 5 karakter. Tampil di halaman Lomba saya siswa.')
        ->assertDontSee('Dikirim ke');

    // Nobody who can log in: the description says so, and the hint still claims no notice.
    $registration = ($this->queued)();
    foreach ($registration->members()->with('student.user')->get() as $member) {
        $member->student->user->forceFill(['status' => UserStatus::Disabled])->save();
    }
    $this->mentor->forceFill(['status' => UserStatus::Disabled])->save();
    $this->kepsek->forceFill(['status' => UserStatus::Disabled])->save();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('askCancel')
        ->assertSee('Tidak ada akun aktif yang diberi tahu.')
        ->assertSee('Minimal 5 karakter. Tampil di halaman Lomba saya siswa.')
        ->assertDontSee('Dikirim ke');
});
