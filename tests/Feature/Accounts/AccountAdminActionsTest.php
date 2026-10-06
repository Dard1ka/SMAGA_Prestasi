<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Fortify\AuthenticateUser;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    config(['session.driver' => 'database']);

    $this->admin = H::staff(RoleName::Admin);
});

// B1 — K10 (lead decision): the reset issues a random 72-hour temporary password, no longer the initial pattern.
it('resets a hijacked student account to a random temporary password and clears the email', function (): void {
    $student = H::activeStudent();
    $user = $student->user;
    $user->forceFill(['password' => Hash::make('Dipakai-penyerang-99')])->save();
    H::fakeSessions($user, 3);

    $otp = new OtpCode;
    $otp->forceFill([
        'user_id' => $user->getKey(), 'purpose' => 'reset_password', 'email' => 'x@example.test',
        'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5),
    ])->save();

    $temporary = app(ResetStudentAccount::class)->handle($this->admin, $user, 'Siswa datang ke TU, akun dibajak.');

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->email)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('Dipakai-penyerang-99', $user->password))->toBeFalse()
        ->and(strlen($temporary))->toBe(12)
        ->and(Hash::check($temporary, $user->password))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make('2010-03-02'), $user->password))->toBeFalse()
        ->and(H::sessionCount($user))->toBe(0)
        ->and($otp->fresh()->used_at)->not->toBeNull();

    $log = Activity::query()->where('event', 'account.reset')->firstOrFail();
    expect($log->properties['reason'])->toBe('Siswa datang ke TU, akun dibajak.')
        ->and($log->causer_id)->toBe($this->admin->getKey())
        ->and($log->subject_id)->toBe((string) $user->getKey());
});

// M2-A (QA 2026-10-05, Q2-02): the reset marks the password as one an Admin has seen; only a password the owner
// chooses takes the mark away.
it('marks the temporary password of a reset until the owner chooses a new one', function (): void {
    $user = H::activeStudent()->user;

    expect(CompleteActivation::hasTemporaryPassword($user->fresh()))->toBeFalse()
        ->and(AccountControls::date($user->fresh(), 'temporary_password_issued_at'))->toBeNull();

    $this->travelTo(now()->startOfSecond());
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Siswa lupa kata sandi.');

    $user->refresh();
    expect(CompleteActivation::hasTemporaryPassword($user))->toBeTrue()
        ->and(AccountControls::date($user, 'temporary_password_issued_at')->equalTo(now()))->toBeTrue()
        ->and(CompleteActivation::mayKeepPassword($user))->toBeFalse()
        // Never mass-assignable.
        ->and($user->isFillable('temporary_password_issued_at'))->toBeFalse();

    // A second reset keeps the mark, with the new time.
    $this->travel(5)->minutes();
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Kata sandi sementara hilang.');

    expect(AccountControls::date($user->fresh(), 'temporary_password_issued_at')->equalTo(now()))->toBeTrue();

    app(SetNewPassword::class)->handle($user->fresh(), 'Pelangi-senja-2026');

    expect(CompleteActivation::hasTemporaryPassword($user->fresh()))->toBeFalse()
        ->and(Hash::check('Pelangi-senja-2026', $user->fresh()->password))->toBeTrue();
});

it('marks accounts that were reset before M2-A when the migration backfills, and no others', function (): void {
    $this->travelTo(now()->subDays(2)->startOfSecond());
    $resetAt = now();

    // Reset before the column existed, not logged in since: still on the temporary password.
    $reset = H::activeStudent()->user;
    app(ResetStudentAccount::class)->handle($this->admin, $reset, 'Reset sebelum M2-A.');

    // Reset as well, but the owner chose a password afterwards.
    $changed = Student::factory()->create()->user;
    app(ResetStudentAccount::class)->handle($this->admin, $changed, 'Reset sebelum M2-A.');
    app(SetNewPassword::class)->handle($changed->fresh(), 'Pelangi-senja-2026');

    // Imported, never reset: the school password.
    $imported = Student::factory()->create()->user;

    $this->travelBack();

    // As the rows were before the migration: nobody is marked.
    DB::table('users')->update(['temporary_password_issued_at' => null]);

    $migration = require database_path('migrations/2026_10_06_100000_m2a_add_temporary_password_issued_at_to_users.php');
    $migration->backfill();

    expect(AccountControls::date($reset->fresh(), 'temporary_password_issued_at')?->equalTo($resetAt))->toBeTrue()
        ->and(CompleteActivation::mayKeepPassword($reset->fresh()))->toBeFalse()
        ->and(CompleteActivation::hasTemporaryPassword($changed->fresh()))->toBeFalse()
        ->and(CompleteActivation::hasTemporaryPassword($imported->fresh()))->toBeFalse()
        ->and(CompleteActivation::mayKeepPassword($imported->fresh()))->toBeTrue()
        ->and(DB::table('users')->whereNotNull('temporary_password_issued_at')->count())->toBe(1);
});

it('never marks an imported account: the school password is not a temporary one', function (): void {
    $user = H::pendingStudent()->user->fresh();

    expect(CompleteActivation::hasTemporaryPassword($user))->toBeFalse()
        ->and(CompleteActivation::mayKeepPassword($user))->toBeTrue()
        // Staff never may keep theirs, mark or no mark.
        ->and(CompleteActivation::hasTemporaryPassword($this->admin))->toBeFalse()
        ->and(CompleteActivation::mayKeepPassword($this->admin))->toBeFalse();
});

it('keeps an archived account archived when it is reset', function (): void {
    $user = H::activeStudent(['status' => UserStatus::Archived])->user;

    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Koreksi data.');

    expect($user->fresh()->status)->toBe(UserStatus::Archived);
});

it('only lets Admin reset accounts, with a reason, and never staff accounts', function (): void {
    $user = H::activeStudent()->user;

    expect(fn () => app(ResetStudentAccount::class)->handle(H::staff(RoleName::Kesiswaan), $user, 'Alasan'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResetStudentAccount::class)->handle($this->admin, $user, '   '))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ResetStudentAccount::class)->handle($this->admin, H::staff(RoleName::Guru), 'Alasan'))
        ->toThrow(ValidationException::class);
});

it('disables an account: sessions revoked, login refused, audited', function (): void {
    $user = H::activeStudent()->user;
    H::fakeSessions($user);

    app(DisableAccount::class)->handle($this->admin, $user, 'Pindah sekolah.');

    expect($user->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(H::sessionCount($user))->toBe(0)
        ->and(Activity::query()->where('event', 'account.disabled')->firstOrFail()->properties['reason'])->toBe('Pindah sekolah.');

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('archives an account by hand (D-30)', function (): void {
    $user = H::activeStudent()->user;

    app(ArchiveAccount::class)->handle($this->admin, $user, 'Lulus lebih awal.');

    expect($user->fresh()->status)->toBe(UserStatus::Archived)
        ->and(Activity::query()->where('event', 'account.archived')->exists())->toBeTrue();
});

it('refuses non-admins and self-disable', function (): void {
    $user = H::activeStudent()->user;

    expect(fn () => app(DisableAccount::class)->handle(H::staff(RoleName::Kesiswaan), $user, 'Alasan'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DisableAccount::class)->handle($this->admin, $this->admin, 'Alasan'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ArchiveAccount::class)->handle($this->admin, $user, ''))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});
