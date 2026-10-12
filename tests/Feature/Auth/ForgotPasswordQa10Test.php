<?php

declare(strict_types=1);

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\RequestPasswordResetCode;
use App\Actions\Accounts\ResetCodeLimits;
use App\Actions\Accounts\ResetPasswordWithCode;
use App\Actions\Accounts\VerifyOtpCode;
use App\Livewire\Auth\ForgotPassword;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 item 2: the reset code could be guessed without end. The screen limits were keyed on the TYPED identifier, so
| NIS, NISN and email of one student (and, on MySQL, accent variants of an address) each had their own counter, and
| an account could get any number of codes. Now the screen limits are keyed on the account the identifier resolves
| to and the client address (an unknown identifier keeps its own stable bucket), a reset code belongs to the browser
| that asked for it, and SECURITY §3b holds per browser (reset) or per account (email codes): at most 5 codes an hour
| and 10 a day, and at most 10 wrong codes a day. Fictional data only.
*/

uses(RefreshDatabase::class);

const QA10_AUTH_EMAIL = 'nadia.anindya@example.test';

const QA10_AUTH_NEW_PASSWORD = 'Hujan-bulan-juni-26';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

function qa10AuthForgotFor(string $identifier): Testable
{
    return Livewire::test(ForgotPassword::class)
        ->set('identifier', $identifier)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
}

/** The plain code of the last code mail (the test mailer keeps it; the app never stores it). */
function qa10AuthLastCode(): string
{
    $codes = Mail::queued(OtpCodeMail::class)->map(fn (OtpCodeMail $mail): string => $mail->code);

    expect($codes)->not->toBeEmpty();

    return (string) $codes->last();
}

function qa10AuthWrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

function qa10AuthTryCode(Testable $component, string $code): Testable
{
    return $component->set('code', $code)
        ->set('password', QA10_AUTH_NEW_PASSWORD)
        ->set('password_confirmation', QA10_AUTH_NEW_PASSWORD)
        ->call('resetPassword');
}

it('shares the code request limit between NIS, NISN and email of one account (QA10)', function (): void {
    H::activeStudent();

    qa10AuthForgotFor(H::NISN);
    qa10AuthForgotFor(H::NISN);
    qa10AuthForgotFor(QA10_AUTH_EMAIL);

    Livewire::test(ForgotPassword::class)
        ->set('identifier', H::NISN)
        ->call('sendCode')
        ->assertHasErrors('identifier')
        ->assertSet('step', 1)
        ->assertSee('Terlalu banyak percobaan');
});

it('keeps a separate, stable request limit for an identifier without an account (QA10)', function (): void {
    H::activeStudent();

    foreach (range(1, 3) as $i) {
        qa10AuthForgotFor('123456789');
    }

    Livewire::test(ForgotPassword::class)->set('identifier', '123456789')->call('sendCode')->assertHasErrors('identifier');

    // The unknown number used up its own limit, not the student's.
    qa10AuthForgotFor(H::NISN);
});

it('shares the code check limit between NIS, NISN and email of one account (QA10)', function (): void {
    H::activeStudent();

    $first = qa10AuthForgotFor(H::NISN);
    $wrong = qa10AuthWrongCode(qa10AuthLastCode());

    foreach (range(1, 4) as $i) {
        qa10AuthTryCode($first, $wrong)->assertHasErrors(['code' => ResetPasswordWithCode::INVALID_CODE]);
    }

    $second = qa10AuthForgotFor(H::NISN);

    foreach (range(1, 4) as $i) {
        qa10AuthTryCode($second, $wrong)->assertHasErrors('code');
    }

    $third = qa10AuthForgotFor(QA10_AUTH_EMAIL);

    foreach (range(1, 2) as $i) {
        qa10AuthTryCode($third, $wrong)->assertHasErrors('code');
    }

    // The eleventh check of the account within ten minutes, whichever identifier the screen was opened with.
    qa10AuthTryCode($third, $wrong)->assertHasErrors('code')->assertSee('Terlalu banyak percobaan');
});

it('stops email codes once a signed-in account had 5 in an hour or 10 in a day (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $issue = app(IssueOtpCode::class);
    $send = fn (): bool => $issue->handle($user, CompleteActivation::PURPOSE, 'nadia.baru@example.test');

    foreach (range(1, 5) as $i) {
        expect($send())->toBeTrue();
        $this->travel(61)->seconds();
    }

    expect($send())->toBeFalse()
        ->and($issue->secondsUntilResend($user, CompleteActivation::PURPOSE))->toBeGreaterThan(60);

    $this->travel(1)->hours();

    foreach (range(6, 10) as $i) {
        expect($send())->toBeTrue();
        $this->travel(61)->seconds();
    }

    $this->travel(1)->hours();

    // A new hour, but the tenth code of the day was sent already.
    expect($send())->toBeFalse();
    Mail::assertQueuedCount(10);

    $this->travel(1)->days();

    expect($send())->toBeTrue();
});

// Review of QA10: one code budget per account let anyone who knows a NIS switch "Lupa kata sandi" off for a day (ten
// wrong codes, or the day's codes, or a new code that cancelled the owner's). Now a reset code belongs to the browser
// that asked for it: only that browser can use it, only its own new code cancels it, and wrong codes count against
// that browser. The account keeps a looser daily cap of reset codes (IssueOtpCode::ACCOUNT_RESET_CODES_PER_DAY).

it('keeps the reset code limits per browser, so a stranger never uses up the codes of the owner (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $issue = app(IssueOtpCode::class);
    $reset = RequestPasswordResetCode::PURPOSE;

    foreach (range(1, 5) as $i) {
        expect($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-stranger'))->toBeTrue();
        $this->travel(61)->seconds();
    }

    expect($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-stranger'))->toBeFalse()
        ->and($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-owner'))->toBeTrue()
        ->and($issue->handle($user, CompleteActivation::PURPOSE, 'nadia.baru@example.test'))->toBeTrue();
});

it('stops reset codes for the account after the daily cap, whichever browser asks (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $issue = app(IssueOtpCode::class);
    $reset = RequestPasswordResetCode::PURPOSE;

    foreach (range(1, 20) as $i) {
        expect($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-'.$i))->toBeTrue();
    }

    expect($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-21'))->toBeFalse();
    Mail::assertQueuedCount(20);

    $this->travel(1)->days();

    expect($issue->handle($user, $reset, QA10_AUTH_EMAIL, 'browser-21'))->toBeTrue();
});

it('refuses even the right code after 10 wrong codes in the same browser in a day, but not in another (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $issue = app(IssueOtpCode::class);
    $verify = app(VerifyOtpCode::class);
    $purpose = RequestPasswordResetCode::PURPOSE;

    // Two codes, five wrong guesses each (the most one code takes).
    foreach (range(1, 2) as $round) {
        $issue->handle($user, $purpose, QA10_AUTH_EMAIL, 'browser-one');
        $wrong = qa10AuthWrongCode(qa10AuthLastCode());

        foreach (range(1, 5) as $i) {
            expect($verify->handle($user, $purpose, $wrong, false, 'browser-one'))->toBeNull();
        }

        $this->travel(61)->seconds();
    }

    $issue->handle($user, $purpose, QA10_AUTH_EMAIL, 'browser-one');
    expect($verify->handle($user, $purpose, qa10AuthLastCode(), false, 'browser-one'))->toBeNull();

    $issue->handle($user, $purpose, QA10_AUTH_EMAIL, 'browser-two');
    expect($verify->handle($user, $purpose, qa10AuthLastCode(), false, 'browser-two'))->not->toBeNull();

    $this->travel(1)->days();

    $issue->handle($user, $purpose, QA10_AUTH_EMAIL, 'browser-one');
    expect($verify->handle($user, $purpose, qa10AuthLastCode(), false, 'browser-one'))->not->toBeNull();
});

it('lets the owner reset after a stranger guessed codes for the account (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $request = app(RequestPasswordResetCode::class);
    $reset = app(ResetPasswordWithCode::class);

    foreach (range(1, 2) as $round) {
        $request->handle(H::NISN, 'browser-stranger');
        $wrong = qa10AuthWrongCode(qa10AuthLastCode());

        foreach (range(1, 5) as $i) {
            expect(fn () => $reset->handle(H::NISN, $wrong, QA10_AUTH_NEW_PASSWORD, QA10_AUTH_NEW_PASSWORD, [], 'browser-stranger'))
                ->toThrow(ValidationException::class);
        }

        $this->travel(61)->seconds();
    }

    $request->handle(H::NISN, 'browser-owner');
    $reset->handle(H::NISN, qa10AuthLastCode(), QA10_AUTH_NEW_PASSWORD, QA10_AUTH_NEW_PASSWORD, [], 'browser-owner');

    expect(Hash::check(QA10_AUTH_NEW_PASSWORD, (string) User::query()->findOrFail($user->getKey())->password))->toBeTrue();
});

it('keeps the code of the owner when a stranger asks for a new one (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $request = app(RequestPasswordResetCode::class);

    $request->handle(H::NISN, 'browser-owner');
    $ownerCode = qa10AuthLastCode();

    $this->travel(61)->seconds();
    $request->handle(H::NISN, 'browser-stranger');

    app(ResetPasswordWithCode::class)->handle(H::NISN, $ownerCode, QA10_AUTH_NEW_PASSWORD, QA10_AUTH_NEW_PASSWORD, [], 'browser-owner');

    expect(Hash::check(QA10_AUTH_NEW_PASSWORD, (string) User::query()->findOrFail($user->getKey())->password))->toBeTrue();
});

it('accepts a code only in the browser that asked for it (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $reset = app(ResetPasswordWithCode::class);

    app(RequestPasswordResetCode::class)->handle(H::NISN, 'browser-owner');
    $code = qa10AuthLastCode();

    expect(fn () => $reset->handle(H::NISN, $code, QA10_AUTH_NEW_PASSWORD, QA10_AUTH_NEW_PASSWORD, [], 'browser-stranger'))
        ->toThrow(ValidationException::class);
    expect(Hash::check(H::PASSWORD, (string) User::query()->findOrFail($user->getKey())->password))->toBeTrue();

    $reset->handle(H::NISN, $code, QA10_AUTH_NEW_PASSWORD, QA10_AUTH_NEW_PASSWORD, [], 'browser-owner');
    expect(Hash::check(QA10_AUTH_NEW_PASSWORD, (string) User::query()->findOrFail($user->getKey())->password))->toBeTrue();
});

it('limits code requests per account and address, so a stranger elsewhere cannot use them up (QA10)', function (): void {
    H::activeStudent();
    $limits = app(ResetCodeLimits::class);

    foreach (range(1, 3) as $i) {
        expect($limits->hit(ResetCodeLimits::SEND, H::NISN, '10.66.0.1'))->toBeNull();
    }

    expect($limits->hit(ResetCodeLimits::SEND, H::NISN, '10.66.0.1'))->toBeGreaterThan(0)
        ->and($limits->hit(ResetCodeLimits::SEND, QA10_AUTH_EMAIL, '10.20.0.1'))->toBeNull();
});

it('limits code checks per account and address (QA10)', function (): void {
    H::activeStudent();
    $limits = app(ResetCodeLimits::class);

    foreach (range(1, 10) as $i) {
        expect($limits->hit(ResetCodeLimits::VERIFY, H::NISN, '10.66.0.1'))->toBeNull();
    }

    expect($limits->hit(ResetCodeLimits::VERIFY, QA10_AUTH_EMAIL, '10.66.0.1'))->toBeGreaterThan(0)
        ->and($limits->hit(ResetCodeLimits::VERIFY, H::NISN, '10.20.0.1'))->toBeNull();
});

it('still resets the password with the right code on the way in (QA10)', function (): void {
    $user = H::activeStudent()->user;

    $component = qa10AuthForgotFor(QA10_AUTH_EMAIL);

    qa10AuthTryCode($component, qa10AuthLastCode())->assertHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check(QA10_AUTH_NEW_PASSWORD, (string) User::query()->findOrFail($user->getKey())->password))->toBeTrue();
});
