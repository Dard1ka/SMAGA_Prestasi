<?php

declare(strict_types=1);

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

it('does not let a student log in with an email', function (): void {
    H::activeStudent();

    w1cLogin('nadia.anindya@example.test')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
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

it('locks the account bucket progressively after repeated failures', function (): void {
    H::pendingStudent();

    // Different IPs so only the per-account lockout (not the per identifier+IP limiter) applies.
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])
            ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => 'salah-sekali-123']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
        ->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])
        ->assertSessionHasErrors('identifier');

    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();

    $this->travel(61)->seconds();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
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
