<?php

declare(strict_types=1);

use App\Actions\Accounts\ChangeEmail;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\RequestPasswordResetCode;
use App\Enums\RoleName;
use App\Livewire\Akun\Keamanan;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 | SV1 (browser verification for the school's server, 2026-10-30): "Batal ganti email" on Akun › Keamanan only emptied
 | the form. The code sent to the new address stayed valid, so a reload brought the code form back, and "Kirim kode" for
 | the corrected address was refused for the 60-second cooldown. Now the cancel ends that code (ChangeEmail::cancel());
 | the limits per account still count it, and nothing is audited or told. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
    config(['fortify.sth.otp_test_mode' => false]);
});

function cancelEmailStudent(): User
{
    return User::query()->findOrFail(H::activeStudent()->user_id);
}

/** The plaintext code of the newest queued change-email mail to that address. */
function cancelEmailMailedCode(string $email): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    expect($code)->not->toBeNull();

    return (string) $code;
}

it('ends the waiting change: its code stops working, a reload shows the email form, a corrected address gets a code at once', function (): void {
    $user = cancelEmailStudent();
    $this->actingAs($user);
    $audits = Activity::query()->count();

    $component = Livewire::test(Keamanan::class)
        ->set('newEmail', 'nadia.salah.ketik@example.test')
        ->set('emailPassword', H::PASSWORD)
        ->call('sendEmailCode')
        ->assertHasNoErrors()
        ->assertSet('codeSentTo', 'n•••@example.test');
    $cancelledCode = cancelEmailMailedCode('nadia.salah.ketik@example.test');

    $component->call('cancelEmailChange')
        ->assertHasNoErrors()
        ->assertSet('codeSentTo', null)
        ->assertSee('Kirim kode');

    expect(OtpCode::query()->where('user_id', $user->getKey())->whereNull('used_at')->count())->toBe(0)
        ->and(app(ChangeEmail::class)->pendingEmail($user))->toBeNull();

    // A forged "Simpan email" with the cancelled code changes nothing.
    $component->set('emailCode', $cancelledCode)
        ->call('confirmEmail')
        ->assertHasErrors(['emailCode' => 'Kode salah atau sudah kedaluwarsa.']);
    expect($user->fresh()->email)->toBe('nadia.anindya@example.test');

    // A reload: the email form, not the code form.
    Livewire::test(Keamanan::class)->assertSet('codeSentTo', null);

    // The corrected address right away: no 60-second wait after a cancel.
    Livewire::test(Keamanan::class)
        ->set('newEmail', 'nadia.baru@example.test')
        ->set('emailPassword', H::PASSWORD)
        ->call('sendEmailCode')
        ->assertHasNoErrors()
        ->assertSet('codeSentTo', 'n•••@example.test');

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->hasTo('nadia.baru@example.test'));

    // A cancel changes nothing about the account: no audit row, no notice.
    expect(Activity::query()->count())->toBe($audits);
    Notification::assertNothingSent();
});

it('still waits for the cooldown while a code is on its way', function (): void {
    $user = cancelEmailStudent();
    $changeEmail = app(ChangeEmail::class);

    expect($changeEmail->requestCode($user, H::PASSWORD, 'nadia.satu@example.test'))->toBeTrue()
        ->and($changeEmail->requestCode($user, H::PASSWORD, 'nadia.dua@example.test'))->toBeFalse()
        ->and(app(IssueOtpCode::class)->secondsUntilResend($user, ChangeEmail::PURPOSE))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    $this->travel(61)->seconds();

    expect($changeEmail->requestCode($user, H::PASSWORD, 'nadia.dua@example.test'))->toBeTrue();
});

it('keeps counting cancelled codes towards the limit of five codes an hour', function (): void {
    $user = cancelEmailStudent();
    $changeEmail = app(ChangeEmail::class);

    foreach (range(1, IssueOtpCode::MAX_PER_HOUR) as $i) {
        expect($changeEmail->requestCode($user, H::PASSWORD, "nadia.{$i}@example.test"))->toBeTrue()
            ->and($changeEmail->cancel($user))->toBeTrue();
    }

    expect($changeEmail->requestCode($user, H::PASSWORD, 'nadia.enam@example.test'))->toBeFalse()
        ->and(app(IssueOtpCode::class)->secondsUntilResend($user, ChangeEmail::PURPOSE))->toBeGreaterThan(60);

    Mail::assertQueuedCount(IssueOtpCode::MAX_PER_HOUR);
});

it('keeps the screen limit of five codes an hour across cancels', function (): void {
    $this->actingAs(cancelEmailStudent());

    foreach (range(1, 5) as $i) {
        Livewire::test(Keamanan::class)
            ->set('newEmail', "nadia.{$i}@example.test")
            ->set('emailPassword', H::PASSWORD)
            ->call('sendEmailCode')
            ->assertHasNoErrors()
            ->call('cancelEmailChange');
    }

    Livewire::test(Keamanan::class)
        ->set('newEmail', 'nadia.enam@example.test')
        ->set('emailPassword', H::PASSWORD)
        ->call('sendEmailCode')
        ->assertHasErrors('newEmail')
        ->assertSee('Terlalu banyak percobaan');

    Mail::assertQueuedCount(5);
});

it('ends only the account\'s own waiting email change', function (): void {
    $user = cancelEmailStudent();
    $other = H::staff(RoleName::Guru);
    $issue = app(IssueOtpCode::class);

    expect($issue->handle($user, ChangeEmail::PURPOSE, 'nadia.baru@example.test'))->toBeTrue()
        ->and($issue->handle($user, RequestPasswordResetCode::PURPOSE, 'nadia.anindya@example.test'))->toBeTrue()
        ->and($issue->handle($other, ChangeEmail::PURPOSE, 'guru.baru@example.test'))->toBeTrue();

    expect(app(ChangeEmail::class)->cancel($user))->toBeTrue()
        ->and(app(ChangeEmail::class)->pendingEmail($user))->toBeNull()
        // The account's reset code and the other account's change stay.
        ->and($issue->pendingEmail($user, RequestPasswordResetCode::PURPOSE))->toBe('nadia.anindya@example.test')
        ->and(app(ChangeEmail::class)->pendingEmail($other))->toBe('guru.baru@example.test');
});

it('does nothing when no change is waiting', function (): void {
    $user = cancelEmailStudent();
    $this->actingAs($user);

    expect(app(ChangeEmail::class)->cancel($user))->toBeFalse();

    Livewire::test(Keamanan::class)->call('cancelEmailChange')->assertHasNoErrors()->assertSet('codeSentTo', null);
});
