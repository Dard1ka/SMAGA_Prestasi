<?php

declare(strict_types=1);

use App\Actions\Accounts\ChangePassword;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\RequestPasswordResetCode;
use App\Actions\Accounts\ResetCodeLimits;
use App\Actions\Accounts\VerifyOtpCode;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\KnownDevices;
use App\Actions\Fortify\LoginAttackAlert;
use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginLockout;
use App\Enums\RoleName;
use App\Mail\OtpCodeMail;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10, second review of the auth fixes. A classmate behind the same school address could still keep the owner's
| known device out (the address lock and limits were shared by every device); an identifier sent as a list gave a
| server error; a failing alert could cost the lock; clearing an account's locks left the "Lupa kata sandi" limits;
| the per-address code limit was 15 minutes instead of an hour; an account could take 30 reset codes (150 guesses) a
| day without an alert; the attack alerts had no ceiling; a password change made the owner's own browser unknown; and
| refused tries counted towards nothing, so one address could send them without end. Fictional data only.
*/

uses(RefreshDatabase::class);

const QA10_AUTH_H_NAT = '10.90.0.1';

const QA10_AUTH_H_WRONG = 'salah-sekali-123';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

function qa10AuthHLogin(string $ip, string $identifier, string $password = H::PASSWORD): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from('/login')
        ->post('/login', ['identifier' => $identifier, 'password' => $password]);
}

function qa10AuthHShown(TestResponse $response): string
{
    $response->assertSessionHasErrors('identifier');

    return (string) session('errors')->first('identifier');
}

/** The owner signs in once at $ip; the browser keeps the known-device cookie from then on. */
function qa10AuthHKnownDevice(string $ip): string
{
    $device = qa10AuthHLogin($ip, H::NISN)->assertRedirect(route('home'))->getCookie(KnownDevices::COOKIE);
    expect($device)->not->toBeNull();
    Auth::guard('web')->logout();

    return (string) $device->getValue();
}

function qa10AuthHAt(int $start, int $second): void
{
    test()->travelTo(now()->setTimestamp($start + $second));
}

// 1. The address lock and limits belong to the kind of device too.

it('keeps the known device of the owner in at a shared address while a stranger there fails fast (QA10)', function (): void {
    $student = H::activeStudent();
    $device = qa10AuthHKnownDevice(QA10_AUTH_H_NAT);

    foreach (range(1, 5) as $i) {
        qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN, QA10_AUTH_H_WRONG)->assertSessionHasErrors('identifier');
    }

    expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)))->toStartWith('Terlalu banyak percobaan');

    $this->withCookie(KnownDevices::COOKIE, $device);
    qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('keeps the known device of the owner in after a stranger at that address failed 20 times (QA10)', function (): void {
    $student = H::activeStudent();
    $device = qa10AuthHKnownDevice(QA10_AUTH_H_NAT);

    foreach (range(1, 20) as $i) {
        qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN, QA10_AUTH_H_WRONG)->assertSessionHasErrors('identifier');
        $this->travel(16)->minutes();
    }

    expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)))->toStartWith('Terlalu banyak percobaan');

    $this->withCookie(KnownDevices::COOKIE, $device);
    qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('still locks the known device after its own wrong passwords (QA10)', function (): void {
    H::activeStudent();
    $this->withCookie(KnownDevices::COOKIE, qa10AuthHKnownDevice(QA10_AUTH_H_NAT));

    foreach (range(1, 5) as $i) {
        qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN, QA10_AUTH_H_WRONG)->assertSessionHasErrors('identifier');
    }

    expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();
});

// 2. An identifier sent as a list.

it('answers an identifier sent as a list with a validation error, not a server error (QA10)', function (): void {
    H::activeStudent();

    $this->from('/login')->post('/login', ['identifier' => [H::NISN], 'password' => H::PASSWORD])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('identifier');

    $this->assertGuest();
});

// 3. The lock first, then the alert, which may fail.

it('locks and answers the 30th failure as usual when the alert cannot be sent (QA10)', function (): void {
    H::activeStudent();
    $this->app->bind(LoginAttackAlert::class, fn () => throw new RuntimeException('Alert channel down (test).'));
    Log::spy();

    foreach (range(1, 25) as $i) {
        qa10AuthHLogin('10.92.0.'.$i, H::NISN, QA10_AUTH_H_WRONG)->assertSessionHasErrors('identifier');
    }

    // The 30th failure is the 5th from one address: it starts that address's lock.
    foreach (range(1, 5) as $i) {
        qa10AuthHLogin('10.93.0.1', H::NISN, QA10_AUTH_H_WRONG)
            ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'security.login_lockout'
            && $context['failures'] === 5 && $context['account_failures'] === 30)
        ->once();
});

// 4. clear() also releases the "Lupa kata sandi" limits.

it('releases the "Lupa kata sandi" limits of the account when its locks are cleared (QA10)', function (): void {
    $student = H::activeStudent();
    $limits = app(ResetCodeLimits::class);

    foreach (range(1, 3) as $i) {
        expect($limits->hit(ResetCodeLimits::SEND, H::NISN, '10.66.0.1'))->toBeNull();
    }

    expect($limits->hit(ResetCodeLimits::SEND, H::NISN, '10.66.0.1'))->toBeGreaterThan(0);

    app(LoginLockout::class)->clear(LoginIdentifier::accountBucket($student->user));

    expect($limits->hit(ResetCodeLimits::SEND, H::NISN, '10.66.0.1'))->toBeNull();
});

// 5. SECURITY §3b: code requests 30 an hour per address.

it('keeps the per-address limit of code requests for an hour (QA10)', function (): void {
    $limits = app(ResetCodeLimits::class);

    foreach (range(1, 30) as $i) {
        expect($limits->hit(ResetCodeLimits::SEND, '9100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '10.66.0.2'))->toBeNull();
    }

    $this->travel(901)->seconds();
    expect($limits->hit(ResetCodeLimits::SEND, '9200000001', '10.66.0.2'))->toBeGreaterThan(2600);

    $this->travel(2700)->seconds();
    expect($limits->hit(ResetCodeLimits::SEND, '9200000001', '10.66.0.2'))->toBeNull();
});

// 6. An alert when the wrong reset codes of an account pile up.

it('alerts the Admins once when the wrong reset codes of an account reach 20 in a day (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $admin = H::staff(RoleName::Admin);
    $issue = app(IssueOtpCode::class);
    $verify = app(VerifyOtpCode::class);
    $purpose = RequestPasswordResetCode::PURPOSE;

    foreach (range(1, 5) as $browser) {
        $issue->handle($user, $purpose, 'nadia.anindya@example.test', 'browser-'.$browser);
        $right = (string) Mail::queued(OtpCodeMail::class)->last()?->code;
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $i) {
            expect($verify->handle($user, $purpose, $wrong, false, 'browser-'.$browser))->toBeNull();
        }
    }

    Notification::assertSentToTimes($admin, AccountAlert::class, 1);
    Notification::assertSentTo($admin, AccountAlert::class, fn (AccountAlert $alert): bool => $alert->kind === 'account.reset_code_attack'
        && str_contains(implode(' ', $alert->lines), '20 kali'));
});

// 7. A ceiling for the attack alerts.

it('sends at most 5 attack alerts an hour and logs the ones it holds back (QA10)', function (): void {
    $admin = H::staff(RoleName::Admin);
    $accounts = Student::factory()->count(7)->create()->map(fn (Student $student): User => $student->user);
    Log::spy();

    foreach ($accounts as $account) {
        app(LoginAttackAlert::class)->handle($account, 30);
    }

    Notification::assertSentToTimes($admin, AccountAlert::class, 5);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'security.login_attack_alerts_capped'
            && ($context['held_back'] ?? null) === 2)
        ->once();

    $this->travel(61)->minutes();
    app(LoginAttackAlert::class)->handle($accounts->first(), 30);
    Notification::assertSentToTimes($admin, AccountAlert::class, 6);
});

// 8. A password change in the owner's own browser keeps that browser known.

it('keeps the browser known after the owner changes the password while signed in (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $this->actingAs($user);

    app(ChangePassword::class)->handle($user, H::PASSWORD, 'Hujan-bulan-juni-26', session()->getId());

    $cookie = Cookie::queued(KnownDevices::COOKIE);
    expect($cookie)->not->toBeNull();

    $request = Request::create('/login', 'POST', [], [KnownDevices::COOKIE => (string) $cookie->getValue()]);
    expect(KnownDevices::recognizes($request, User::query()->findOrFail($user->getKey())))->toBeTrue();
});

// 9. Refused tries count against the address limit only.

it('counts refused tries against the address, while the locked identifier still counts down (QA10)', function (): void {
    H::activeStudent();
    H::staff(RoleName::Guru, ['username' => 'bu.dwi']);
    $start = now()->getTimestamp();

    foreach (range(0, 4) as $second) {
        qa10AuthHAt($start, $second);
        qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN, QA10_AUTH_H_WRONG);
    }

    foreach (range(65, 69) as $second) {
        qa10AuthHAt($start, $second);
        qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN, QA10_AUTH_H_WRONG);
    }

    // Locked until second 969. Sixty refused tries in one second from this address.
    qa10AuthHAt($start, 70);
    foreach (range(1, 60) as $i) {
        expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)))->toBe(AppServiceProvider::retryMessage(899));
    }

    // Another account at the same address now waits for the address window (from second 65): 54 seconds.
    qa10AuthHAt($start, 71);
    expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, 'bu.dwi')))->toBe(AppServiceProvider::retryMessage(54));

    qa10AuthHAt($start, 80);
    expect(qa10AuthHShown(qa10AuthHLogin(QA10_AUTH_H_NAT, H::NISN)))->toBe(AppServiceProvider::retryMessage(889));

    qa10AuthHAt($start, 126);
    qa10AuthHLogin(QA10_AUTH_H_NAT, 'bu.dwi')->assertRedirect(route('home'));
});
