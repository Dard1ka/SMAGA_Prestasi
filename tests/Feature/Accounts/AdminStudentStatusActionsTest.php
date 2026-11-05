<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivateStudentAccount;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\ExpireActivationWindow;
use App\Actions\Accounts\FreezeAccount;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Fortify\AuthenticateUser;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Akun\StatusAktivasi;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * The three Actions behind the new targets of "Ubah status" (the lead, 2026-11-05: the Admin sets every state of Status
 * aktivasi): ActivateStudentAccount ("Aktif"), ExpireActivationWindow ("Kedaluwarsa") and FreezeAccount ("Dibekukan").
 * Admin only, student accounts only, never the own account, a reason of 10–500 characters, audited without names.
 * Fictional data only.
 */

uses(RefreshDatabase::class);

const SV6_NISN = '0098765432';

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->admin = A::admin();
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

/**
 * A student account in a K9 state (password "password", NISN SV6_NISN for the first one): belum (window ahead),
 * kedaluwarsa, aktif (finished activation), dibekukan (K3), nonaktif, arsip.
 */
function sv6Student(string $state, array $user = []): User
{
    $pending = ['status' => UserStatus::PendingActivation, 'must_change_password' => true, 'email' => null, 'email_verified_at' => null];
    $attributes = match ($state) {
        'belum' => $pending + ['initial_password_expires_at' => now()->addDays(5)],
        'kedaluwarsa' => $pending + ['initial_password_expires_at' => now()->subDay()],
        'dibekukan' => $pending + ['initial_password_expires_at' => now()->addDays(5), 'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10],
        'aktif' => ['status' => UserStatus::Active, 'must_change_password' => false, 'email' => 'raka.pratama@example.test', 'email_verified_at' => now(), 'password_changed_at' => now()],
        'nonaktif' => ['status' => UserStatus::Disabled] + $pending,
        'arsip' => ['status' => UserStatus::Archived] + $pending,
    };

    $nisn = Student::query()->where('nisn', SV6_NISN)->exists() ? fake()->unique()->numerify('00########') : SV6_NISN;
    $row = Student::factory()->create(['classroom_id' => test()->classroom->getKey(), 'nisn' => $nisn]);
    $row->user->forceFill(array_merge(['name' => 'Raka Pratama'], $attributes, $user))->saveQuietly();

    return $row->user->fresh();
}

/** The errors of a refused call, key => first message. @return array<string, string> */
function sv6Errors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return collect($e->errors())->map(fn (array $messages): string => (string) $messages[0])->all();
    }

    return [];
}

function sv6Login(string $password = 'password'): TestResponse
{
    return test()->from('/login')->post('/login', ['identifier' => SV6_NISN, 'password' => $password]);
}

function sv6Session(User $user): void
{
    DB::table('sessions')->insert(['id' => 'sesi-'.$user->getKey(), 'user_id' => $user->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 'Pest', 'payload' => '', 'last_activity' => time()]);
}

// --- ActivateStudentAccount ("Aktif") ------------------------------------------------------------------------------

it('makes a student who has not finished activation active, the window gone and the password change still due', function (string $state): void {
    $user = sv6Student($state, ['failed_activation_attempts' => 3]);
    $before = AccountControls::date($user, 'initial_password_expires_at');

    $result = app(ActivateStudentAccount::class)->handle($this->admin, $user, '  Siswa sudah dicek di TU.  ');

    $fresh = $user->fresh();
    $row = Activity::query()->where('event', ActivateStudentAccount::EVENT)->sole();

    expect($result->status)->toBe(UserStatus::Active)
        ->and($fresh->status)->toBe(UserStatus::Active)
        ->and(AccountControls::state($fresh))->toBe(AccountControls::STATE_ACTIVE)
        ->and(AccountControls::date($fresh, 'initial_password_expires_at'))->toBeNull()
        ->and($fresh->must_change_password)->toBeTrue()
        ->and($fresh->email)->toBeNull()
        ->and(AccountControls::failedAttempts($fresh))->toBe(0)
        ->and(AccountControls::date($fresh, 'activated_at'))->toBeNull()
        ->and((int) $row->subject_id)->toBe((int) $user->getKey())
        ->and((int) $row->causer_id)->toBe((int) $this->admin->getKey())
        ->and($row->properties['reason'])->toBe('Siswa sudah dicek di TU.')
        ->and($row->properties['from'])->toBe($state)
        ->and($row->properties['previous_expires_at'])->toBe($before->toIso8601String())
        ->and(json_encode($row->properties))->not->toContain('Raka');

    Notification::assertNothingSent();
})->with(['belum', 'kedaluwarsa']);

it('still leads a student the Admin made active through email, code and a new password at the next login, without Data diri', function (): void {
    $user = sv6Student('kedaluwarsa');

    // Expired: the initial password does not log in.
    sv6Login()->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();

    app(ActivateStudentAccount::class)->handle($this->admin, $user, 'Siswa sudah dicek di TU.');
    $fresh = $user->fresh();

    expect(app(DetermineActivationStep::class)($fresh))->toBe(DetermineActivationStep::EMAIL)
        ->and(DetermineActivationStep::profileOpen($fresh))->toBeFalse()
        ->and($fresh->student->profile_confirmed_at)->toBeNull();

    sv6Login()->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($fresh);
    $this->get(route('home'))->assertRedirect(route('aktivasi'));

    // Email bound and verified (the code step): the password step is next, not "Data diri".
    $fresh->forceFill(['email' => 'raka.pratama@example.test', 'email_verified_at' => now()])->save();
    expect(app(DetermineActivationStep::class)($fresh->fresh()))->toBe(DetermineActivationStep::PASSWORD);
    $this->get(route('home'))->assertRedirect(route('aktivasi'));
});

it('refuses an account that is active, closed, frozen, a staff account, the own account and a short or long reason', function (): void {
    $staff = A::staff(RoleName::Guru, ['status' => UserStatus::PendingActivation]);
    $belum = sv6Student('belum');
    $activate = app(ActivateStudentAccount::class);
    $reason = 'Siswa sudah dicek di TU.';

    expect(sv6Errors(fn () => $activate->handle($this->admin, sv6Student('aktif'), $reason)))->toBe(['user' => ActivateStudentAccount::MSG_ACTIVE])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, sv6Student('nonaktif'), $reason)))->toBe(['user' => ActivateStudentAccount::MSG_CLOSED])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, sv6Student('arsip'), $reason)))->toBe(['user' => ActivateStudentAccount::MSG_CLOSED])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, sv6Student('dibekukan'), $reason)))->toBe(['user' => ActivateStudentAccount::MSG_FROZEN])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, $staff, $reason)))->toBe(['user' => ActivateStudentAccount::MSG_NOT_STUDENT])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, $this->admin, $reason)))->toBe(['user' => ActivateStudentAccount::MSG_SELF])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, $belum, '   ')))->toBe(['reason' => 'Isi alasannya.'])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, $belum, 'Dicek.')))->toBe(['reason' => 'Alasan minimal 10 karakter.'])
        ->and(sv6Errors(fn () => $activate->handle($this->admin, $belum, str_repeat('a', 501))))->toBe(['reason' => 'Alasan maksimal 500 karakter.']);

    expect($belum->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and($staff->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(Activity::query()->where('event', ActivateStudentAccount::EVENT)->exists())->toBeFalse();
});

// --- ExpireActivationWindow ("Kedaluwarsa") ------------------------------------------------------------------------

it('ends the activation window: the password no longer logs in, sessions end, audited', function (): void {
    $user = sv6Student('belum');
    $before = AccountControls::date($user, 'initial_password_expires_at');
    sv6Session($user);

    app(ExpireActivationWindow::class)->handle($this->admin, $user, 'Siswa belum boleh aktivasi.');

    $fresh = $user->fresh();
    $row = Activity::query()->where('event', ExpireActivationWindow::EVENT)->sole();

    expect(AccountControls::state($fresh))->toBe(AccountControls::STATE_EXPIRED)
        ->and(AccountControls::date($fresh, 'initial_password_expires_at')->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($fresh->status)->toBe(UserStatus::PendingActivation)
        ->and(DB::table('sessions')->where('user_id', $user->getKey())->count())->toBe(0)
        ->and($row->properties['reason'])->toBe('Siswa belum boleh aktivasi.')
        ->and($row->properties['previous_expires_at'])->toBe($before->toIso8601String())
        ->and($row->properties['expires_at'])->not->toBeNull()
        ->and(json_encode($row->properties))->not->toContain('Raka');

    sv6Login()->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();

    // "Buka ulang" opens it again.
    app(ReopenActivationWindow::class)->handle($this->admin, $fresh, 'Siswa siap aktivasi sekarang.');
    sv6Login()->assertRedirect(route('aktivasi'));
    Notification::assertNothingSent();
});

it('refuses to expire an account that is active, closed, frozen, already expired, without a window to end, staff or own', function (): void {
    $expire = app(ExpireActivationWindow::class);
    $reason = 'Siswa belum boleh aktivasi.';
    $belum = sv6Student('belum');

    expect(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('aktif'), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_ACTIVE])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('nonaktif'), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_CLOSED])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('arsip'), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_CLOSED])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('dibekukan'), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_FROZEN])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('kedaluwarsa'), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_EXPIRED])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, sv6Student('belum', ['must_change_password' => false]), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_NO_WINDOW])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, A::staff(RoleName::Guru, ['status' => UserStatus::PendingActivation]), $reason)))->toBe(['user' => ExpireActivationWindow::MSG_NOT_STUDENT])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, $this->admin, $reason)))->toBe(['user' => ExpireActivationWindow::MSG_SELF])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, $belum, 'Belum.')))->toBe(['reason' => 'Alasan minimal 10 karakter.'])
        ->and(sv6Errors(fn () => $expire->handle($this->admin, $belum, str_repeat('a', 501))))->toBe(['reason' => 'Alasan maksimal 500 karakter.']);

    expect(AccountControls::state($belum->fresh()))->toBe(AccountControls::STATE_PENDING)
        ->and(Activity::query()->where('event', ExpireActivationWindow::EVENT)->exists())->toBeFalse();
});

// --- FreezeAccount ("Dibekukan") -----------------------------------------------------------------------------------

it('freezes a student account by hand: no login, sessions end, audited with the state before', function (string $state): void {
    $user = sv6Student($state);
    sv6Session($user);
    $token = $user->remember_token;

    app(FreezeAccount::class)->handle($this->admin, $user, 'Akun dipakai orang lain.');

    $fresh = $user->fresh();
    $row = Activity::query()->where('event', FreezeAccount::EVENT)->sole();

    expect(AccountControls::state($fresh))->toBe(AccountControls::STATE_FROZEN)
        ->and(AccountControls::frozenReason($fresh))->toBe(AccountControls::FROZEN_ADMIN)
        ->and($fresh->status)->toBe($user->status)
        ->and(DB::table('sessions')->where('user_id', $user->getKey())->count())->toBe(0)
        ->and($fresh->remember_token)->not->toBe($token)
        ->and($row->properties['reason'])->toBe('Akun dipakai orang lain.')
        ->and($row->properties['from'])->toBe($state)
        ->and(json_encode($row->properties))->not->toContain('Raka');

    sv6Login()->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
    Notification::assertNothingSent();
})->with(['aktif', 'belum', 'kedaluwarsa']);

it('ends a session that survived the freeze at the next request', function (): void {
    $user = sv6Student('aktif');
    app(FreezeAccount::class)->handle($this->admin, $user, 'Akun dipakai orang lain.');

    $this->actingAs($user->fresh())->get(route('home'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('lets only an Admin lift an Admin freeze: "Buka ulang" refuses it, "Buka pembekuan" lifts it', function (): void {
    $user = sv6Student('belum');
    app(FreezeAccount::class)->handle($this->admin, $user, 'Akun dipakai orang lain.');

    expect(sv6Errors(fn () => app(ReopenActivationWindow::class)->handle(A::staff(RoleName::Kesiswaan), $user->fresh(), 'Siswa siap aktivasi sekarang.')))
        ->toBe(['reason' => 'Akun dibekukan Admin. Hanya Admin yang bisa membukanya.'])
        ->and(AccountControls::isFrozen($user->fresh()))->toBeTrue();

    app(UnfreezeAccount::class)->handle($this->admin, $user->fresh(), 'Pemilik akun sudah dicek.');

    expect(AccountControls::state($user->fresh()))->toBe(AccountControls::STATE_PENDING)
        ->and(Activity::query()->where('event', 'account.unfrozen')->sole()->properties['was'])->toBe(AccountControls::FROZEN_ADMIN);
});

it('names an Admin freeze "Dibekukan Admin" on Status aktivasi and in the report flags', function (): void {
    $user = sv6Student('belum');
    app(FreezeAccount::class)->handle($this->admin, $user, 'Akun dipakai orang lain.');

    $row = app(ActivationReport::class)->rows()->firstWhere('userId', (int) $user->getKey());
    expect($row['flags'])->toContain(ActivationReport::FLAG_FROZEN_ADMIN)
        ->and(ActivationReport::FLAG_FROZEN_ADMIN)->toBe('Dibekukan Admin');

    $this->actingAs(A::staff(RoleName::Kesiswaan));
    $screen = Livewire::test(StatusAktivasi::class)->assertSee('X-1 · Dibekukan Admin');

    expect(collect($screen->viewData('frozen'))->firstWhere('id', (int) $user->getKey())['action'])->toBe('admin');
});

it('refuses to freeze an account that is closed, already frozen, staff or own, and a short reason', function (): void {
    $freeze = app(FreezeAccount::class);
    $reason = 'Akun dipakai orang lain.';
    $aktif = sv6Student('aktif');

    expect(sv6Errors(fn () => $freeze->handle($this->admin, sv6Student('nonaktif'), $reason)))->toBe(['user' => FreezeAccount::MSG_CLOSED])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, sv6Student('arsip'), $reason)))->toBe(['user' => FreezeAccount::MSG_CLOSED])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, sv6Student('dibekukan'), $reason)))->toBe(['user' => FreezeAccount::MSG_FROZEN])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, A::staff(RoleName::Guru), $reason)))->toBe(['user' => FreezeAccount::MSG_NOT_STUDENT])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, $this->admin, $reason)))->toBe(['user' => FreezeAccount::MSG_SELF])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, $aktif, '   ')))->toBe(['reason' => 'Isi alasannya.'])
        ->and(sv6Errors(fn () => $freeze->handle($this->admin, $aktif, 'Bekukan.')))->toBe(['reason' => 'Alasan minimal 10 karakter.']);

    expect(AccountControls::isFrozen($aktif->fresh()))->toBeFalse()
        ->and(Activity::query()->where('event', FreezeAccount::EVENT)->exists())->toBeFalse();
});

// --- Admin only ----------------------------------------------------------------------------------------------------

it('lets no role without user.archive or student.status use the three Actions', function (RoleName $role): void {
    $actor = A::staff($role);
    $belum = sv6Student('belum');
    $reason = 'Perubahan status oleh staf.';

    foreach ([ActivateStudentAccount::class, ExpireActivationWindow::class, FreezeAccount::class] as $action) {
        expect(fn () => app($action)->handle($actor, $belum, $reason))->toThrow(AuthorizationException::class);
    }

    expect(AccountControls::state($belum->fresh()))->toBe(AccountControls::STATE_PENDING)
        ->and(Activity::query()->whereIn('event', [ActivateStudentAccount::EVENT, ExpireActivationWindow::EVENT, FreezeAccount::EVENT])->exists())->toBeFalse();
})->with([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

it('lets Kesiswaan and Tata Usaha use the three Actions on a student (student.status, 2026-11-05), never on a staff account', function (RoleName $role): void {
    $actor = A::staff($role);
    $reason = 'Perubahan status oleh staf.';

    app(ActivateStudentAccount::class)->handle($actor, $active = sv6Student('belum'), $reason);
    app(ExpireActivationWindow::class)->handle($actor, $expired = sv6Student('belum'), $reason);
    app(FreezeAccount::class)->handle($actor, $frozen = sv6Student('belum'), $reason);

    expect(AccountControls::state($active->fresh()))->toBe(AccountControls::STATE_ACTIVE)
        ->and(AccountControls::state($expired->fresh()))->toBe(AccountControls::STATE_EXPIRED)
        ->and(AccountControls::state($frozen->fresh()))->toBe(AccountControls::STATE_FROZEN)
        ->and(fn () => app(FreezeAccount::class)->handle($actor, A::staff(RoleName::Guru), $reason))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::TataUsaha]);
