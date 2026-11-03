<?php

declare(strict_types=1);

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\RequestPasswordResetCode;
use App\Enums\UserStatus;
use App\Livewire\Auth\ForgotPassword;
use App\Mail\OtpCodeMail;
use App\Notifications\Auth\PasswordWasReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

function w1cRequestCode(string $identifier): mixed
{
    return Livewire::test(ForgotPassword::class)
        ->set('identifier', $identifier)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
}

it('resets a student password with the code sent to the bound email', function (): void {
    config(['session.driver' => 'database']);

    $user = H::activeStudent()->user;
    H::fakeSessions($user, 3);

    $component = w1cRequestCode(H::NISN);

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('nadia.anindya@example.test') && $mail->purpose === 'reset_password';
    });

    $component->set('code', $code)
        ->set('password', 'Hujan-bulan-juni-26')
        ->set('password_confirmation', 'Hujan-bulan-juni-26')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('Hujan-bulan-juni-26', $user->fresh()->password))->toBeTrue()
        ->and(H::sessionCount($user))->toBe(0);

    Notification::assertSentTo($user, PasswordWasReset::class);
});

it('answers the same way for unknown or unusable identifiers and sends nothing', function (string $identifier): void {
    H::pendingStudent(); // no bound email yet

    w1cRequestCode($identifier)->assertSee(RequestPasswordResetCode::GENERIC_STATUS);

    Mail::assertNothingQueued();
})->with([
    'unknown number' => ['123456789'],
    'account without email' => [H::NISN],
]);

it('rejects a wrong code without revealing anything and keeps the old password', function (): void {
    $user = H::activeStudent()->user;

    w1cRequestCode(H::NISN)
        ->set('code', '000000')
        ->set('password', 'Hujan-bulan-juni-26')
        ->set('password_confirmation', 'Hujan-bulan-juni-26')
        ->call('resetPassword')
        ->assertHasErrors(['code']);

    expect(Hash::check(H::PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('gives the same code error for an identifier without an account', function (): void {
    w1cRequestCode('123456789')
        ->set('code', '123456')
        ->set('password', 'Hujan-bulan-juni-26')
        ->set('password_confirmation', 'Hujan-bulan-juni-26')
        ->call('resetPassword')
        ->assertHasErrors(['code']);
});

it('does not reset archived accounts', function (): void {
    H::activeStudent(['status' => UserStatus::Archived]);

    w1cRequestCode(H::NISN);
    w1cRequestCode('nadia.anindya@example.test');

    Mail::assertNothingQueued();
});

// M1-B (lead, 2026-10-04): the student's own email is accepted here as well, same neutral answer.

it('resets a student password when the bound email is typed instead of the NISN', function (): void {
    $user = H::activeStudent()->user;

    $component = w1cRequestCode(' Nadia.Anindya@example.test')->assertSee(RequestPasswordResetCode::GENERIC_STATUS);

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('nadia.anindya@example.test') && $mail->purpose === 'reset_password';
    });

    $component->set('code', $code)
        ->set('password', 'Hujan-bulan-juni-26')
        ->set('password_confirmation', 'Hujan-bulan-juni-26')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('Hujan-bulan-juni-26', $user->fresh()->password))->toBeTrue();
});

it('gives the neutral answer and sends nothing for a student email before the activation is finished', function (): void {
    // Email bound and verified, password step still open: the address is not a login identifier yet.
    $student = H::pendingStudent();
    $student->user->forceFill(['email' => 'nadia.anindya@example.test', 'email_verified_at' => now()])->save();

    w1cRequestCode('nadia.anindya@example.test')->assertSee(RequestPasswordResetCode::GENERIC_STATUS);
    w1cRequestCode('tidak.ada@example.test')->assertSee(RequestPasswordResetCode::GENERIC_STATUS);

    Mail::assertNothingQueued();
});

it('closes a half-finished activation when the password is reset with the NISN', function (): void {
    // The student bound the email and saved Data diri (D-152), left before the password step and then used "Lupa
    // kata sandi". With Data diri still open the account stays pending (ActivationProfileTest).
    $student = H::pendingStudent(['phone' => '081234567890', 'profile_confirmed_at' => now()]);
    $user = $student->user;
    $user->forceFill([
        'email' => 'nadia.anindya@example.test',
        'email_verified_at' => now(),
        'initial_password_expires_at' => now()->subDay(),
    ])->save();

    $component = w1cRequestCode(H::NISN);

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('nadia.anindya@example.test');
    });

    $component->set('code', $code)
        ->set('password', 'Hujan-bulan-juni-26')
        ->set('password_confirmation', 'Hujan-bulan-juni-26')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->activated_at)->not->toBeNull()
        ->and($user->initial_password_expires_at)->toBeNull()
        ->and(Activity::query()->where('event', 'account.activated')->sole()->properties['password_changed'])->toBeTrue();

    // Now the email is a login identifier, and the first-login screen is over.
    $this->from('/login')->post('/login', ['identifier' => 'nadia.anindya@example.test', 'password' => 'Hujan-bulan-juni-26'])
        ->assertRedirect(route('home'));
});

it('leaves an account without a verified email pending after nothing was reset', function (): void {
    $user = H::pendingStudent()->user;

    expect(app(CompleteActivation::class)->whenPasswordAlreadyChosen($user))->toBeFalse()
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation);
});

it('sends no reset code for the NIS of an activated student (D-137: NISN or email only)', function (): void {
    H::activeStudent();

    w1cRequestCode(H::NIS)->assertSee(RequestPasswordResetCode::GENERIC_STATUS);

    Mail::assertNothingQueued();
});
