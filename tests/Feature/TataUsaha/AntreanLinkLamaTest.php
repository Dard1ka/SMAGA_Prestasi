<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Actions\Registrations\CancelApprovedRegistration;
use App\Enums\LetterType;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 | SV1 (browser verification for the school's server, 2026-10-30): Tata Usaha opened an old notice "Surat tugas perlu
 | dibuat" of a registration that had left the letter queue meanwhile (D-155: cancelled from it, its unsent letters
 | revoked) and got a full 404 page. Such a link now opens the queue with a warning toast (stale-screen rule); an unknown
 | registration and one the account may not see keep their 404, another role its 403. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    F::signature();
});

/** An approved team whose student letter TU made, then removed from the letter queue with a reason (D-155). */
function oldLinkCancelledRegistration(User $tu): Registration
{
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], F::mentor());

    app(IssueLetters::class)->handle($tu, $registration, LetterType::Siswa, new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::today(),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    ));

    app(CancelApprovedRegistration::class)->handle($tu, $registration->fresh(), 'Lomba diundur penyelenggara ke tahun depan.');

    return $registration->fresh();
}

it('opens the queue with a warning for the old link of a registration removed from it', function (): void {
    $cancelled = oldLinkCancelledRegistration($this->tu);
    F::registration(RegistrationStatus::Disetujui, ['Nadia Putri Anindya'], null, ['name' => 'Olimpiade Sains Kota 2026']);

    // Not in the queue any more, and its letter was revoked with the cancel.
    expect($cancelled->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and($cancelled->letters()->exists())->toBeFalse();

    $this->actingAs($this->tu)->get(route('tu.surat.show', $cancelled))
        ->assertRedirect(route('tu.beranda'))
        ->assertSessionHas('toast', ['tone' => 'warning', 'message' => SuratTugas::MSG_CANCELLED_ELSEWHERE]);

    // The queue page shows the toast and opens the registration that is still in the queue.
    $this->get(route('tu.beranda'))
        ->assertOk()
        ->assertSee(SuratTugas::MSG_CANCELLED_ELSEWHERE)
        ->assertSee('Olimpiade Sains Kota 2026');
});

it('redirects the Livewire page itself, also for a registration that never reached the queue', function (): void {
    $waiting = F::registration(RegistrationStatus::MenungguKepsek, ['Nadia Putri Anindya']);

    Livewire::actingAs($this->tu)
        ->test(SuratTugas::class, ['registration' => $waiting])
        ->assertRedirect(route('tu.beranda'));
});

it('still opens a registration in the queue and one whose letters exist', function (): void {
    $queued = F::registration(RegistrationStatus::Disetujui, ['Nadia Putri Anindya']);

    $this->actingAs($this->tu)->get(route('tu.surat.show', $queued))->assertOk()->assertSee('NADIA PUTRI ANINDYA');

    // Sent letters: the registration left the queue (surat_terbit), its letters still open the page (done state).
    $sent = F::registration(RegistrationStatus::Disetujui, ['Hana Safira']);
    app(IssueLetters::class)->handle($this->tu, $sent, LetterType::Siswa, new LetterDraft(
        number: '400.3.8/314/MRD/X/2026',
        issuedOn: CarbonImmutable::today(),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    ));
    app(SendLetters::class)->handle($this->tu, $sent->fresh());

    expect($sent->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
    $this->get(route('tu.surat.show', $sent))->assertOk()->assertSee('HANA SAFIRA');
});

it('keeps the 404 for an unknown registration and for one the account may not see', function (): void {
    $this->actingAs($this->tu)->get('/tu/surat/'.strtolower((string) Str::ulid()))->assertNotFound();

    // An account that may open the letter queue but sees no registration (no approval, letter or registration
    // permission): RegistrationPolicy::view says no, so the old link stays a 404.
    Role::findOrCreate('surat-saja', 'web')->syncPermissions([Permission::LetterGenerate->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $clerk = User::factory()->withRole('surat-saja')->create()->fresh();
    $cancelled = oldLinkCancelledRegistration($this->tu);

    Livewire::actingAs($clerk)
        ->test(SuratTugas::class, ['registration' => $cancelled])
        ->assertNotFound();
});

it('keeps other roles out of the old link as before', function (): void {
    $cancelled = oldLinkCancelledRegistration($this->tu);

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Admin] as $role) {
        $this->actingAs(F::staff($role))->get(route('tu.surat.show', $cancelled))->assertForbidden();
    }
});
