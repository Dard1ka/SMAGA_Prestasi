<?php

declare(strict_types=1);

use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Roles\ApproveRoleChange;
use App\Actions\Roles\CancelRoleChange;
use App\Actions\Roles\RequestRoleChange;
use App\Actions\Users\ChangeStaffRole;
use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Admin\RoleChangeRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09): the "Perubahan peran" panel inside Admin › Pengguna & peran. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

function qa9SecondAdmin(): User
{
    return A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
}

// Item 5: a request decided elsewhere ---------------------------------------------------------------------------

it('says so instead of a 403 when the request was cancelled before the approval (QA9)', function (): void {
    $second = qa9SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan di kesiswaan.');
    $this->actingAs($second);
    session(A::passwordConfirmed());

    $panel = Livewire::test(RoleChangeRequests::class)->assertSee('Setujui');

    app(CancelRoleChange::class)->handle($this->admin, $request, 'Tidak jadi.');

    $panel->call('approve', $request->id)
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDontSee('Setujui');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('says so instead of a 403 when another Admin approved the request first (QA9)', function (): void {
    $second = qa9SecondAdmin();
    $third = A::admin(['name' => 'Pak Yusuf Hidayat', 'username' => 'operator3']);
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'Ditugaskan di TU.');
    $this->actingAs($second);
    session(A::passwordConfirmed());

    $panel = Livewire::test(RoleChangeRequests::class);

    app(ApproveRoleChange::class)->handle($third, $request);

    $panel->call('approve', $request->id)->assertDispatched('sth-toast', tone: 'warning');

    expect(Activity::query()->where('event', 'role.change_approved')->count())->toBe(1);
});

it('says so instead of a 403 when the request was cancelled before the cancel dialog opens (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'TU.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $panel = Livewire::test(RoleChangeRequests::class);

    app(CancelRoleChange::class)->handle($this->admin, $request, 'Dibatalkan di tab lain.');

    $panel->call('openCancel', $request->id)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', tone: 'warning');
});

it('says so when the 24-hour change took effect while the cancel dialog was open (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'TU.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $panel = Livewire::test(RoleChangeRequests::class)
        ->call('openCancel', $request->id)
        ->set('cancelReason', 'Tidak jadi.');

    $this->travel(25)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(1);

    $panel->call('cancel')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'batal-peran')
        ->assertSet('cancelId', null);

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::TataUsaha->value]);
});

// Item 6: the staff table follows a decision in the panel -------------------------------------------------------

it('tells the staff table when a role change is approved (QA9)', function (): void {
    $second = qa9SecondAdmin();
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::Kesiswaan, 'Ditugaskan di kesiswaan.');
    $this->actingAs($second);
    session(A::passwordConfirmed());

    $table = Livewire::test(Pengguna::class)->assertSee('Menunggu: Kesiswaan');

    Livewire::test(RoleChangeRequests::class)
        ->call('approve', $request->id)
        ->assertDispatched('role-change-decided');

    $table->dispatch('role-change-decided')->assertDontSee('Menunggu: Kesiswaan');
});

it('tells the staff table when a role change is cancelled (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Agus Santoso']);
    $request = app(RequestRoleChange::class)->handle($this->admin, $guru, RoleName::TataUsaha, 'TU.');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(RoleChangeRequests::class)
        ->call('openCancel', $request->id)
        ->set('cancelReason', 'Tidak jadi.')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertDispatched('role-change-decided');
});

// Item 7: the direct role change refuses the role the account already holds -------------------------------------

it('refuses a role the account already holds instead of doing nothing silently (QA9)', function (): void {
    $guru = A::staff(RoleName::Guru);

    expect(fn () => app(ChangeStaffRole::class)->handle($this->admin, $guru, RoleName::Guru, 'Cek peran.'))
        ->toThrow(ValidationException::class);

    expect(Activity::query()->where('event', 'account.role_changed')->exists())->toBeFalse();
});
