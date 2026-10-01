<?php

declare(strict_types=1);

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
    'unknown NIS' => ['123456789'],
    'account without email' => [H::NIS],
]);

it('rejects a wrong code without revealing anything and keeps the old password', function (): void {
    $user = H::activeStudent()->user;

    w1cRequestCode(H::NIS)
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

    w1cRequestCode(H::NIS);

    Mail::assertNothingQueued();
});
