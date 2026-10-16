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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

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

// Non-privileged + guard ---------------------------------------------------------------------------

it('applies a change to a non-privileged role at once', function (): void {
    $humas = A::staff(RoleName::Humas);

    $result = app(RequestRoleChange::class)->handle($this->admin, $humas, RoleName::Guru, 'Kembali mengajar.');

    expect($result)->toBeNull()
        ->and($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
});

it('applies a downgrade from a privileged role at once', function (): void {
    $kesiswaan = A::staff(RoleName::Kesiswaan);

    expect(app(RequestRoleChange::class)->handle($this->admin, $kesiswaan, RoleName::Guru, 'Kembali mengajar.'))->toBeNull()
        ->and($kesiswaan->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('refuses privileged roles in the direct ChangeStaffRole action', function (RoleName $role): void {
    $guru = A::staff(RoleName::Guru);

    expect(fn () => app(ChangeStaffRole::class)->handle($this->admin, $guru, $role, 'Langsung.'))->toThrow(ValidationException::class)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
})->with([RoleName::Admin, RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::Humas]);

it('knows which roles are privileged', function (): void {
    expect(PrivilegedRoles::isPrivileged(RoleName::Admin))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::KepalaSekolah))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::TataUsaha))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Kesiswaan))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Humas))->toBeTrue()
        ->and(PrivilegedRoles::isPrivileged(RoleName::Guru))->toBeFalse();
});

// Second Admin approval ----------------------------------------------------------------------------

it('waits for a second Admin when one exists, and audits + notifies the request', function (): void {
    $second = b5SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);

    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan di kesiswaan.');

    expect($request)->toBeInstanceOf(RoleChangeRequest::class)
        ->and($request->usesDelay())->toBeFalse()
        ->and($request->from_roles)->toBe([RoleName::Guru->value])
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);

    $log = Activity::query()->where('event', 'role.change_requested')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['mode'])->toBe('approval')
        ->and($log->properties['to'])->toBe([RoleName::Kesiswaan->value]);

    Notification::assertSentTo([$this->admin, $second, $guru], RoleChangeNotice::class);
});

it('lets only another Admin approve: not the requester, not the promoted account', function (): void {
    $second = b5SecondAdmin();
    $tu = A::staff(RoleName::TataUsaha);
    $request = app(RequestRoleChange::class)->handle($this->admin, $second, RoleName::TataUsaha, 'Pindah ke TU.');

    expect(fn () => app(ApproveRoleChange::class)->handle($this->admin, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($second, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($tu, $request))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->isPending())->toBeTrue();
});

it('applies the role when a second Admin approves: sessions revoked, audited, notified', function (): void {
    $second = b5SecondAdmin();
    $guru = A::staff(RoleName::Guru);
    H::fakeSessions($guru, 2);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Membantu TU.');

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

    expect(fn () => app(ApproveRoleChange::class)->handle($second, $request->fresh()))->toThrow(AuthorizationException::class);
});

it('never applies an approval-mode request by itself', function (): void {
    b5SecondAdmin();
    $guru = A::staff(RoleName::Guru);
    app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan.');

    $this->travel(3)->days();

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

// Single Admin: 24 h delay -------------------------------------------------------------------------

it('applies after 24 hours when the requester is the only Admin', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $guru = A::staff(RoleName::Guru);

    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Kesiswaan baru.');

    expect($request->usesDelay())->toBeTrue()
        ->and((int) round(now()->diffInMinutes($request->effective_at)))->toBe(24 * 60)
        ->and(Activity::query()->where('event', 'role.change_requested')->sole()->properties['mode'])->toBe('delay');
    Notification::assertSentTo($kepsek, RoleChangeNotice::class);

    $this->travel(23)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);

    $this->travel(2)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(1)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(Activity::query()->where('event', 'account.role_changed')->sole()->properties['via'])->toBe('delay')
        ->and(app(ApplyRoleChange::class)->applyDue())->toBe(0);
});

it('cancels a pending change with a reason; it never applies afterwards', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Admin, 'Admin cadangan.');

    expect(fn () => app(CancelRoleChange::class)->handle($this->admin, $request, '  '))->toThrow(ValidationException::class);

    app(CancelRoleChange::class)->handle($this->admin, $request, 'Salah pilih akun.');

    $this->travel(2)->days();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and($request->fresh()->cancel_reason)->toBe('Salah pilih akun.')
        ->and(Activity::query()->where('event', 'role.change_cancelled')->sole()->causer_id)->toBe($this->admin->id);

    expect(fn () => app(CancelRoleChange::class)->handle($this->admin, $request->fresh(), 'Lagi.'))->toThrow(AuthorizationException::class);
});

it('cancels automatically when the account is no longer eligible at apply time', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'TU baru.');
    $guru->forceFill(['status' => UserStatus::Disabled])->save();

    $this->travel(25)->hours();

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($request->fresh()->cancelled_at)->not->toBeNull()
        ->and($request->fresh()->cancel_reason)->toStartWith('Otomatis:')
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

// Limits + access ----------------------------------------------------------------------------------

it('allows one pending request per account', function (): void {
    $guru = A::staff(RoleName::Guru);
    app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Pertama.');

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Kedua.'))
        ->toThrow(ValidationException::class);
});

it('counts pending Admin requests towards the 3-Admin limit', function (): void {
    b5SecondAdmin();
    app(RequestRoleChange::class)->handle($this->admin, A::staff(RoleName::Guru), RoleName::Admin, 'Admin ketiga.');

    expect(fn () => app(RequestRoleChange::class)->handle($this->admin, A::staff(RoleName::Humas), RoleName::Admin, 'Admin keempat.'))
        ->toThrow(ValidationException::class);
});

it('denies role changes to staff without user.manage', function (RoleName $role): void {
    $actor = A::staff($role);
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan.');

    expect(fn () => app(RequestRoleChange::class)->handle($actor, $guru, RoleName::Kesiswaan, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveRoleChange::class)->handle($actor, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelRoleChange::class)->handle($actor, $request, 'x'))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);

it('denies a disabled Admin', function (): void {
    $second = b5SecondAdmin();
    $request = app(RequestRoleChange::class)->handle($this->admin, A::staff(RoleName::Guru), RoleName::Kesiswaan, 'x');
    $second->forceFill(['status' => UserStatus::Disabled])->save();

    expect(fn () => app(ApproveRoleChange::class)->handle($second->fresh(), $request))->toThrow(AuthorizationException::class);
});

// Screens ------------------------------------------------------------------------------------------

it('creates a request from the Ubah peran dialog and lists it for the second Admin', function (): void {
    $second = b5SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->call('openStaff', $guru->id, 'staf-peran')
        ->set('role', RoleName::Kesiswaan->value)
        ->set('reason', 'Ditugaskan di kesiswaan.')
        ->call('changeRole')
        ->assertHasNoErrors()
        ->assertDispatched('role-change-requested')
        ->assertDispatched('sth-toast', message: 'Perubahan peran menunggu persetujuan Admin lain.');

    Livewire::test(RoleChangeRequests::class)
        ->assertSee('Pak Agus Santoso')
        ->assertDontSee('Setujui');

    $request = RoleChangeRequest::query()->sole();
    $this->actingAs($second);

    Livewire::test(RoleChangeRequests::class)
        ->assertSee('Setujui')
        ->call('approve', $request->id)
        ->assertDispatched('sth-toast');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value]);
});

it('cancels from the panel with a reason and asks for the password first', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'TU.');
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

it('waits for a second Admin before an account becomes Humas, and takes Humas away at once (D-139)', function (): void {
    b5SecondAdmin();
    $guru = A::staff(RoleName::Guru);

    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Humas, 'Ditugaskan di humas.');

    expect($request)->toBeInstanceOf(RoleChangeRequest::class)
        ->and($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and(fn () => app(ChangeStaffRole::class)->handle($this->admin, A::staff(RoleName::Guru, ['email' => 'guru.dua@example.test', 'username' => 'guru.dua']), RoleName::Humas, 'Langsung.'))
        ->toThrow(ValidationException::class);

    $humas = A::staff(RoleName::Humas);
    expect(app(RequestRoleChange::class)->handle($this->admin, $humas, RoleName::Guru, 'Kembali mengajar.'))->toBeNull()
        ->and($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('leaves an account that is Humas already as it is: no new approval, no lost role (D-139)', function (): void {
    $humas = A::staff(RoleName::Humas);

    expect(PrivilegedRoles::needsApproval($humas, RoleName::Humas))->toBeFalse()
        ->and(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($humas->fresh()->getRoleNames()->all())->toBe([RoleName::Humas->value])
        ->and($humas->fresh()->can(Permission::ReportVerify->value))->toBeTrue()
        ->and(RoleChangeRequest::query()->count())->toBe(0);
});
