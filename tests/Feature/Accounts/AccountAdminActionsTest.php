<?php

declare(strict_types=1);

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Fortify\AuthenticateUser;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
