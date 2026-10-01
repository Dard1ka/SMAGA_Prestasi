<?php

declare(strict_types=1);

use App\Actions\Accounts\VerifyOtpCode;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Auth\Activation;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

const W1C_NEW_PASSWORD = 'Pelangi-senja-2026';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    config(['fortify.sth.otp_test_mode' => false]);
});

/** Runs step 1 + 2 and returns the component at step 3, plus the queued plaintext code. */
function w1cToCodeStep(User $user, string $email = 'nadia.anindya@example.test'): array
{
    test()->actingAs($user);

    $component = Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', W1C_NEW_PASSWORD)
        ->set('password_confirmation', W1C_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', $email)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE)
        ->assertSet('sentTo', 'n•••@example.test');

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        $code = $mail->code;

        return $mail->hasTo($email) && $mail->purpose === 'verify_email';
    });

    return [$component, $code];
}

it('activates a student in OTP test mode (local only) and lands on the tour placeholder', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['fortify.sth.otp_test_mode' => true]);

    $user = H::pendingStudent()->user;

    [$component] = w1cToCodeStep($user);

    $component->set('code', '000000')
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->call('start')
        ->assertRedirect(route('siswa.tour'));

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email)->toBe('nadia.anindya@example.test')
        ->and($user->email_verified_at)->toBeNull() // test mode: re-verify once real OTP is on
        ->and($user->must_change_password)->toBeFalse()
        ->and(Hash::check(W1C_NEW_PASSWORD, $user->password))->toBeTrue();

    // Code rows only ever hold a hash.
    expect(OtpCode::query()->where('user_id', $user->getKey())->value('code_hash'))->toHaveLength(64);
});

it('rejects an arbitrary code when test mode is off, accepts the mailed code once', function (): void {
    $user = H::pendingStudent()->user;

    [$component, $code] = w1cToCodeStep($user);
    $wrong = $code === '123456' ? '654321' : '123456';

    $component->set('code', $wrong)->call('verifyCode')->assertHasErrors('code')->assertSet('step', Activation::STEP_CODE);

    $component->set('code', $code)->call('verifyCode')->assertHasNoErrors()->assertSet('step', Activation::STEP_DONE);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull();

    // Single use.
    expect(app(VerifyOtpCode::class)->handle($user, 'verify_email', $code))->toBeNull();
});

it('ignores STH_OTP_TEST_MODE outside APP_ENV=local', function (): void {
    config(['fortify.sth.otp_test_mode' => true]); // env is "testing"

    expect(VerifyOtpCode::testModeActive())->toBeFalse();

    [$component, $code] = w1cToCodeStep(H::pendingStudent()->user);

    $component->set('code', $code === '111111' ? '222222' : '111111')->call('verifyCode')->assertHasErrors('code');
});

it('burns a code after 5 wrong attempts and expires it after 10 minutes', function (): void {
    $user = H::pendingStudent()->user;
    [$component, $code] = w1cToCodeStep($user);
    $wrong = $code === '999999' ? '888888' : '999999';

    foreach (range(1, 5) as $i) {
        $component->set('code', $wrong)->call('verifyCode')->assertHasErrors('code');
    }

    $component->set('code', $code)->call('verifyCode')->assertHasErrors('code');

    // A fresh code after the cooldown works, but not once it has expired.
    $this->travel(61)->seconds();
    $component->call('resendCode')->assertHasNoErrors();

    $fresh = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$fresh): bool {
        $fresh = $mail->code;

        return true;
    });

    $this->travel(11)->minutes();
    $component->set('code', $fresh)->call('verifyCode')->assertHasErrors('code');
    expect($user->fresh()->status)->toBe(UserStatus::PendingActivation);
});

it('enforces the 60 second resend cooldown', function (): void {
    [$component] = w1cToCodeStep(H::pendingStudent()->user);

    $component->call('resendCode')->assertHasErrors('code');
    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();
    $component->call('resendCode')->assertHasNoErrors();
    Mail::assertQueuedCount(2);
});

it('applies the password policy (10+ chars, no NIS, no birth date)', function (string $password): void {
    $this->actingAs(H::pendingStudent()->user);

    Livewire::test(Activation::class)
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSet('step', Activation::STEP_PASSWORD);
})->with([
    'too short' => ['Abc12345'],
    'contains NIS' => ['nadia'.H::NIS],
    'contains birth date' => ['Rahasia02032010'],
    'initial pattern' => ['sm4g4_020310xyz'],
    'no digits' => ['HanyaHurufSaja'],
]);

it('does not let a tampered client skip the password step', function (): void {
    $user = H::pendingStudent()->user;
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->set('email', 'nadia.anindya@example.test')
        ->call('sendCode')
        ->assertSet('step', Activation::STEP_PASSWORD);

    Mail::assertNothingQueued();
    expect(OtpCode::query()->count())->toBe(0);
});

it('refuses an email that another account already uses', function (): void {
    H::staff(RoleName::Guru, ['email' => 'dipakai@example.test']);
    $user = H::pendingStudent()->user;
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->set('password', W1C_NEW_PASSWORD)
        ->set('password_confirmation', W1C_NEW_PASSWORD)
        ->call('savePassword')
        ->set('email', 'dipakai@example.test')
        ->call('sendCode')
        ->assertHasErrors('email')
        ->assertSet('step', Activation::STEP_EMAIL);
});

it('keeps unactivated users on the activation screen', function (): void {
    $this->actingAs(H::pendingStudent()->user);

    $this->get(route('home'))->assertRedirect(route('aktivasi'));
    $this->get(route('siswa.tour'))->assertRedirect(route('aktivasi'));
});

it('sends activated users away from the activation screen', function (): void {
    $this->actingAs(H::activeStudent()->user);

    Livewire::test(Activation::class)->assertRedirect(route('home'));
});
