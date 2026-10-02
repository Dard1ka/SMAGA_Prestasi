<?php

declare(strict_types=1);

use App\Actions\Accounts\RequestPasswordResetCode;
use App\Actions\Accounts\ResetPasswordWithCode;
use App\Livewire\Auth\ForgotPassword;
use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use App\Notifications\Auth\PasswordWasReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

// Who gets mail (User::routeNotificationForMail) and who may ask for a reset code: a frozen account gets
// neither, and an address nobody verified only gets account notices.

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

function qFreeze(User $user): User
{
    $user->forceFill(['frozen_at' => now(), 'frozen_reason' => 'not_me'])->save();

    return $user->refresh();
}

it('routes mail to a verified, unfrozen account', function (): void {
    $user = User::factory()->create(['email' => 'verified@sth.test']);

    expect($user->routeNotificationForMail(new PasswordWasReset))->toBe('verified@sth.test')
        ->and($user->routeNotificationForMail(new StaffAccountChanged(StaffAccountChanged::RESET, 'Admin', 'Staf')))->toBe('verified@sth.test');
});

it('sends no mail at all to a frozen account', function (): void {
    $user = qFreeze(User::factory()->create(['email' => 'disputed@sth.test']));

    expect($user->routeNotificationForMail(new PasswordWasReset))->toBeNull()
        ->and($user->routeNotificationForMail(new StaffAccountChanged(StaffAccountChanged::RESET, 'Admin', 'Staf')))->toBeNull();
});

it('sends only account notices to an address nobody verified', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'typed-by-admin@sth.test']);

    expect($user->routeNotificationForMail(new PasswordWasReset))->toBeNull()
        ->and($user->routeNotificationForMail(new StaffAccountChanged(StaffAccountChanged::CREATED, 'Admin', 'Staf')))->toBe('typed-by-admin@sth.test');
});

it('treats OTP test mode (local only) as verified, so dev mail still reaches Mailpit', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'dev@sth.test']);

    $this->app['env'] = 'local';
    config(['fortify.sth.otp_test_mode' => true]);

    expect($user->routeNotificationForMail(new PasswordWasReset))->toBe('dev@sth.test');
});

it('skips the mail for an account without an email', function (): void {
    $user = User::factory()->create(['email' => null]);

    expect($user->routeNotificationForMail(new PasswordWasReset))->toBeNull();
});

it('gives a frozen account no reset code but the same generic answer', function (): void {
    $user = H::activeStudent()->user;
    qFreeze($user);

    Livewire::test(ForgotPassword::class)
        ->set('identifier', H::NISN)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    Mail::assertNotQueued(OtpCodeMail::class);
    expect(app(RequestPasswordResetCode::class)->eligibleUser(H::NISN))->toBeNull();
});

it('refuses to reset the password of a frozen account', function (): void {
    $user = H::activeStudent()->user;
    $before = $user->password;
    qFreeze($user);

    expect(fn () => app(ResetPasswordWithCode::class)->handle(H::NISN, '123456', 'Sandi-Baru-2026!', 'Sandi-Baru-2026!'))
        ->toThrow(ValidationException::class);

    expect($user->refresh()->password)->toBe($before);
    Notification::assertNothingSent();
});
