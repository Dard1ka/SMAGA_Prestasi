<?php

declare(strict_types=1);

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Domain\Accounts\StudentProfileInput;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Auth\Activation;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| D-152 (PS1, lead 2026-10-15): during the first activation a student fills in "Data diri" (birth date pre-filled from
| the import, No HP), both required, after the code and before the new password. Staff have no such step, and an
| active student is never sent back to it. Fictional data only.
*/

uses(RefreshDatabase::class);

const PS1_EMAIL = 'nadia.anindya@example.test';

const PS1_PASSWORD = 'Pelangi-senja-2026';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
    config(['fortify.sth.otp_test_mode' => false]);
});

/** A pending student whose email is already proven: the next activation step is "Data diri". */
function ps1AtProfile(array $studentAttributes = []): User
{
    $student = H::pendingStudent($studentAttributes);
    $student->user->forceFill(['email' => PS1_EMAIL, 'email_verified_at' => now()])->save();

    return $student->user->fresh();
}

function ps1MailedCode(string $email): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email) && $mail->purpose === 'verify_email') {
            $code = $mail->code;
        }

        return true;
    });

    return (string) $code;
}

it('asks a student for Data diri right after the code, as step 3 of 4, pre-filled from the import', function (): void {
    $user = H::pendingStudent()->user;
    $this->actingAs($user);

    $component = Livewire::test(Activation::class)
        ->set('email', PS1_EMAIL)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE)
        ->assertSee('Langkah 2 dari 4');

    $component->set('code', ps1MailedCode(PS1_EMAIL))
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PROFILE)
        ->assertSet('birth_date', '2010-03-02')
        ->assertSet('phone', '')
        ->assertSee('Langkah 3 dari 4')
        ->assertSee('Data diri')
        ->assertSee('Tanggal lahir')
        ->assertSee('No HP');

    expect($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(app(DetermineActivationStep::class)($user->fresh()))->toBe(DetermineActivationStep::PROFILE);
});

it('renders Data diri with the masked address and never the NIS or the full address', function (): void {
    $user = ps1AtProfile();

    $this->actingAs($user)
        ->get(route('aktivasi'))
        ->assertOk()
        ->assertSee('Langkah 3 dari 4')
        ->assertSee('Simpan data diri')
        ->assertSee('n•••@example.test')
        ->assertSee('NISN '.H::NISN)
        ->assertDontSee(PS1_EMAIL)
        ->assertDontSee(H::NIS)
        ->assertSee('Keluar');
});

it('refuses an empty No HP and a birth date outside the window, both at once, and stays at the step', function (): void {
    $user = ps1AtProfile();
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PROFILE)
        ->set('birth_date', '2091-03-02')
        ->set('phone', '')
        ->call('saveProfile')
        ->assertHasErrors(['birth_date', 'phone'])
        ->assertSee(StudentProfileInput::BIRTH_DATE_RANGE)
        ->assertSee(StudentProfileInput::PHONE_EMPTY)
        ->assertSet('step', Activation::STEP_PROFILE);

    $student = $user->student->fresh();
    expect($student->profile_confirmed_at)->toBeNull()
        ->and($student->phone)->toBeNull()
        ->and($student->birth_date->format('Y-m-d'))->toBe('2010-03-02')
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_PROFILE_CONFIRMED)->exists())->toBeFalse();
});

it('stores birth date and No HP, audits two flags without values, then asks for the new password', function (): void {
    $user = ps1AtProfile();
    $this->actingAs($user);

    $component = Livewire::test(Activation::class)
        ->set('birth_date', '2010-03-12')
        ->set('phone', '0812 3456 7890')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->assertSee('Langkah 4 dari 4');

    $student = $user->student->fresh();
    expect($student->birth_date->format('Y-m-d'))->toBe('2010-03-12')
        ->and($student->phone)->toBe('081234567890')
        ->and($student->profile_confirmed_at)->not->toBeNull()
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation);

    $row = Activity::query()->where('event', CompleteActivation::EVENT_PROFILE_CONFIRMED)->sole();
    expect($row->properties->toArray())->toBe(['birth_date_changed' => true, 'phone_changed' => true])
        ->and((string) json_encode($row->properties))->not->toContain('0812')->not->toContain('2010');

    $component->set('password', PS1_PASSWORD)
        ->set('password_confirmation', PS1_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSee('Data diri tersimpan')
        ->assertSee('Kata sandi baru tersimpan');

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('gives staff no Data diri step: three steps, straight to the password', function (): void {
    $staff = H::staff(RoleName::Guru, [
        'email_verified_at' => now(),
        'status' => UserStatus::PendingActivation,
        'must_change_password' => true,
        'password_changed_at' => null,
    ]);

    expect(DetermineActivationStep::profileOpen($staff))->toBeFalse()
        ->and(app(DetermineActivationStep::class)($staff))->toBe(DetermineActivationStep::PASSWORD);

    $this->actingAs($staff);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->assertSee('Langkah 3 dari 3')
        ->assertDontSee('Data diri')
        ->call('saveProfile')
        ->assertSet('step', Activation::STEP_PASSWORD);

    expect(fn () => app(CompleteActivation::class)->confirmProfile($staff, '2010-03-02', '081234567890'))
        ->toThrow(ValidationException::class, CompleteActivation::MSG_PROFILE_DONE);
});

it('does not let a tampered client set the password before Data diri, and the Action refuses too', function (): void {
    $user = ps1AtProfile();
    $hash = $user->password;
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->set('password', PS1_PASSWORD)
        ->set('password_confirmation', PS1_PASSWORD)
        ->call('savePassword')
        ->assertSet('step', Activation::STEP_PROFILE);

    expect($user->fresh()->password)->toBe($hash)
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(fn () => app(CompleteActivation::class)->changePassword($user->fresh(), PS1_PASSWORD, PS1_PASSWORD))
        ->toThrow(ValidationException::class, CompleteActivation::MSG_PROFILE_FIRST);

    expect(Hash::check(PS1_PASSWORD, (string) $user->fresh()->password))->toBeFalse();
});

it('asks the step only once: a confirmed profile goes on to the password step', function (): void {
    $user = ps1AtProfile();
    $steps = app(DetermineActivationStep::class);

    expect($steps($user))->toBe(DetermineActivationStep::PROFILE);

    $user->student->forceFill(['profile_confirmed_at' => now()])->save();

    expect($steps($user->fresh()))->toBe(DetermineActivationStep::PASSWORD);
});

it('never sends an active student back to the step, whatever the profile holds', function (): void {
    $student = H::activeStudent();

    expect($student->fresh()->profile_confirmed_at)->toBeNull()
        ->and($student->fresh()->phone)->toBeNull()
        ->and(DetermineActivationStep::profileOpen($student->user->fresh()))->toBeFalse()
        ->and(app(DetermineActivationStep::class)($student->user->fresh()))->toBeNull();

    $this->actingAs($student->user->fresh())->get(route('notifikasi.index'))->assertOk();

    expect(fn () => app(CompleteActivation::class)->confirmProfile($student->user->fresh(), '2010-03-02', '081234567890'))
        ->toThrow(ValidationException::class, CompleteActivation::MSG_ALREADY_ACTIVE);
});

it('refuses Data diri before the email is proven', function (): void {
    $student = H::pendingStudent();

    expect(fn () => app(CompleteActivation::class)->confirmProfile($student->user, '2010-03-02', '081234567890'))
        ->toThrow(ValidationException::class, CompleteActivation::MSG_EMAIL_FIRST);

    expect($student->fresh()->profile_confirmed_at)->toBeNull();
});

it('keeps a student pending after "Lupa kata sandi" while Data diri is open, and activates once it is saved', function (): void {
    $user = ps1AtProfile();
    $user->forceFill(['must_change_password' => false, 'password_changed_at' => now()])->save();
    $complete = app(CompleteActivation::class);

    expect($complete->whenPasswordAlreadyChosen($user->fresh()))->toBeFalse()
        ->and($user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and(app(DetermineActivationStep::class)($user->fresh()))->toBe(DetermineActivationStep::PROFILE);

    $this->actingAs($user->fresh());

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_PROFILE)
        ->set('phone', '081234567890')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(app(DetermineActivationStep::class)($user->fresh()))->toBeNull();
});

it('keeps the birth date of the import when the student leaves it as it is', function (): void {
    $user = ps1AtProfile();

    app(CompleteActivation::class)->confirmProfile($user, '2010-03-02', '081234567890');

    expect($user->student->fresh()->birth_date->format('Y-m-d'))->toBe('2010-03-02')
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_PROFILE_CONFIRMED)->sole()->properties->toArray())
        ->toBe(['birth_date_changed' => false, 'phone_changed' => true]);
});
