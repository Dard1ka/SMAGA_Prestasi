<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Fortify\AuthenticateUser;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\Student;
use App\Models\TalentApplication;
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

// B1 — K10; the lead on 2026-10-21 (replacing the password-only reset of D-158): "Reset kata sandi & email" is for a
// taken-over account. The Admin types the temporary password, the email goes and the student activates again; the
// Data diri and the talent choices stay.
it('resets a hijacked student account to the typed temporary password and removes the email', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['phone' => '081234567890', 'profile_confirmed_at' => now()])->save();
    $user = $student->user;
    $user->forceFill([
        'password' => Hash::make('Dipakai-penyerang-99'), 'activated_at' => now()->subDays(3),
        'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME,
    ])->save();
    $talent = TalentApplication::factory()->create(['student_id' => $student->getKey()]);
    H::fakeSessions($user, 3);

    $otp = new OtpCode;
    $otp->forceFill([
        'user_id' => $user->getKey(), 'purpose' => 'reset_password', 'email' => 'x@example.test',
        'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5),
    ])->save();

    $this->travelTo(now()->startOfSecond());
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Siswa datang ke TU, akun dibajak.', 'sementara8');

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->activated_at)->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->password_changed_at)->toBeNull()
        ->and(AccountControls::date($user, 'initial_password_expires_at')?->equalTo(now()->addHours(72)))->toBeTrue()
        ->and(AccountControls::isFrozen($user))->toBeFalse()
        ->and(Hash::check('Dipakai-penyerang-99', $user->password))->toBeFalse()
        ->and(Hash::check('sementara8', $user->password))->toBeTrue()
        ->and(H::sessionCount($user))->toBe(0)
        ->and($otp->fresh()->used_at)->not->toBeNull()
        // Data diri and talents stay, so the next login asks the email (then code and password), never Data diri.
        ->and($student->fresh()->phone)->toBe('081234567890')
        ->and($student->fresh()->profile_confirmed_at)->not->toBeNull()
        ->and($talent->fresh())->not->toBeNull()
        ->and(DetermineActivationStep::profileOpen($user))->toBeFalse()
        ->and(app(DetermineActivationStep::class)($user))->toBe(DetermineActivationStep::EMAIL);

    $log = Activity::query()->where('event', 'account.reset')->firstOrFail();
    expect($log->properties['reason'])->toBe('Siswa datang ke TU, akun dibajak.')
        ->and($log->properties['email_detached'])->toBeTrue()
        ->and($log->properties['unfrozen'])->toBe(AccountControls::FROZEN_NOT_ME)
        ->and($log->causer_id)->toBe($this->admin->getKey())
        ->and($log->subject_id)->toBe((string) $user->getKey())
        // The password is never written anywhere but as a hash.
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('sementara8');
});

it('takes any temporary password of at least 8 characters and refuses a shorter one together with the reason', function (): void {
    $user = H::activeStudent()->user;
    $before = $user->password;

    try {
        app(ResetStudentAccount::class)->handle($this->admin, $user, '  ', 'pendek7');
        $this->fail('A 7-character temporary password was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'reason' => [ResetStudentAccount::MSG_REASON],
            'password' => [ResetStudentAccount::MSG_PASSWORD],
        ])->and(json_encode($e->errors()))->not->toContain('pendek7');
    }

    expect($user->fresh()->password)->toBe($before)
        ->and($user->fresh()->email)->not->toBeNull();

    // No complexity rule: eight plain lowercase letters are fine for a temporary password.
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Akun diambil alih.', 'abcdefgh');
    expect(Hash::check('abcdefgh', $user->fresh()->password))->toBeTrue();
});

// M2-A (QA 2026-10-05, Q2-02): the reset marks the password as one an Admin has seen; only a password the owner
// chooses takes the mark away.
it('marks the temporary password of a reset until the owner chooses a new one', function (): void {
    $user = H::activeStudent()->user;

    expect(CompleteActivation::hasTemporaryPassword($user->fresh()))->toBeFalse()
        ->and(AccountControls::date($user->fresh(), 'temporary_password_issued_at'))->toBeNull();

    $this->travelTo(now()->startOfSecond());
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Siswa lupa kata sandi.', 'sementara8');

    $user->refresh();
    expect(CompleteActivation::hasTemporaryPassword($user))->toBeTrue()
        ->and(AccountControls::date($user, 'temporary_password_issued_at')->equalTo(now()))->toBeTrue()
        // Never mass-assignable.
        ->and($user->isFillable('temporary_password_issued_at'))->toBeFalse();

    // A second reset keeps the mark, with the new time.
    $this->travel(5)->minutes();
    app(ResetStudentAccount::class)->handle($this->admin, $user, 'Kata sandi sementara hilang.', 'sementara8');

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
    app(ResetStudentAccount::class)->handle($this->admin, $reset, 'Reset sebelum M2-A.', 'sementara8');

    // Reset as well, but the owner chose a password afterwards.
    $changed = Student::factory()->create()->user;
    app(ResetStudentAccount::class)->handle($this->admin, $changed, 'Reset sebelum M2-A.', 'sementara8');
    app(SetNewPassword::class)->handle($changed->fresh(), 'Pelangi-senja-2026');

    // Imported, never reset: the school password.
    $imported = Student::factory()->create()->user;

    $this->travelBack();

    // As the rows were before the migration: nobody is marked.
    DB::table('users')->update(['temporary_password_issued_at' => null]);

    $migration = require database_path('migrations/2026_10_06_100000_m2a_add_temporary_password_issued_at_to_users.php');
    $migration->backfill();

    expect(AccountControls::date($reset->fresh(), 'temporary_password_issued_at')?->equalTo($resetAt))->toBeTrue()
        ->and(CompleteActivation::hasTemporaryPassword($changed->fresh()))->toBeFalse()
        ->and(CompleteActivation::hasTemporaryPassword($imported->fresh()))->toBeFalse()
        ->and(DB::table('users')->whereNotNull('temporary_password_issued_at')->count())->toBe(1);
});

it('never marks an imported account: the school password is not a temporary one', function (): void {
    $user = H::pendingStudent()->user->fresh();

    expect(CompleteActivation::hasTemporaryPassword($user))->toBeFalse()
        ->and(CompleteActivation::hasTemporaryPassword($this->admin))->toBeFalse();
});

it('keeps an archived account archived: it is not reset at all', function (): void {
    $user = H::activeStudent(['status' => UserStatus::Archived])->user;

    // QA9: an account that cannot log in is refused (AccountsQa9Test), it used to get a useless temporary password.
    expect(fn () => app(ResetStudentAccount::class)->handle($this->admin, $user, 'Koreksi data.', 'sementara8'))->toThrow(ValidationException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Archived);
});

it('only lets Admin reset accounts, with a reason, and never staff accounts', function (): void {
    $user = H::activeStudent()->user;

    expect(fn () => app(ResetStudentAccount::class)->handle(H::staff(RoleName::Kesiswaan), $user, 'Alasan', 'sementara8'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResetStudentAccount::class)->handle($this->admin, $user, '   ', 'sementara8'))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ResetStudentAccount::class)->handle($this->admin, H::staff(RoleName::Guru), 'Alasan', 'sementara8'))
        ->toThrow(ValidationException::class);
});

it('disables an account: sessions revoked, login refused, audited', function (): void {
    $user = H::activeStudent()->user;
    H::fakeSessions($user);

    app(DisableAccount::class)->handle($this->admin, $user, 'Pindah sekolah.');

    expect($user->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(H::sessionCount($user))->toBe(0)
        ->and(Activity::query()->where('event', 'account.disabled')->firstOrFail()->properties['reason'])->toBe('Pindah sekolah.');

    $this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])
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
