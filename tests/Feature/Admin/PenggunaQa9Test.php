<?php

declare(strict_types=1);

use App\Actions\Accounts\DisableAccount;
use App\Actions\Users\ApplyPrincipalReplacement;
use App\Actions\Users\CancelPrincipalReplacement;
use App\Actions\Users\RequestPrincipalReplacement;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09): Admin › Pengguna & peran. Fictional data only; no password is printed.
 */

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

// Item 3: no reset for an account that cannot log in ------------------------------------------------------------

it('offers no reset for a staff account that cannot log in and says why (QA9)', function (): void {
    $active = A::staff(RoleName::Guru, ['name' => 'Ibu Retno Wulandari']);
    $disabled = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma', 'status' => UserStatus::Disabled]);
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->assertSeeHtml("openStaff({$active->id}, 'staf-reset')")
        ->assertDontSeeHtml("openStaff({$disabled->id}, 'staf-reset')")
        ->assertSee('Akun nonaktif tidak bisa login, jadi tidak bisa direset.')
        ->call('openStaff', $disabled->id, 'staf-reset')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.', tone: 'warning');
});

it('does not reset a staff account that was disabled while the dialog was open (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $hash = $guru->password;
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $screen = Livewire::test(Pengguna::class)
        ->call('openStaff', $guru->id, 'staf-reset')
        ->set('tempPassword', 'Sementara-2026!y')
        ->set('tempPassword_confirmation', 'Sementara-2026!y')
        ->set('reason', 'Lupa kata sandi.');

    app(DisableAccount::class)->handle($this->admin, $guru, 'Pindah tugas.');

    $screen->call('resetStaff')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'staf-reset');

    expect($guru->fresh()->password)->toBe($hash)
        ->and(Activity::query()->where('event', 'account.staff_reset')->exists())->toBeFalse();
});

it('offers no reset for a student account that cannot log in and says why (QA9)', function (UserStatus $status, string $message): void {
    H::activeStudent(['status' => $status]);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->set('studentQuery', H::NIS)
        ->call('findStudent')
        ->assertDontSeeHtml("openStudent('siswa-reset')")
        ->assertSee($message)
        ->call('openStudent', 'siswa-reset')
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: $message, tone: 'warning')
        ->call('resetStudent')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('issuedPassword', null);

    expect(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();
})->with([
    'nonaktif' => [UserStatus::Disabled, 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.'],
    'alumni' => [UserStatus::Archived, 'Akun alumni tidak bisa login, jadi tidak bisa direset. Pulihkan dulu di Arsip otomatis bila perlu.'],
]);

it('still offers the reset for a student who can log in (QA9)', function (): void {
    H::activeStudent();
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->set('studentQuery', H::NIS)
        ->call('findStudent')
        ->assertSeeHtml("openStudent('siswa-reset')")
        ->assertDontSee('tidak bisa direset');
});

// Item 5: Ganti Kepala Sekolah decided elsewhere ---------------------------------------------------------------

it('says so when another Admin cancelled the Kepala Sekolah replacement meanwhile (QA9)', function (): void {
    A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $new = A::staff(RoleName::Guru, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);
    $second = A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, $new, 'SK Kepala Sekolah baru.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $screen = Livewire::test(Pengguna::class)
        ->call('openCancel', $replacement->id)
        ->set('cancelReason', 'Salah pilih akun.');

    app(CancelPrincipalReplacement::class)->handle($second, $replacement, 'Dibatalkan Admin lain.');

    $screen->call('cancelReplacement')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'batal-kepsek')
        ->assertSet('replacementId', null)
        ->assertDontSee('Penggantian Kepala Sekolah dijadwalkan');

    $screen->call('openCancel', $replacement->id)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', tone: 'warning');

    expect($replacement->fresh()->cancel_reason)->toBe('Dibatalkan Admin lain.');
});

it('says so when the Kepala Sekolah replacement took effect before the cancel (QA9)', function (): void {
    A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $new = A::staff(RoleName::Guru, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);
    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, $new, 'SK Kepala Sekolah baru.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $screen = Livewire::test(Pengguna::class)->call('openCancel', $replacement->id)->set('cancelReason', 'Tidak jadi.');

    $this->travel(25)->hours();
    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(1);

    $screen->call('cancelReplacement')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'batal-kepsek');

    expect($replacement->fresh()->applied_at)->not->toBeNull()
        ->and($replacement->fresh()->cancelled_at)->toBeNull();
});

// Item 7: "Ubah peran" with the role the account already holds -------------------------------------------------

it('says the role is already held instead of claiming a change (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->call('openStaff', $guru->id, 'staf-peran')
        ->assertSet('role', RoleName::Guru->value)
        ->set('reason', 'Cek peran.')
        ->call('changeRole')
        ->assertHasErrors(['role'])
        ->assertSee('Akun ini sudah memegang peran itu.')
        ->assertNotDispatched('sth-toast')
        ->assertNoRedirect();

    expect(Activity::query()->where('event', 'account.role_changed')->exists())->toBeFalse();
});

// Item 11: the temporary password leaves the page however the dialog closes ------------------------------------

it('forgets the temporary password whenever its dialog closes, with one toast (QA9)', function (): void {
    H::activeStudent();
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $screen = Livewire::test(Pengguna::class)
        ->assertSeeHtml('data-sth-on-close="forgetIssuedPassword"')
        ->set('studentQuery', H::NIS)
        ->call('findStudent')
        ->call('openStudent', 'siswa-reset')
        ->set('studentReason', 'Siswa datang ke TU.')
        ->call('resetStudent')
        ->assertSet('issuedPassword', fn (?string $pw): bool => is_string($pw) && $pw !== '');

    // "Sudah saya berikan", then the dialog's own close event calls the same method once more.
    $screen->call('forgetIssuedPassword')
        ->assertSet('issuedPassword', null)
        ->assertDispatched('sth-toast', tone: 'success');

    $screen->call('forgetIssuedPassword')
        ->assertSet('issuedPassword', null)
        ->assertNotDispatched('sth-toast');
});
