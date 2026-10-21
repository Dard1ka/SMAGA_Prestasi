<?php

declare(strict_types=1);

use App\Actions\Roles\ApplyRoleChange;
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
| "Tambah pengguna" gives the chosen role at once for every role, privileged ones included (the lead, 21 Okt 2026:
| "kalo sdh dibuat ya sudah tidak perlu persetujuan admin lainnya"; replaces the W4 rule of D-61, which created such an
| account without a role plus a request for a second Admin or the 24 h delay). The scheduler entry
| `sth:peran-tertunda` stays for requests made before that day.
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

/** The account as it is after its owner finished the first login (email, code, new password, 2FA). */
function w4Activated(User $user): User
{
    $user->forceFill([
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
        'must_change_password' => false,
        'password_changed_at' => now(),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user->fresh();
}

it('gives a privileged role at once to a new staff account: no request, no second Admin, no wait', function (string $role, string $area): void {
    A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);

    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff($role));

    expect($user->fresh()->getRoleNames()->all())->toBe([$role])
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and(RoleChangeRequest::query()->count())->toBe(0);

    $created = Activity::query()->where('event', 'account.staff_created')->sole();
    expect($created->causer_id)->toBe($this->admin->id)
        ->and($created->properties['role'])->toBe($role)
        ->and($created->properties->has('requested_role'))->toBeFalse()
        ->and(Activity::query()->where('event', 'role.change_requested')->exists())->toBeFalse();

    Notification::assertSentTo($this->admin, StaffAccountChanged::class, fn (StaffAccountChanged $n): bool => $n->kind() === 'staff.created'
        && str_contains(implode(' ', $n->lines()), 'membuat akun Ibu Dwi Rahayu dengan peran'));
    Notification::assertNotSentTo($user, RoleChangeNotice::class);
    Notification::assertNotSentTo($this->admin, RoleChangeNotice::class);

    // Once its owner finished the first login, the account opens its own area.
    $this->actingAs(w4Activated($user))->get(route($area.'.beranda'))->assertOk();
})->with([
    'Admin' => [RoleName::Admin->value, 'admin'],
    'Kesiswaan' => [RoleName::Kesiswaan->value, 'kesiswaan'],
    'Tata Usaha' => [RoleName::TataUsaha->value, 'tu'],
    'Humas' => [RoleName::Humas->value, 'humas'],
]);

it('gives the role at once also when the creator is the only Admin (no 24 h delay)', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);

    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Kesiswaan->value));

    expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
    Notification::assertNotSentTo($kepsek, RoleChangeNotice::class);
});

it('gives a guru its role at once, as before', function (): void {
    $user = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Guru->value));

    expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and(RoleChangeRequest::query()->count())->toBe(0);
    Notification::assertSentTo($this->admin, StaffAccountChanged::class);
});

it('never creates a Kepala Sekolah or a student here', function (string $role): void {
    expect(fn () => app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff($role)))
        ->toThrow(ValidationException::class);

    expect(User::query()->where('username', 'dwi.rahayu')->exists())->toBeFalse();
})->with([RoleName::KepalaSekolah->value, RoleName::Siswa->value]);

it('keeps the 3-Admin limit and creates nothing when refused', function (): void {
    A::admin(['username' => 'operator2']);
    $third = app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Admin->value, ['username' => 'admin.tiga', 'email' => null]));
    expect($third->fresh()->hasRole(RoleName::Admin->value))->toBeTrue();

    try {
        app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Admin->value, ['username' => 'admin.empat', 'email' => null]));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['role'][0])->toBe('Sudah ada 3 akun Admin.');
    }

    expect(User::query()->where('username', 'admin.empat')->exists())->toBeFalse();
});

it('still counts a pending Admin request made before 21 Okt 2026 towards the 3-Admin limit', function (): void {
    A::admin(['username' => 'operator2']);
    A::legacyRoleRequest($this->admin, A::staff(RoleName::Guru), RoleName::Admin, 'Admin ketiga (lama).');

    try {
        app(CreateStaffAccount::class)->handle($this->admin, w4NewStaff(RoleName::Admin->value, ['username' => 'admin.empat', 'email' => null]));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['role'][0])->toBe('Sudah ada 3 akun Admin (termasuk yang menunggu).');
    }

    expect(User::query()->where('username', 'admin.empat')->exists())->toBeFalse();
});

it('says the role applies at once in the create form and gives it (Pengguna screen)', function (): void {
    A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->assertSee('Peran langsung berlaku.')
        ->assertDontSee('persetujuan Admin lain')
        ->assertDontSee('24 jam kemudian')
        ->assertDontSee('tanpa peran dulu')
        ->set('new', ['name' => 'Ibu Sri Handayani', 'username' => 'sri.h', 'email' => '', 'role' => RoleName::TataUsaha->value, 'honorific' => 'Ibu', 'nip' => '', 'rank' => '', 'position' => '', 'password' => 'Sementara-2026!z', 'password_confirmation' => 'Sementara-2026!z'])
        ->call('createStaff')
        ->assertHasNoErrors()
        ->assertNotDispatched('role-change-requested')
        ->assertDispatched('sth-toast', message: 'Akun staf dibuat. Berikan kata sandi sementara langsung ke pemiliknya.')
        ->assertSee('Ibu Sri Handayani')
        ->assertDontSee('Tanpa peran')
        ->assertDontSee('Menunggu: Tata Usaha');

    expect(User::query()->where('username', 'sri.h')->sole()->getRoleNames()->all())->toBe([RoleName::TataUsaha->value]);
});

it('keeps the delayed role changes of old requests on the scheduler every five minutes (sth:peran-tertunda)', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => $e instanceof CallbackEvent && $e->description === 'sth:peran-tertunda');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();

    // A new account of the W4 time: created without a role, its request in delay mode.
    $user = User::factory()->create(['username' => 'lama.kesiswaan', 'status' => UserStatus::PendingActivation]);
    A::legacyRoleRequest($this->admin, $user, RoleName::Kesiswaan, 'Peran awal akun staf baru.', delay: true);
    $this->travel(25)->hours();

    $event->run($this->app);

    expect($user->fresh()->getRoleNames()->all())->toBe([RoleName::Kesiswaan->value])
        ->and(RoleChangeRequest::query()->sole()->applied_at)->not->toBeNull()
        ->and(app(ApplyRoleChange::class)->applyDue())->toBe(0);
});
