<?php

declare(strict_types=1);

use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Roles\ApproveRoleChange;
use App\Actions\Roles\CancelRoleChange;
use App\Actions\Roles\PrivilegedRoles;
use App\Actions\Roles\RequestRoleChange;
use App\Actions\Users\ChangeStaffRole;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Admin\RoleChangeRequests;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\RoleChangeNotice;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| "Ubah peran" applies at once for every role since 21 Okt 2026 (the lead: no second Admin, no waiting; replaces the
| waiting rule of backlog #13 / D-46). Requests made before that day (A::legacyRoleRequest) are still approved,
| cancelled and applied after their 24 h.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->admin = A::admin();
});

function b5SecondAdmin(): User
{
    return A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
}

// Every change applies at once ------------------------------------------------------------------------------------

it('applies a change to a privileged role at once: sessions revoked, audited, notified, no request row', function (RoleName $role): void {
    b5SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    H::fakeSessions($guru, 2);

    app(RequestRoleChange::class)->handle($this->admin, $guru, $role, 'Ditugaskan ulang.');

    expect($guru->fresh()->getRoleNames()->all())->toBe([$role->value])
        ->and(H::sessionCount($guru))->toBe(0)
        ->and(RoleChangeRequest::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'role.change_requested')->exists())->toBeFalse();

    $log = Activity::query()->where('event', 'account.role_changed')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['from'])->toBe([RoleName::Guru->value])
        ->and($log->properties['to'])->toBe([$role->value])
        ->and($log->properties['reason'])->toBe('Ditugaskan ulang.');

    Notification::assertSentTo([$this->admin, $guru], StaffAccountChanged::class, fn (StaffAccountChanged $n): bool => $n->kind() === 'staff.role_changed');
    Notification::assertNotSentTo($guru, RoleChangeNotice::class);
})->with([RoleName::Admin, RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::Humas]);

it('applies at once also when the requester is the only Admin (no 24 h delay)', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $guru = A::staff(RoleName::Guru);

    app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Kesiswaan baru.');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
    Notification::assertNotSentTo($kepsek, RoleChangeNotice::class);
});

it('lets the promoted account open its new area after the next login', function (): void {
    $guru = A::staff(RoleName::Guru);

    app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Membantu TU.');

    $this->actingAs($guru->fresh())->get(route('tu.beranda'))->assertOk();
});

it('applies a change to a non-privileged role and a downgrade at once', function (): void {
    $humas = A::staff(RoleName::Humas);
    $kesiswaan = A::staff(RoleName::Kesiswaan);

    app(RequestRoleChange::class)->handle($this->admin, $humas, RoleName::Guru, 'Kembali mengajar.');
    app(ChangeStaffRole::class)->handle($this->admin, $kesiswaan, RoleName::Guru, 'Kembali mengajar.');

    expect($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and($kesiswaan->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
});

it('knows which roles are privileged', function (): void {
    expect(PrivilegedRoles::isPrivileged(RoleName::Admin))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::KepalaSekolah))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::TataUsaha))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Kesiswaan))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Humas))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Guru))->toBeFalse();
});

// The rules that stay -------------------------------------------------------------------------------------------

it('keeps the 3-Admin limit', function (): void {
    b5SecondAdmin();
    app(RequestRoleChange::class)->handle($this->admin, A::staff(RoleName::Guru), RoleName::Admin, 'Admin ketiga.');

    $humas = A::staff(RoleName::Humas);

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $humas, RoleName::Admin, 'Admin keempat.'))
        ->toThrow(ValidationException::class, 'Sudah ada 3 akun Admin.')
        ->and($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Humas->value]);
});

it('still counts a pending Admin request made before 21 Okt 2026 towards the 3-Admin limit', function (): void {
    b5SecondAdmin();
    A::legacyRoleRequest($this->admin, A::staff(RoleName::Guru), RoleName::Admin, 'Admin ketiga (lama).');

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, A::staff(RoleName::Humas), RoleName::Admin, 'Admin keempat.'))
        ->toThrow(ValidationException::class, 'termasuk yang menunggu');
});

it('never lets an Admin change their own role, so the last Admin keeps it', function (): void {
    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $this->admin, RoleName::Guru, 'Mundur.'))
        ->toThrow(AuthorizationException::class)
        ->and($this->admin->fresh()->getRoleNames()->all())->toBe([RoleName::Admin->value]);
});

it('never changes a student or the Kepala Sekolah here, and never gives those roles', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $guru = A::staff(RoleName::Guru);

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $kepsek, RoleName::Guru, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::KepalaSekolah, 'x'))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Siswa, 'x'))->toThrow(ValidationException::class)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('needs a reason', function (): void {
    $guru = A::staff(RoleName::Guru);

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, '   '))->toThrow(ValidationException::class)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('refuses a direct change while an old request of the same account still waits', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::Kesiswaan);

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Langsung.'))
        ->toThrow(ValidationException::class, ChangeStaffRole::PENDING_REQUEST);

    app(CancelRoleChange::class)->handle($this->admin, $request, 'Diganti perubahan langsung.');
    app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Langsung.');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::TataUsaha->value]);
});

it('denies role changes to staff without user.manage', function (RoleName $role): void {
    $actor = A::staff($role);
    $guru = A::staff(RoleName::Guru);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::Kesiswaan);

    expect(fn () => app(RequestRoleChange::class)->handle($actor, $guru, RoleName::Kesiswaan, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($actor, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelRoleChange::class)->handle($actor, $request, 'x'))->toThrow(AuthorizationException::class)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);

// Requests made before 21 Okt 2026 --------------------------------------------------------------------------------

it('lets only another Admin approve an old request: not the requester, not the promoted account', function (): void {
    $second = b5SecondAdmin();
    $tu = A::staff(RoleName::TataUsaha);
    $request = A::legacyRoleRequest($this->admin, $second, RoleName::TataUsaha, 'Pindah ke TU.');

    expect(fn () => app(ApproveRoleChange::class)->handle($this->admin, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($second, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($tu, $request))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->isPending())->toBeTrue();
});

it('applies an old request when a second Admin approves: sessions revoked, audited, notified', function (): void {
    $second = b5SecondAdmin();
    $guru = A::staff(RoleName::Guru);
    H::fakeSessions($guru, 2);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::TataUsaha, 'Membantu TU.');

    expect(app(ApproveRoleChange::class)->handle($second, $request))->toBeTrue();

    $request->refresh();
    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::TataUsaha->value])
        ->and(H::sessionCount($guru))->toBe(0)
        ->and($request->approved_by)->toBe($second->id)
        ->and($request->applied_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'role.change_approved')->sole()->causer_id)->toBe($second->id);

    $applied = Activity::query()->where('event', 'account.role_changed')->sole();
    expect($applied->properties['via'])->toBe('approval')
        ->and($applied->properties['request_id'])->toBe($request->id)
        ->and($applied->properties['from'])->toBe([RoleName::Guru->value]);

    Notification::assertSentTo([$this->admin, $second, $guru], RoleChangeNotice::class, fn (RoleChangeNotice $n): bool => $n->kind() === 'role.applied');

    expect(fn () => app(ApproveRoleChange::class)->handle($second, $request->fresh()))->toThrow(AuthorizationException::class);
});

it('never applies an old approval-mode request by itself', function (): void {
    b5SecondAdmin();
    $guru = A::staff(RoleName::Guru);
    A::legacyRoleRequest($this->admin, $guru, RoleName::Kesiswaan);

    $this->travel(3)->days();

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('applies an old delay-mode request after its 24 hours', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $guru = A::staff(RoleName::Guru);
    A::legacyRoleRequest($this->admin, $guru, RoleName::Kesiswaan, 'Kesiswaan baru.', delay: true);

    $this->travel(23)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);

    $this->travel(2)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(1)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(Activity::query()->where('event', 'account.role_changed')->sole()->properties['via'])->toBe('delay')
        ->and(app(ApplyRoleChange::class)->applyDue())->toBe(0);
    Notification::assertSentTo($kepsek, RoleChangeNotice::class);
});

it('cancels an old pending request with a reason; it never applies afterwards', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::Admin, 'Admin cadangan.', delay: true);

    expect(fn () => app(CancelRoleChange::class)->handle($this->admin, $request, '  '))->toThrow(ValidationException::class);

    app(CancelRoleChange::class)->handle($this->admin, $request, 'Salah pilih akun.');

    $this->travel(2)->days();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and($request->fresh()->cancel_reason)->toBe('Salah pilih akun.')
        ->and(Activity::query()->where('event', 'role.change_cancelled')->sole()->causer_id)->toBe($this->admin->id);

    expect(fn () => app(CancelRoleChange::class)->handle($this->admin, $request->fresh(), 'Lagi.'))->toThrow(AuthorizationException::class);
});

it('cancels an old request automatically when the account is no longer eligible at apply time', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::TataUsaha, 'TU baru.', delay: true);
    $guru->forceFill(['status' => UserStatus::Disabled])->save();

    $this->travel(25)->hours();

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($request->fresh()->cancelled_at)->not->toBeNull()
        ->and($request->fresh()->cancel_reason)->toStartWith('Otomatis:')
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('denies a disabled Admin the approval of an old request', function (): void {
    $second = b5SecondAdmin();
    $request = A::legacyRoleRequest($this->admin, A::staff(RoleName::Guru), RoleName::Kesiswaan);
    $second->forceFill(['status' => UserStatus::Disabled])->save();

    expect(fn () => app(ApproveRoleChange::class)->handle($second->fresh(), $request))->toThrow(AuthorizationException::class);
});

// Screens ---------------------------------------------------------------------------------------------------------

it('changes the role from the Ubah peran dialog at once and says nothing about waiting', function (): void {
    b5SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->assertSee('Langsung berlaku setelah disimpan.')
        ->assertDontSee('persetujuan Admin lain')
        ->assertDontSee('24 jam kemudian')
        ->call('openStaff', $guru->id, 'staf-peran')
        ->set('role', RoleName::Kesiswaan->value)
        ->set('reason', 'Ditugaskan di kesiswaan.')
        ->call('changeRole')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Peran diubah. Pengguna perlu login ulang.');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);

    // Nothing waits, so the panel draws nothing.
    Livewire::test(RoleChangeRequests::class)
        ->assertDontSee('Perubahan peran')
        ->assertDontSee('Setujui');
});

it('shows an old pending request to a second Admin, without saying new grants wait', function (): void {
    $second = b5SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan di kesiswaan.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(RoleChangeRequests::class)
        ->assertSee('Pak Agus Santoso')
        ->assertSee('Peran yang diubah sekarang langsung berlaku.')
        ->assertDontSee('persetujuan Admin lain')
        ->assertDontSee('24 jam')
        ->assertDontSee('Menunggu Admin lain')
        ->assertDontSee('Setujui');

    $this->actingAs($second);

    Livewire::test(RoleChangeRequests::class)
        ->assertSee('Setujui')
        ->call('approve', $request->id)
        ->assertDispatched('sth-toast');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value]);
});

it('cancels an old request from the panel with a reason and asks for the password first', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = A::legacyRoleRequest($this->admin, $guru, RoleName::TataUsaha, 'TU.', delay: true);
    $this->actingAs($this->admin);

    Livewire::test(RoleChangeRequests::class)
        ->call('openCancel', $request->id)
        ->set('cancelReason', 'Tidak jadi.')
        ->call('cancel')
        ->assertRedirect(route('password.confirm'));

    session(A::passwordConfirmed());

    Livewire::test(RoleChangeRequests::class)
        ->call('openCancel', $request->id)
        ->call('cancel')
        ->assertHasErrors('cancelReason')
        ->set('cancelReason', 'Tidak jadi.')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($request->fresh()->cancelled_at)->not->toBeNull();
});

it('forbids the panel to non-Admins', function (): void {
    $this->actingAs(A::staff(RoleName::Kesiswaan));

    Livewire::test(RoleChangeRequests::class)->assertForbidden();
});

it('leaves an account that is Humas already as it is (D-139)', function (): void {
    $humas = A::staff(RoleName::Humas);

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Humas->value])
        ->and($humas->fresh()->can(Permission::ReportVerify->value))->toBeTrue()
        ->and(RoleChangeRequest::query()->count())->toBe(0);
});
