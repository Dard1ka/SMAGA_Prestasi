<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\VerifyOtpCode;
use App\Actions\Users\ResetStaffAccount;
use App\Domain\Accounts\PasswordPolicy;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Auth\Activation;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| First-login activation, order of the lead's decision of 2026-10-04 (M1-B): 1 bind email → 2 six-digit code →
| 3 password, where a student may keep the current password ("Lewati") and staff must change theirs.
| Fictional data only.
*/

uses(RefreshDatabase::class);

const M1B_NEW_PASSWORD = 'Pelangi-senja-2026';

const M1B_EMAIL = 'nadia.anindya@example.test';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    config(['fortify.sth.otp_test_mode' => false]);
});

/** The plaintext code of the newest queued OTP mail to that address. */
function m1bMailedCode(string $email): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email) && $mail->purpose === 'verify_email') {
            $code = $mail->code;
        }

        return true;
    });

    expect($code)->not->toBeNull();

    return (string) $code;
}

/**
 * Runs step 1 and returns the component at step 2, plus the queued plaintext code.
 *
 * @return array{0: Testable, 1: string}
 */
function m1bToCodeStep(User $user, string $email = M1B_EMAIL): array
{
    test()->actingAs($user);

    $component = Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', $email)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE)
        ->assertSet('sentTo', Activation::mask($email));

    return [$component, m1bMailedCode($email)];
}

/** Runs steps 1 + 2 and returns the component at step 3 (the password step). */
function m1bToPasswordStep(User $user, string $email = M1B_EMAIL): Testable
{
    [$component, $code] = m1bToCodeStep($user, $email);

    return $component->set('code', $code)
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD);
}

function m1bEvent(string $event): ?Activity
{
    return Activity::query()->where('event', $event)->latest('id')->first();
}

// Order: email → code → password ---------------------------------------------------------------------

it('binds the email first and keeps the account pending until the password step', function (): void {
    $user = H::pendingStudent()->user;

    m1bToPasswordStep($user)->assertSee('Langkah 3 dari 3')->assertSee('Lewati, pakai kata sandi sekarang');

    $user->refresh();
    expect($user->email)->toBe(M1B_EMAIL)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and(AccountControls::date($user, 'activated_at'))->toBeNull()
        ->and(app(DetermineActivationStep::class)($user))->toBe(DetermineActivationStep::PASSWORD)
        ->and(m1bEvent(CompleteActivation::EVENT_EMAIL_BOUND)?->properties['email'])->toBe('n•••@example.test')
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED))->toBeNull();

    // Still locked into the activation screen.
    $this->get(route('home'))->assertRedirect(route('aktivasi'));
});

it('lets a student skip the password change: the stored password stays and the skip is audited', function (): void {
    $user = H::pendingStudent()->user;
    $user->forceFill(['initial_password_expires_at' => now()->addDays(3), 'failed_activation_attempts' => 4])->saveQuietly();
    $hash = $user->fresh()->password;

    m1bToPasswordStep($user)
        ->call('keepPassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSet('passwordChanged', false)
        ->assertSee('Kata sandi tetap, tidak diganti')
        ->call('start')
        ->assertRedirect(route('siswa.tour'));

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->password)->toBe($hash)
        ->and(Hash::check(H::PASSWORD, $user->password))->toBeTrue()
        ->and($user->password_changed_at)->toBeNull() // "still uses the password from school"
        ->and(AccountControls::date($user, 'activated_at'))->not->toBeNull()
        ->and(AccountControls::date($user, 'initial_password_expires_at'))->toBeNull()
        ->and(AccountControls::failedAttempts($user))->toBe(0)
        ->and(app(DetermineActivationStep::class)($user))->toBeNull();

    $kept = m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT);
    expect($kept)->not->toBeNull()
        ->and($kept->subject_id)->toBe((string) $user->getKey())
        ->and($kept->causer_id)->toBe($user->getKey())
        // Only the fact is recorded: no password material, not even a hash.
        ->and(collect($kept->properties)->isEmpty())->toBeTrue()
        ->and(json_encode(Activity::query()->get(['description', 'event', 'properties']), JSON_UNESCAPED_SLASHES))->not->toContain($hash)
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED)->properties['password_changed'])->toBeFalse()
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_CHANGED))->toBeNull();

    // The kept password logs the student in afterwards.
    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route('home'));
});

it('lets a student choose a new password at step 3', function (): void {
    config(['session.driver' => 'database']);

    $user = H::pendingStudent()->user;
    $component = m1bToPasswordStep($user);
    H::fakeSessions($user, 2);

    $component->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', M1B_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSet('passwordChanged', true)
        ->assertSet('password', '')
        ->assertSee('Kata sandi baru tersimpan')
        ->call('start')
        ->assertRedirect(route('siswa.tour'));

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->must_change_password)->toBeFalse()
        ->and(Hash::check(M1B_NEW_PASSWORD, $user->password))->toBeTrue()
        ->and(Hash::check(H::PASSWORD, $user->password))->toBeFalse()
        ->and($user->password_changed_at)->not->toBeNull()
        // Other devices are signed out.
        ->and(DB::table('sessions')->where('id', 'like', 'sess-'.$user->getKey().'-%')->count())->toBe(0)
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_CHANGED))->not->toBeNull()
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT))->toBeNull()
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED)->properties['password_changed'])->toBeTrue()
        ->and(json_encode(Activity::query()->get(['description', 'event', 'properties'])))->not->toContain(M1B_NEW_PASSWORD);
});

it('applies the password policy at step 3 (10+ chars, no NIS, no birth date)', function (string $password): void {
    $user = H::pendingStudent()->user;

    m1bToPasswordStep($user)
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSet('step', Activation::STEP_PASSWORD);

    expect($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(Hash::check(H::PASSWORD, $user->fresh()->password))->toBeTrue();
})->with([
    'too short' => ['Abc12345'],
    'contains NIS' => ['nadia'.H::NIS],
    'contains birth date' => ['Rahasia02032010'],
    'initial pattern' => ['sm4g4_020310xyz'],
    'no digits' => ['HanyaHurufSaja'],
    // M2-A (QA Q1-02): common passwords, whatever digits or symbols surround them.
    'the QA finding' => ['password123'],
    'common word with capital and symbols' => ['Qwerty!2026**'],
    'common word in leet' => ['P@ssw0rd-2026'],
]);

it('tells why a common password is refused', function (): void {
    m1bToPasswordStep(H::pendingStudent()->user)
        ->assertSee('Jangan pakai tanggal lahir, NIS/NISN, nama, atau kata yang mudah ditebak.')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSee(PasswordPolicy::MSG_TOO_COMMON);
});

it('wants the confirmation to match and a password that differs from the current one', function (): void {
    $user = H::pendingStudent()->user;
    $user->forceFill(['password' => Hash::make('Kunci-lemari-77')])->saveQuietly();

    m1bToPasswordStep($user)
        ->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', 'Lain-sekali-2026')
        ->call('savePassword')
        ->assertHasErrors('password')
        // Typing the current password again is not a change.
        ->set('password', 'Kunci-lemari-77')
        ->set('password_confirmation', 'Kunci-lemari-77')
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSee(CompleteActivation::MSG_SAME_PASSWORD)
        ->assertSet('step', Activation::STEP_PASSWORD);

    expect($user->fresh()->status)->toBe(UserStatus::PendingActivation);
});

// OTP ------------------------------------------------------------------------------------------------

it('activates a student in OTP test mode (local only) and lands on the tour placeholder', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['fortify.sth.otp_test_mode' => true]);

    $user = H::pendingStudent()->user;

    [$component] = m1bToCodeStep($user);

    $component->assertSee('Mode uji: kode apa pun diterima.')
        ->set('code', '000000')
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->call('keepPassword')
        ->assertSet('step', Activation::STEP_DONE)
        ->call('start')
        ->assertRedirect(route('siswa.tour'));

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email)->toBe(M1B_EMAIL)
        ->and($user->email_verified_at)->toBeNull() // test mode: re-verify once real OTP is on
        ->and($user->must_change_password)->toBeFalse();

    // Code rows only ever hold a hash.
    expect(OtpCode::query()->where('user_id', $user->getKey())->value('code_hash'))->toHaveLength(64);
});

it('rejects an arbitrary code when test mode is off, accepts the mailed code once', function (): void {
    $user = H::pendingStudent()->user;

    [$component, $code] = m1bToCodeStep($user);
    $wrong = $code === '123456' ? '654321' : '123456';

    $component->set('code', $wrong)->call('verifyCode')->assertHasErrors('code')->assertSet('step', Activation::STEP_CODE);
    expect($user->fresh()->email)->toBeNull();

    $component->set('code', $code)->call('verifyCode')->assertHasNoErrors()->assertSet('step', Activation::STEP_PASSWORD);

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    // Single use.
    expect(app(VerifyOtpCode::class)->handle($user, 'verify_email', $code))->toBeNull();
});

it('ignores STH_OTP_TEST_MODE outside APP_ENV=local', function (): void {
    config(['fortify.sth.otp_test_mode' => true]); // env is "testing"

    expect(VerifyOtpCode::testModeActive())->toBeFalse();

    [$component, $code] = m1bToCodeStep(H::pendingStudent()->user);

    $component->set('code', $code === '111111' ? '222222' : '111111')->call('verifyCode')->assertHasErrors('code');
});

it('burns a code after 5 wrong attempts and expires it after 10 minutes', function (): void {
    $user = H::pendingStudent()->user;
    [$component, $code] = m1bToCodeStep($user);
    $wrong = $code === '999999' ? '888888' : '999999';

    foreach (range(1, 5) as $i) {
        $component->set('code', $wrong)->call('verifyCode')->assertHasErrors('code');
    }

    $component->set('code', $code)->call('verifyCode')->assertHasErrors('code');

    // A fresh code after the cooldown works, but not once it has expired.
    $this->travel(61)->seconds();
    $component->call('resendCode')->assertHasNoErrors();

    $fresh = m1bMailedCode(M1B_EMAIL);

    $this->travel(11)->minutes();
    $component->set('code', $fresh)->call('verifyCode')->assertHasErrors('code');

    expect($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and($user->fresh()->email)->toBeNull();
});

it('enforces the 60 second resend cooldown', function (): void {
    [$component] = m1bToCodeStep(H::pendingStudent()->user);

    $component->call('resendCode')->assertHasErrors('code');
    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();
    $component->call('resendCode')->assertHasNoErrors();
    Mail::assertQueuedCount(2);
});

it('lets the student go back and fix a mistyped email, within the OTP limits', function (): void {
    $user = H::pendingStudent()->user;
    [$component, $firstCode] = m1bToCodeStep($user, 'nadia.salah@example.test');

    $component->call('changeEmail')
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', M1B_EMAIL)
        // The resend cooldown also covers a new address.
        ->call('sendCode')
        ->assertHasErrors('email')
        ->assertSet('step', Activation::STEP_EMAIL);

    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();
    $component->call('sendCode')->assertHasNoErrors()->assertSet('step', Activation::STEP_CODE)->assertSet('sentTo', 'n•••@example.test');

    $secondCode = m1bMailedCode(M1B_EMAIL);

    // The code of the mistyped address died with the new one.
    if ($firstCode !== $secondCode) {
        $component->set('code', $firstCode)->call('verifyCode')->assertHasErrors('code');
    }

    $component->set('code', $secondCode)->call('verifyCode')->assertHasNoErrors()->assertSet('step', Activation::STEP_PASSWORD);

    expect($user->fresh()->email)->toBe(M1B_EMAIL);
});

it('refuses an email that another account already uses', function (): void {
    H::staff(RoleName::Guru, ['email' => 'dipakai@example.test']);
    $this->actingAs(H::pendingStudent()->user);

    Livewire::test(Activation::class)
        ->set('email', 'dipakai@example.test')
        ->call('sendCode')
        ->assertHasErrors('email')
        ->assertSet('step', Activation::STEP_EMAIL);

    Mail::assertNothingQueued();
});

// Forbidden: skipping steps ---------------------------------------------------------------------------

it('does not let a tampered client reach the password step before the email is verified', function (): void {
    $user = H::pendingStudent()->user;
    $hash = $user->password;
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', M1B_NEW_PASSWORD)
        ->call('savePassword')
        ->assertSet('step', Activation::STEP_EMAIL)
        ->call('keepPassword')
        ->assertSet('step', Activation::STEP_EMAIL);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash)
        ->and(Activity::query()->whereIn('event', [CompleteActivation::EVENT_PASSWORD_KEPT, CompleteActivation::EVENT_ACTIVATED])->count())->toBe(0);

    // The Actions refuse as well, whoever calls them.
    expect(fn () => app(CompleteActivation::class)->keepPassword($user))->toThrow(ValidationException::class)
        ->and(fn () => app(CompleteActivation::class)->changePassword($user, M1B_NEW_PASSWORD, M1B_NEW_PASSWORD))->toThrow(ValidationException::class)
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation);
});

it('does not accept a code once the email step is closed', function (): void {
    $user = H::pendingStudent()->user;
    $component = m1bToPasswordStep($user);

    $otp = new OtpCode;
    $otp->forceFill([
        'user_id' => $user->getKey(), 'purpose' => CompleteActivation::PURPOSE, 'email' => 'lain@example.test',
        'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5),
    ])->save();

    expect(fn () => app(CompleteActivation::class)->bindEmail($user->fresh(), $otp))->toThrow(ValidationException::class);

    // The screen moves a stale client to the real step instead of sending or checking a code.
    $component->set('email', 'lain@example.test')->call('sendCode')->assertSet('step', Activation::STEP_PASSWORD);
    $component->set('code', '123456')->call('verifyCode')->assertSet('step', Activation::STEP_PASSWORD);

    expect($user->fresh()->email)->toBe(M1B_EMAIL);
});

// Resume ----------------------------------------------------------------------------------------------

it('resumes at the right step when the student left in the middle', function (): void {
    $user = H::pendingStudent()->user;

    // Left after asking for the code → back at the code step.
    [, $code] = m1bToCodeStep($user);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_CODE)
        ->assertSet('sentTo', 'n•••@example.test')
        ->set('code', $code)
        ->call('verifyCode')
        ->assertSet('step', Activation::STEP_PASSWORD);

    // Left after the code → the next login goes to the password step, not back to the email.
    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->assertSee('Langkah 3 dari 3')
        ->call('keepPassword')
        ->assertSet('step', Activation::STEP_DONE);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
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

// Other accounts on the same screen --------------------------------------------------------------------

// M2-A (QA 2026-10-05, Q2-02): the temporary password of an Admin reset was shown on the Admin's screen, so the
// student must replace it. Only the school password of the import may be kept.
it('makes a student replace the temporary password of an Admin reset: same order, no skip', function (): void {
    config(['session.driver' => 'database']);

    $user = H::activeStudent()->user;
    $temporary = app(ResetStudentAccount::class)->handle(H::staff(RoleName::Admin), $user, 'Siswa lupa kata sandi dan email.');

    expect(CompleteActivation::hasTemporaryPassword($user->fresh()))->toBeTrue()
        ->and(CompleteActivation::mayKeepPassword($user->fresh()))->toBeFalse();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => $temporary])->assertRedirect(route('aktivasi'));

    $component = m1bToPasswordStep($user->fresh())
        ->assertSee('Buat kata sandi baru')
        ->assertSee('Ganti kata sandi sementara dari Admin.')
        ->assertDontSee('Lewati, pakai kata sandi sekarang');

    // Forbidden: the button is gone, and a hand-made call is refused by the screen and by the Action.
    $component->call('keepPassword')->assertForbidden();

    expect(fn () => app(CompleteActivation::class)->keepPassword($user->fresh()))
        ->toThrow(AuthorizationException::class, CompleteActivation::MSG_MUST_CHANGE);

    // Typing the temporary password again as the "new" one would be a hidden keep.
    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', $temporary)
        ->set('password_confirmation', $temporary)
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSet('step', Activation::STEP_PASSWORD);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and(CompleteActivation::hasTemporaryPassword($user))->toBeTrue()
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT))->toBeNull()
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED))->toBeNull();

    Livewire::test(Activation::class)
        ->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', M1B_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSet('passwordChanged', true);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::Active)
        ->and(Hash::check(M1B_NEW_PASSWORD, $user->password))->toBeTrue()
        ->and(Hash::check($temporary, $user->password))->toBeFalse()
        // The mark goes with the temporary password.
        ->and(CompleteActivation::hasTemporaryPassword($user))->toBeFalse()
        ->and(AccountControls::date($user, 'initial_password_expires_at'))->toBeNull()
        ->and(json_encode(Activity::query()->get(['description', 'event', 'properties'])))->not->toContain($temporary);
});

it('still lets a student keep the school password of the import, and refuses once a reset has landed', function (): void {
    $user = H::pendingStudent()->user;
    $user->forceFill(['email' => M1B_EMAIL, 'email_verified_at' => now()])->saveQuietly();
    $loadedBeforeTheReset = $user->fresh();

    expect(CompleteActivation::hasTemporaryPassword($loadedBeforeTheReset))->toBeFalse()
        ->and(CompleteActivation::mayKeepPassword($loadedBeforeTheReset))->toBeTrue();

    // An Admin reset lands while the student still has the password step open in another tab: the Action looks
    // at the stored row, not at the copy the screen loaded earlier.
    DB::table('users')->where('id', $user->getKey())->update(['temporary_password_issued_at' => now()]);

    expect(fn () => app(CompleteActivation::class)->keepPassword($loadedBeforeTheReset))
        ->toThrow(AuthorizationException::class, CompleteActivation::MSG_MUST_CHANGE)
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT))->toBeNull();

    // Without the mark the school password may be kept as before.
    DB::table('users')->where('id', $user->getKey())->update(['temporary_password_issued_at' => null]);
    app(CompleteActivation::class)->keepPassword($user->fresh());

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT))->not->toBeNull();
});

// M2-A (QA 2026-10-05, Q1-03 / Q2-07): a renewed session id leaves no old row behind, and a refused attempt
// renews nothing.
it('deletes the old session row when the id is renewed, so no ghost device stays and the old id is dead', function (): void {
    config(['session.driver' => 'database']);

    $user = H::pendingStudent()->user;

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));

    $atLogin = session()->getId();
    expect(H::sessionCount($user))->toBe(1)
        // Control: this is how a live session id answers.
        ->and(H::userBehindSession($atLogin))->toBe($user->getKey());

    [$component, $code] = m1bToCodeStep($user);
    expect(session()->getId())->toBe($atLogin);

    // Step 2: the id is renewed and the row of the old id is gone.
    $component->set('code', $code)->call('verifyCode')->assertSet('step', Activation::STEP_PASSWORD);

    $afterCode = session()->getId();
    expect($afterCode)->not->toBe($atLogin)
        ->and(DB::table('sessions')->where('id', $atLogin)->exists())->toBeFalse();

    session()->save(); // what StartSession does when the request ends (Livewire tests skip the middleware)
    expect(H::sessionCount($user))->toBe(1);

    // Step 3, refused passwords: same id, no extra row.
    foreach (['Abc12345', 'password123', 'nadia'.H::NIS] as $refused) {
        $component->set('password', $refused)->set('password_confirmation', $refused)->call('savePassword')->assertHasErrors('password');
    }

    expect(session()->getId())->toBe($afterCode)
        ->and(H::sessionCount($user))->toBe(1);

    // Step 3, "Lewati": renewed once more, again without a leftover.
    $component->call('keepPassword')->assertSet('step', Activation::STEP_DONE);

    $afterActivation = session()->getId();
    expect($afterActivation)->not->toBe($afterCode)
        ->and(DB::table('sessions')->where('id', $afterCode)->exists())->toBeFalse();

    session()->save();
    expect(H::sessionCount($user))->toBe(1) // one browser, one device
        ->and(H::userBehindSession($afterActivation))->toBe($user->getKey())
        ->and(H::userBehindSession($atLogin))->toBeNull()
        ->and(H::userBehindSession($afterCode))->toBeNull();
});

it('renews the session after a new password at step 3 and signs every other device out', function (): void {
    config(['session.driver' => 'database']);

    $user = H::pendingStudent()->user;
    $user->forceFill(['email' => M1B_EMAIL, 'email_verified_at' => now()])->saveQuietly();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));

    $before = session()->getId();
    H::fakeSessions($user, 2);
    expect(H::sessionCount($user))->toBe(3);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', M1B_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE);

    $after = session()->getId();
    expect($after)->not->toBe($before)
        ->and(H::sessionCount($user))->toBe(0); // the old row and the two other devices are gone

    session()->save();
    expect(H::sessionCount($user))->toBe(1)
        ->and(H::userBehindSession($after))->toBe($user->getKey())
        ->and(H::userBehindSession($before))->toBeNull();
});

it('finishes after the code for an account that had already chosen its password in the old order', function (): void {
    $user = H::pendingStudent()->user;
    $user->forceFill([
        'password' => Hash::make(M1B_NEW_PASSWORD),
        'must_change_password' => false,
        'password_changed_at' => now()->subDay(),
    ])->saveQuietly();

    [$component, $code] = m1bToCodeStep($user->fresh());

    $component->set('code', $code)
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSet('passwordChanged', true);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED)->properties['password_changed'])->toBeTrue();
});

it('only re-verifies the email of an account that is already active', function (): void {
    // An address bound in local OTP test mode is unverified; with real OTP on, the owner verifies it again.
    $activatedAt = now()->subDays(20)->startOfSecond();
    $user = H::activeStudent(['email_verified_at' => null, 'activated_at' => $activatedAt])->user;

    [$component, $code] = m1bToCodeStep($user);

    $component->set('code', $code)->call('verifyCode')->assertHasNoErrors()->assertSet('step', Activation::STEP_DONE);

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and(AccountControls::date($user, 'activated_at')->equalTo($activatedAt))->toBeTrue()
        ->and(m1bEvent(CompleteActivation::EVENT_ACTIVATED))->toBeNull();
});

it('still asks staff for the email step in local OTP test mode: an address typed by an Admin is not a bound one', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['fortify.sth.otp_test_mode' => true]);
    Notification::fake();

    $staff = H::staff(RoleName::Guru, [
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'password_changed_at' => null,
        'email_verified_at' => null,
    ]);
    $this->actingAs($staff);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->assertSet('email', 'staf.guru@example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE)
        ->set('code', '000000')
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD);

    // Test mode leaves the address unverified; the audit trail tells that this account bound it itself, so a
    // later visit resumes at the password step.
    expect($staff->fresh()->email_verified_at)->toBeNull();
    Livewire::test(Activation::class)->assertSet('step', Activation::STEP_PASSWORD);

    // An Admin reset opens the email step again.
    app(ResetStaffAccount::class)->handle(H::staff(RoleName::Admin), $staff->fresh(), 'Sementara-baru-2026', 'Lupa kata sandi.');

    expect(app(DetermineActivationStep::class)($staff->fresh()))->toBe(DetermineActivationStep::EMAIL);
});

it('makes staff change the temporary password: same order, no skip', function (): void {
    // As CreateStaffAccount leaves it: pending, must change, the address typed by the Admin is unverified.
    $staff = H::staff(RoleName::Guru, [
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'password_changed_at' => null,
        'email_verified_at' => null,
    ]);
    $this->actingAs($staff);

    $component = Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->assertSet('email', 'staf.guru@example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE);

    $component->set('code', m1bMailedCode('staf.guru@example.test'))
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->assertSee('Buat kata sandi baru')
        ->assertDontSee('Lewati, pakai kata sandi sekarang')
        // M2-A (QA Q2-05): staff read their own rules, not the student's NIS/NISN.
        ->assertSee('Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.')
        ->assertDontSee('NIS/NISN');

    // Forbidden: a staff account cannot keep the password an Admin typed.
    $component->call('keepPassword')->assertForbidden();

    expect(fn () => app(CompleteActivation::class)->keepPassword($staff))->toThrow(AuthorizationException::class)
        ->and($staff->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and($staff->fresh()->must_change_password)->toBeTrue()
        ->and(m1bEvent(CompleteActivation::EVENT_PASSWORD_KEPT))->toBeNull();

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', M1B_NEW_PASSWORD)
        ->set('password_confirmation', M1B_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->call('start')
        ->assertRedirect(route('home'));

    $staff->refresh();
    expect($staff->status)->toBe(UserStatus::Active)
        ->and($staff->must_change_password)->toBeFalse()
        ->and(Hash::check(M1B_NEW_PASSWORD, $staff->password))->toBeTrue();
});

// M2-A (QA 2026-10-05, Q2-05): staff have no NIS; their username, email name and own name are refused instead.
it('refuses a staff password built from the username, the email name, the own name or a common word', function (string $password, string $message): void {
    $staff = H::staff(RoleName::Humas, [
        'name' => 'Rina Kusumawati',
        'username' => 'uji.qa2',
        'email' => 'humas.dua@example.test',
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'password_changed_at' => null,
    ]);
    $this->actingAs($staff);

    $component = Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', $password)
        ->set('password_confirmation', $password)
        ->call('savePassword')
        ->assertHasErrors('password')
        ->assertSet('step', Activation::STEP_PASSWORD);

    expect($component->errors()->get('password'))->toBe([$message])
        ->and($staff->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(Hash::check(H::PASSWORD, $staff->fresh()->password))->toBeTrue();
})->with([
    'the QA finding: username-username' => ['uji.qa2-uji.qa2', PasswordPolicy::MSG_USERNAME],
    'email name' => ['Humas-dua-2026x', PasswordPolicy::MSG_EMAIL],
    'own name' => ['Kusumawati-77-ok', PasswordPolicy::MSG_OWN_NAME],
    'common password' => ['password12345', PasswordPolicy::MSG_TOO_COMMON],
]);

// QA5 (2026-10-07, QA Q1-11): a student can leave the activation screen on a shared school computer ------------

it('offers "Keluar" on every activation step', function (): void {
    $user = H::pendingStudent()->user;

    $html = $this->actingAs($user)->get(route('aktivasi'))->assertOk()->getContent();

    expect($html)->toContain('action="'.route('logout').'"')
        ->and($html)->toContain('Keluar');
    expect(substr_count($html, 'name="_token"'))->toBeGreaterThanOrEqual(1);

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

// QA5 retest (2026-10-07) ------------------------------------------------------------------------------------

it('counts the resend wait down every second, not in 5-second jumps (backlog #65)', function (): void {
    [$component] = m1bToCodeStep(H::pendingStudent()->user);

    // The server still swaps in "Kirim ulang kode" at 0 (wire:poll); app.js ticks the text in between.
    $component->assertSeeHtml('data-sth-countdown="')
        ->assertSeeHtml('data-sth-countdown-text');

    expect(file_get_contents(resource_path('js/app.js')))->toContain('data-sth-countdown');
});

it('offers "Keluar" while the activation is open, not on the finished screen', function (): void {
    $user = H::pendingStudent()->user;

    m1bToPasswordStep($user)
        ->assertSee('Bukan akun kamu?')
        ->call('keepPassword')
        ->assertSet('step', Activation::STEP_DONE)
        ->assertDontSee('Bukan akun kamu?');
});
