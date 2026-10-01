<?php

declare(strict_types=1);

use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Roles\ApproveRoleChange;
use App\Actions\Roles\CancelRoleChange;
use App\Actions\Users\CreateStaffAccount;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\RoleChangeNotice;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
| W4 — lead decision (backlog #29): a NEW staff account with a privileged role (admin, tata_usaha, kesiswaan) follows
| the role-change rule of B5: created without a role + a pending RoleChangeRequest (second Admin, or 24 h when the
| creator is the only Admin). Also the scheduler entry `sth:peran-tertunda` (backlog #27).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->admin = A::admin();
});

function w4NewStaff(string $role, array $override = []): array
{
    return array_merge([
        'name' => 'Ibu Dwi Rahayu',
        'username' => 'dwi.rahayu',
        'email' => 'dwi.rahayu@example.test',
        'role' => $role,
        'password' => 'Sementara-2026!x',
    ], $override);
}

it('creates a privileged account without a role and a request that waits for a second Admin', function (string $role): void {
    $second = A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);

    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff($role));
    $request = RoleChangeRequest::query()->sole();

    expect($user->fresh()->getRoleNames()->all())->toBe([])
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and($request->user_id)->toBe($user->id)
        ->and($request->from_roles)->toBe([])
        ->and($request->to_role->value)->toBe($role)
        ->and($request->usesDelay())->toBeFalse()
        ->and($request->reason)->toBe(CreateStaffAccount::NEW_ACCOUNT_REASON);

    $created = Activity::query()->where('event', 'account.staff_created')->sole();
    expect($created->properties['role'])->toBeNull()
        ->and($created->properties['requested_role'])->toBe($role)
        ->and(Activity::query()->where('event', 'role.change_requested')->sole()->properties['mode'])->toBe('approval');

    Notification::assertSentTo([$this->admin, $second], RoleChangeNotice::class, fn (RoleChangeNotice $n): bool => str_contains(implode(' ', $n->lines()), 'membuat akun Ibu Dwi Rahayu'));
    Notification::assertNotSentTo($this->admin, StaffAccountChanged::class);

    // The creator cannot approve their own request; the second Admin can.
    expect($this->admin->can('approve', $request))->toBeFalse();
    expect(app(ApproveRoleChange::class)->handle($second, $request))->toBeTrue()
        ->and($user->fresh()->getRoleNames()->all())->toBe([$role])
        ->and(Activity::query()->where('event', 'account.role_changed')->sole()->properties['via'])->toBe('approval');
})->with([RoleName::Admin->value, RoleName::Kesiswaan->value, RoleName::TataUsaha->value]);

it('applies the role of a new account after 24 h when its creator is the only Admin', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);

    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Kesiswaan->value));
    $request = RoleChangeRequest::query()->sole();

    expect($request->usesDelay())->toBeTrue()
        ->and((int) round(now()->diffInMinutes($request->effective_at)))->toBe(24 * 60);
    Notification::assertSentTo($kepsek, RoleChangeNotice::class);

    $this->travel(23)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($user->fresh()->getRoleNames()->all())->toBe([]);

    $this->travel(2)->hours();
    expect(app(ApplyRoleChange::class)->applyDue())->toBe(1)
        ->and($user->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value]);
});

it('keeps the account without a role when the request is cancelled', function (): void {
    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::TataUsaha->value));

    app(CancelRoleChange::class)->handle($this->admin, RoleChangeRequest::query()->sole(), 'Salah pilih peran.');
    $this->travel(25)->hours();

    expect(app(ApplyRoleChange::class)->applyDue())->toBe(0)
        ->and($user->fresh()->getRoleNames()->all())->toBe([]);
});

it('gives non-privileged roles at once without a request', function (string $role): void {
    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff($role));

    expect($user->fresh()->getRoleNames()->all())->toBe([$role])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
    Notification::assertSentTo($this->admin, StaffAccountChanged::class);
})->with([RoleName::Guru->value, RoleName::Humas->value]);

it('counts pending Admin requests towards the 3-Admin limit and creates nothing when refused', function (): void {
    A::admin(['username' => 'operator2']);
    app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Admin->value, ['username' => 'admin.tiga', 'email' => null]));

    try {
        app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Admin->value, ['username' => 'admin.empat', 'email' => null]));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('role');
    }

    expect(User::query()->where('username', 'admin.empat')->exists())->toBeFalse()
        ->and(RoleChangeRequest::query()->pending()->count())->toBe(1);
});

it('explains the rule in the create form and lists the waiting role (Pengguna screen)', function (): void {
    A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->assertSee('akun dibuat tanpa peran dulu')
        ->set('new', ['name' => 'Ibu Sri Handayani', 'username' => 'sri.h', 'email' => '', 'role' => RoleName::TataUsaha->value, 'honorific' => 'Ibu', 'nip' => '', 'rank' => '', 'position' => '', 'password' => 'Sementara-2026!z', 'password_confirmation' => 'Sementara-2026!z'])
        ->call('createStaff')
        ->assertHasNoErrors()
        ->assertDispatched('role-change-requested')
        ->assertDispatched('sth-toast', message: 'Akun staf dibuat tanpa peran. Peran Tata Usaha menunggu persetujuan Admin lain.')
        ->assertSee('Ibu Sri Handayani')
        ->assertSee('Tanpa peran')
        ->assertSee('Menunggu: Tata Usaha');

    expect(User::query()->where('username', 'sri.h')->sole()->getRoleNames()->all())->toBe([]);
});

it('schedules the delayed role changes every five minutes on one server (sth:peran-tertunda)', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => $e instanceof CallbackEvent && $e->description === 'sth:peran-tertunda');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Kesiswaan->value));
    $this->travel(25)->hours();

    $event->run($this->app);

    expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(RoleChangeRequest::query()->sole()->applied_at)->not->toBeNull();
});
