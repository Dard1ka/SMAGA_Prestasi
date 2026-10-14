<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Accounts\VerifyOtpCode;
use App\Actions\Fortify\LoginIdentifier;
use App\Domain\Accounts\PasswordPolicy;
use App\Enums\RoleName;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * First-login activation (SECURITY §3a K4; order of the lead's decision of 2026-10-04): 1 bind email →
 * 2 six-digit code → 3 password → done. At step 3 every account replaces its password, students and staff alike
 * (D-138; D-92 now holds for all): there is no "Lewati". The same screen serves a restored account.
 * The step shown is only a view hint: every action re-derives the real step from the database
 * (DetermineActivationStep), so a tampered client cannot skip a step. All state changes are in CompleteActivation.
 * The session id is renewed (and the old session row deleted) after the code and after the password step, never on
 * a refused attempt.
 */
#[Layout('layouts::auth', ['variant' => 'simple'])]
#[Title('Aktivasi akun')]
class Activation extends Component
{
    public const STEP_EMAIL = 1;

    public const STEP_CODE = 2;

    public const STEP_PASSWORD = 3;

    public const STEP_DONE = 4;

    #[Locked]
    public int $step = self::STEP_EMAIL;

    /** Masked address the code was sent to, later the bound address (n•••@gmail.com). */
    #[Locked]
    public ?string $sentTo = null;

    public string $email = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(DetermineActivationStep $activationStep, IssueOtpCode $otp): mixed
    {
        $user = $this->user();
        $next = $activationStep($user);

        if ($next === null) {
            return $this->redirectRoute('home');
        }

        if ($next === DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_PASSWORD;
            $this->sentTo = self::mask((string) $user->email);

            return null;
        }

        $pending = $otp->pendingEmail($user, CompleteActivation::PURPOSE);

        if ($pending !== null) {
            $this->step = self::STEP_CODE;
            $this->sentTo = self::mask($pending);
        } else {
            $this->step = self::STEP_EMAIL;
            // A staff address typed by the Admin (still unverified) is offered, never a student's (they have none).
            $this->email = (string) $user->email;
        }

        return null;
    }

    public function sendCode(DetermineActivationStep $activationStep, IssueOtpCode $otp): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if (! $this->isAt(DetermineActivationStep::EMAIL, $activationStep, $user)) {
            return;
        }

        $this->email = mb_strtolower(trim($this->email));

        $this->validate([
            'email' => ['required', 'string', 'max:254', 'email:rfc', Rule::unique('users', 'email')->ignore($user->getKey())],
        ], self::messages());

        if (! $this->sendTo($otp, $user, $this->email, 'email')) {
            return;
        }

        $this->code = '';
        $this->sentTo = self::mask($this->email);
        $this->step = self::STEP_CODE;
    }

    public function resendCode(DetermineActivationStep $activationStep, IssueOtpCode $otp): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if (! $this->isAt(DetermineActivationStep::EMAIL, $activationStep, $user)) {
            return;
        }

        $pending = $otp->pendingEmail($user, CompleteActivation::PURPOSE);

        if ($pending === null) {
            $this->step = self::STEP_EMAIL;

            return;
        }

        if ($this->sendTo($otp, $user, $pending, 'code')) {
            session()->flash('status', 'Kode baru sudah dikirim.');
        }
    }

    /** Back from the code to the address (a mistyped email); a new code follows the usual OTP limits. */
    public function changeEmail(DetermineActivationStep $activationStep): void
    {
        $this->resetErrorBag();

        if (! $this->isAt(DetermineActivationStep::EMAIL, $activationStep, $this->user())) {
            return;
        }

        $this->code = '';
        $this->step = self::STEP_EMAIL;
    }

    public function verifyCode(DetermineActivationStep $activationStep, VerifyOtpCode $verify, CompleteActivation $complete): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if (! $this->isAt(DetermineActivationStep::EMAIL, $activationStep, $user)) {
            return;
        }

        $this->validate(['code' => ['required', 'digits:6']], self::messages());

        $key = 'otp-verify:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => AppServiceProvider::retryMessage(RateLimiter::availableIn($key))]);
        }

        RateLimiter::hit($key, 60);

        $otpCode = $verify->handle($user, CompleteActivation::PURPOSE, $this->code);

        if ($otpCode === null) {
            throw ValidationException::withMessages(['code' => 'Kode salah atau sudah kedaluwarsa.']);
        }

        try {
            $complete->bindEmail($user, $otpCode);
        } catch (ValidationException $e) {
            $this->step = self::STEP_EMAIL;

            throw $e;
        }

        // New session id for the bound account; the old row is deleted, so it is neither listed as a second
        // device nor usable any more (QA Q1-03).
        session()->regenerate(true);
        RateLimiter::clear($key);

        $this->code = '';
        $this->sentTo = self::mask((string) $otpCode->email);

        if ($activationStep($user) === DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_PASSWORD;

            return;
        }

        // No password step left (the password was chosen before the email, in the old order).
        $this->step = self::STEP_DONE;
    }

    /** Step 3, "Ganti kata sandi": rules and the state change are in CompleteActivation::changePassword. */
    public function savePassword(DetermineActivationStep $activationStep, CompleteActivation $complete): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if (! $this->isAt(DetermineActivationStep::PASSWORD, $activationStep, $user)) {
            return;
        }

        $this->validate(
            ['password' => ['required', 'string', 'confirmed', 'max:'.PasswordPolicy::MAX_LENGTH]],
            self::messages(),
            ['password' => 'kata sandi'],
        );

        // A refused password throws here and leaves the session alone (QA Q2-07: every failed try used to add a
        // "device"). Other devices are signed out by the Action; this one gets a new id once the password is stored.
        $complete->changePassword($user, $this->password, $this->password_confirmation, session()->getId(), self::messages());
        session()->regenerate(true);

        $this->reset('password', 'password_confirmation');
        $this->step = self::STEP_DONE;
    }

    public function start(DetermineActivationStep $activationStep): mixed
    {
        $user = $this->user();

        if ($activationStep($user) !== null) {
            return $this->redirectRoute('aktivasi');
        }

        return $user->hasRole(RoleName::Siswa->value) ? $this->redirectRoute('siswa.tour') : $this->redirectRoute('home');
    }

    public function render(IssueOtpCode $otp, SetNewPassword $setPassword): mixed
    {
        $user = $this->user();

        return view('livewire.auth.activation', [
            'user' => $user,
            'testMode' => VerifyOtpCode::testModeActive(),
            'resendIn' => $this->step === self::STEP_CODE ? $otp->secondsUntilResend($user, CompleteActivation::PURPOSE) : 0,
            'passwordHint' => $setPassword->hint($user),
            'isStudent' => LoginIdentifier::isStudentAccount($user),
            'temporaryPassword' => CompleteActivation::hasTemporaryPassword($user),
        ]);
    }

    /** Masks an address for display: nadia@example.com → n•••@example.com */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'confirmed' => 'Kata sandi belum sama.',
            'password.min' => 'Minimal :min karakter.',
            'password.max' => 'Maksimal :max karakter.',
            'email.email' => 'Tulis alamat email yang benar.',
            'email.max' => 'Email terlalu panjang.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'code.digits' => 'Isi keenam angka kodenya.',
        ];
    }

    private function sendTo(IssueOtpCode $otp, User $user, string $email, string $field): bool
    {
        $key = 'otp-send:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError($field, AppServiceProvider::retryMessage(RateLimiter::availableIn($key)));

            return false;
        }

        if (! $otp->handle($user, CompleteActivation::PURPOSE, $email)) {
            $seconds = $otp->secondsUntilResend($user, CompleteActivation::PURPOSE);

            // QA10: past the cooldown it is the hourly or daily limit of the account, which can last for hours.
            $this->addError($field, $seconds > 60
                ? AppServiceProvider::retryMessage($seconds)
                : 'Tunggu '.$seconds.' detik sebelum kirim ulang.');

            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * True when the account really is at `$expected`. Otherwise nothing may be done: the view is moved to the real
     * step (or home when the activation is already finished) and the action returns.
     */
    private function isAt(string $expected, DetermineActivationStep $activationStep, User $user): bool
    {
        $actual = $activationStep($user);

        if ($actual === $expected) {
            return true;
        }

        if ($actual === null) {
            $this->redirectRoute('home');
        } elseif ($actual === DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_PASSWORD;
            $this->sentTo = self::mask((string) $user->email);
        } else {
            $this->step = self::STEP_EMAIL;
        }

        return false;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
