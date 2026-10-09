<?php

declare(strict_types=1);

use App\Actions\Accounts\ResetStudentAccount;
use App\Enums\RoleName;
use App\Livewire\Auth\ForgotPassword;
use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 item 1 (SECURITY K3: an active account is never locked on purpose by a classmate). Whoever knew a student's NIS
| could keep the account locked: the hourly limit per account counted every request, correct passwords included, and
| the progressive lock was per account, so one wrong try an hour from anywhere kept the owner out. Now the blocking
| limits are per account AND address and count failed passwords only; the per-account total only feeds the alerts.
| A reset with the code from the email and an Admin reset clear every limit of the account. Fictional data only.
*/

uses(RefreshDatabase::class);

const QA10_AUTH_ATTACKER_IP = '10.66.0.1';

const QA10_AUTH_OWNER_IP = '10.20.0.1';

const QA10_AUTH_WRONG = 'salah-sekali-123';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

function qa10AuthLogin(string $ip, string $identifier, string $password = H::PASSWORD): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from('/login')
        ->post('/login', ['identifier' => $identifier, 'password' => $password]);
}

/** $times wrong passwords from $ip, $spacing seconds apart (never faster than the per-minute limiter allows). */
function qa10AuthFailFrom(string $ip, int $times, int $spacing, string $identifier = H::NIS): void
{
    foreach (range(1, $times) as $i) {
        qa10AuthLogin($ip, $identifier, QA10_AUTH_WRONG)->assertSessionHasErrors('identifier');
        test()->assertGuest();
        test()->travel($spacing)->seconds();
    }
}

function qa10AuthAssertLimited(TestResponse $response): void
{
    $response->assertSessionHasErrors('identifier');
    expect(session('errors')->first('identifier'))->toStartWith('Terlalu banyak percobaan');
    test()->assertGuest();
}

function qa10AuthResetByCode(string $identifier, string $newPassword): void
{
    $component = Livewire::test(ForgotPassword::class)
        ->set('identifier', $identifier)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)
        ->set('password', $newPassword)
        ->set('password_confirmation', $newPassword)
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));
}

it('lets the owner in from another address while an attacker keeps failing, and keeps the attacker limited (QA10)', function (): void {
    $student = H::activeStudent();

    // 25 wrong passwords over almost seven hours: more than 20 real failures on the account.
    qa10AuthFailFrom(QA10_AUTH_ATTACKER_IP, 25, 16 * 60);

    qa10AuthLogin(QA10_AUTH_OWNER_IP, H::NIS)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);

    Auth::guard('web')->logout();

    // The attacker's own address stays limited, even with the right password.
    qa10AuthAssertLimited(qa10AuthLogin(QA10_AUTH_ATTACKER_IP, H::NIS));
});

it('still reports failures spread over many addresses to the security log, without locking the account (QA10)', function (): void {
    Log::spy();
    $student = H::activeStudent();

    foreach (range(1, 5) as $i) {
        qa10AuthLogin('10.66.1.'.$i, H::NIS, QA10_AUTH_WRONG)->assertSessionHasErrors('identifier');
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'security.login_failures_account'
            && $context['bucket'] === 'u:'.$student->user->getKey() && $context['failures'] === 5)
        ->once();

    qa10AuthLogin(QA10_AUTH_OWNER_IP, H::NIS)->assertRedirect(route('home'));
});

it('counts only failed passwords: correct logins never fill the hourly limit of an account (QA10)', function (): void {
    $student = H::activeStudent();

    foreach (range(1, 25) as $i) {
        qa10AuthLogin(QA10_AUTH_OWNER_IP, $i % 2 === 0 ? H::NIS : H::NISN)->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($student->user);

        Auth::guard('web')->logout();
        $this->travel(61)->seconds();
    }
});

it('unlocks the account after a reset with the code from the email (QA10)', function (): void {
    $student = H::activeStudent();

    // Owner and attacker behind the same school address: 20 failures lock that address for an hour.
    qa10AuthFailFrom(QA10_AUTH_ATTACKER_IP, 20, 16 * 60);
    qa10AuthAssertLimited(qa10AuthLogin(QA10_AUTH_ATTACKER_IP, H::NIS));

    qa10AuthResetByCode(H::NISN, 'Hujan-bulan-juni-26');

    qa10AuthLogin(QA10_AUTH_ATTACKER_IP, H::NIS, 'Hujan-bulan-juni-26')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('clears the limits of every address of the account on an Admin reset (QA10)', function (): void {
    $student = H::activeStudent();
    $admin = H::staff(RoleName::Admin);

    // 20 tries within half an hour from one address: the hourly limit of that address is full.
    qa10AuthFailFrom(QA10_AUTH_ATTACKER_IP, 20, 61);
    qa10AuthAssertLimited(qa10AuthLogin(QA10_AUTH_ATTACKER_IP, H::NIS));

    $temporary = app(ResetStudentAccount::class)->handle($admin, $student->user, 'Pemilik datang ke ruang TU dengan kartu pelajar.');

    qa10AuthLogin(QA10_AUTH_ATTACKER_IP, H::NIS, $temporary)->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs(User::query()->findOrFail($student->user->getKey()));
});

// Review of QA10: with every limit keyed on account + address, nothing capped the guesses on one account from many
// addresses. Now 30 failures in 24 h close the account to devices that never signed in to it (the owner's own devices
// still get in), the Admins are alerted, and an IPv6 address counts as its /64.

/** One wrong password from each of $count addresses (no address is limited on its own). */
function qa10AuthFailFromMany(int $count, string $prefix = '10.70'): void
{
    foreach (range(1, $count) as $i) {
        qa10AuthLogin($prefix.'.'.intdiv($i, 200).'.'.($i % 200 + 1), H::NIS, QA10_AUTH_WRONG)->assertSessionHasErrors('identifier');
    }
}

it('closes the account to unknown devices after 30 failed logins in a day from many addresses (QA10)', function (): void {
    $student = H::activeStudent();

    qa10AuthFailFromMany(30);

    // A device and an address the account never used: refused, even with the right password.
    qa10AuthAssertLimited(qa10AuthLogin('10.71.0.1', H::NIS));

    // "Lupa kata sandi" is the owner's way back on a new device.
    qa10AuthResetByCode(H::NISN, 'Hujan-bulan-juni-26');

    qa10AuthLogin('10.71.0.2', H::NIS, 'Hujan-bulan-juni-26')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('lets the owner in from a device that signed in before while the account is closed to others (QA10)', function (): void {
    $student = H::activeStudent();

    $device = qa10AuthLogin(QA10_AUTH_OWNER_IP, H::NIS)->assertRedirect(route('home'))->getCookie('sth_device');
    expect($device)->not->toBeNull()
        ->and($device->isHttpOnly())->toBeTrue();
    Auth::guard('web')->logout();

    qa10AuthFailFromMany(30);
    qa10AuthAssertLimited(qa10AuthLogin('10.71.0.1', H::NIS));

    // The owner's device, from yet another address (a phone on mobile data).
    $this->withCookie('sth_device', (string) $device->getValue());
    qa10AuthLogin('10.71.0.3', H::NIS)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('does not take a device of one account as known for another account (QA10)', function (): void {
    H::activeStudent();
    H::staff(RoleName::Guru, ['username' => 'bu.dwi']);

    $device = qa10AuthLogin(QA10_AUTH_OWNER_IP, 'bu.dwi')->assertRedirect(route('home'))->getCookie('sth_device');
    Auth::guard('web')->logout();

    qa10AuthFailFromMany(30);

    $this->withCookie('sth_device', (string) $device?->getValue());
    qa10AuthAssertLimited(qa10AuthLogin('10.71.0.4', H::NIS));
});

it('alerts the Admins once when an account reaches 30 failed logins in a day (QA10)', function (): void {
    H::activeStudent();
    $admin = H::staff(RoleName::Admin);

    qa10AuthFailFromMany(31);

    Notification::assertSentToTimes($admin, AccountAlert::class, 1);
    Notification::assertSentTo($admin, AccountAlert::class, fn (AccountAlert $alert): bool => $alert->kind === 'account.login_attack'
        && str_contains(implode(' ', $alert->lines), '30 kali'));
});

it('counts the addresses of one IPv6 /64 as one address (QA10)', function (): void {
    $student = H::activeStudent();

    foreach (range(1, 5) as $i) {
        qa10AuthLogin('2001:db8:1:2::'.$i, H::NIS, QA10_AUTH_WRONG)->assertSessionHasErrors('identifier');
    }

    qa10AuthAssertLimited(qa10AuthLogin('2001:db8:1:2::99', H::NIS));

    // Another /64 is another address.
    qa10AuthLogin('2001:db8:1:3::1', H::NIS)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});
