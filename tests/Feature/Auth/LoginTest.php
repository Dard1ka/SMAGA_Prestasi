<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Fortify\AuthenticateUser;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

function w1cLogin(string $identifier, string $password = H::PASSWORD): TestResponse
{
    return test()->from('/login')->post('/login', ['identifier' => $identifier, 'password' => $password]);
}

it('logs a student in with the NIS and sends a first login to activation', function (): void {
    $student = H::pendingStudent();

    w1cLogin(H::NIS)->assertRedirect(route('aktivasi'));

    $this->assertAuthenticatedAs($student->user);
    expect($student->user->fresh()->last_login_at)->not->toBeNull();
});

it('never issues a remember-me cookie', function (): void {
    H::activeStudent();

    $response = $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD, 'remember' => 'on']);

    $response->assertRedirect(route('home'));
    expect(collect($response->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web_')))->toBeFalse();
});

it('logs a student in with the NISN', function (): void {
    $student = H::activeStudent();

    w1cLogin(H::NISN)->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($student->user);
});

it('ignores surrounding spaces in the identifier', function (): void {
    $student = H::pendingStudent();

    w1cLogin('  '.H::NIS.' ')->assertRedirect(route('aktivasi'));

    $this->assertAuthenticatedAs($student->user);
});

it('logs staff in with their email and username, but never with a NIS-like number', function (): void {
    $guru = H::staff(RoleName::Guru, ['username' => 'bu.dwi']);

    w1cLogin('STAF.GURU@example.test')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($guru);

    auth()->logout();

    w1cLogin('bu.dwi')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($guru);
});

// M1-B (lead, 2026-10-04): a student may also log in with the email bound and verified during activation.

it('logs an activated student in with the bound email, whatever the letter case', function (): void {
    $student = H::activeStudent();

    w1cLogin('  Nadia.Anindya@Example.test ')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($student->user);
    expect($student->user->fresh()->last_login_at)->not->toBeNull();
});

it('answers a student email with the generic error until the activation is finished', function (array $state): void {
    $student = H::pendingStudent();
    $student->user->forceFill(array_merge(['email' => 'nadia.anindya@example.test'], $state))->save();

    // The right password does not help, and the answer is the one of a wrong password or an unknown address.
    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    w1cLogin('nadia.anindya@example.test', 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    w1cLogin('tidak.ada@example.test')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();

    // A refused email never counts as a failed login on the pending account (K3) and never ends its sessions.
    expect(AccountControls::failedAttempts($student->user->fresh()))->toBe(0);
})->with([
    'address typed, code not entered yet' => [['email_verified_at' => null]],
    'email verified, password step still open' => [['email_verified_at' => '2026-10-01 08:00:00']],
    'password chosen in the old order, email not verified' => [['email_verified_at' => null, 'must_change_password' => false]],
    'active but the address is unverified (bound in OTP test mode)' => [['email_verified_at' => null, 'must_change_password' => false, 'status' => UserStatus::Active]],
]);

it('refuses archived and disabled accounts by email too', function (UserStatus $status): void {
    H::activeStudent(['status' => $status]);

    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
})->with([UserStatus::Archived, UserStatus::Disabled]);

it('refuses a frozen student by email with the generic error', function (): void {
    H::activeStudent(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME]);

    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
});

it('never lets a student log in with a username', function (): void {
    H::activeStudent(['username' => 'nadia.pa']);

    w1cLogin('nadia.pa')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
});

it('counts NIS, NISN and email of one student in the same rate-limit bucket', function (): void {
    H::activeStudent();

    foreach ([H::NIS, 'nadia.anindya@example.test', H::NISN, 'NADIA.ANINDYA@example.test', H::NIS] as $identifier) {
        w1cLogin($identifier, 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    }

    // Sixth attempt in the same minute is blocked even with the right password, by any identifier.
    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors('identifier');

    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();
});

it('locks the account progressively for failures by email at their address, also for the NIS (QA10: per address)', function (): void {
    H::activeStudent();

    // One address, 13 s apart: when the sixth try comes the per-minute limiter has started a new window, so only the
    // lockout answers it. QA10 (K3): the lockout is per account AND address, no longer per account alone.
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->from('/login')->post('/login', ['identifier' => 'nadia.anindya@example.test', 'password' => 'salah-sekali-123']);
        $this->travel(13)->seconds();
    }

    // The lock holds for the NIS as well: one account, one bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertSessionHasErrors('identifier');

    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();

    // Another address is not held up by these failures.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
    auth()->logout();

    $this->travel(61)->seconds();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->from('/login')->post('/login', ['identifier' => 'nadia.anindya@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('home'));
});

it('limits an email that resolves to nobody like any unknown identifier', function (): void {
    // Bound, but the activation is not finished: for the limiter this address belongs to nobody.
    $student = H::pendingStudent();
    $student->user->forceFill(['email' => 'nadia.anindya@example.test', 'email_verified_at' => now()])->save();

    foreach (range(1, 5) as $i) {
        w1cLogin('nadia.anindya@example.test', 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    }

    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors('identifier');
    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');

    // The account's own bucket is untouched: the owner still gets in with the NIS.
    w1cLogin(H::NIS)->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($student->user);
});

it('never sends a student who logs in by email to the TOTP challenge (D-66)', function (): void {
    $student = H::activeStudent();
    $student->user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    w1cLogin('nadia.anindya@example.test')->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($student->user);
});

it('keeps the staff login as it was: email works before activation, and leads to the activation screen', function (): void {
    $staff = H::staff(RoleName::Guru, [
        'username' => 'bu.dwi',
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'email_verified_at' => null,
    ]);

    w1cLogin('staf.guru@example.test')->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($staff);
});

it('shows one generic error for a wrong password and an unknown identifier', function (): void {
    H::pendingStudent();

    w1cLogin(H::NIS, 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    w1cLogin('999999999', 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
});

it('refuses archived and disabled accounts with the generic error and revokes their sessions', function (UserStatus $status): void {
    config(['session.driver' => 'database']);

    $student = H::activeStudent(['status' => $status]);
    H::fakeSessions($student->user);

    w1cLogin(H::NIS)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
    expect(H::sessionCount($student->user))->toBe(0);
})->with([UserStatus::Archived, UserStatus::Disabled]);

it('logs out a signed-in user whose account was archived meanwhile', function (): void {
    Route::middleware('web')->get('/_test/ping', fn () => 'ok');

    $student = H::activeStudent();
    $this->actingAs($student->user);

    $student->user->forceFill(['status' => UserStatus::Archived])->save();

    $this->get('/_test/ping')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('rate limits one account bucket, shared by NIS and NISN', function (): void {
    H::pendingStudent();

    foreach ([H::NIS, H::NISN, H::NIS, H::NISN, H::NIS] as $identifier) {
        w1cLogin($identifier, 'salah-sekali-123')->assertSessionHasErrors('identifier');
    }

    // Sixth attempt in the same minute is blocked even with the right password.
    $response = w1cLogin(H::NISN);

    $response->assertSessionHasErrors('identifier');
    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();
});

it('locks the account bucket progressively after repeated failures at their address (QA10: per address)', function (): void {
    H::pendingStudent();

    // One address, 13 s apart, so only the lockout (per account AND address since QA10) answers the sixth try.
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => 'salah-sekali-123']);
        $this->travel(13)->seconds();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertSessionHasErrors('identifier');

    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();

    // Another address is not held up by these failures.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertRedirect(route('aktivasi'));
    auth()->logout();

    $this->travel(61)->seconds();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertRedirect(route('aktivasi'));
});

it('flags the initial password pattern tried on an already activated account (K7) without logging in', function (): void {
    Log::spy();

    $student = H::activeStudent();
    $student->user->forceFill(['password' => Hash::make('Kata-sandi-baru-2026')])->save();

    // Fictional birth date 2010-03-02 → pattern built by the domain class, never by auth code.
    $pattern = app(InitialPassword::class)->make('2010-03-02');

    w1cLogin(H::NIS, $pattern)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'security.initial_password_on_active')->once();
});

it('sends staff with confirmed 2FA to the TOTP challenge', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $staff->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    w1cLogin('staf.kesiswaan@example.test')->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('logs out through Fortify', function (): void {
    $student = H::activeStudent();

    $this->actingAs($student->user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
