<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Accounts\VerifyOtpCode;
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
 * First-login activation (mockup Aktivasi.dc.html, SECURITY §3a K4): 1 new password → 2 bind email →
 * 3 six-digit code → 4 done. The step shown is only a view hint: every action re-derives the real step from
 * the database (DetermineActivationStep), so a tampered client cannot skip a step.
 */
#[Layout('layouts::auth', ['variant' => 'simple'])]
#[Title('Aktivasi akun')]
class Activation extends Component
{
    public const STEP_PASSWORD = 1;

    public const STEP_EMAIL = 2;

    public const STEP_CODE = 3;

    public const STEP_DONE = 4;

    #[Locked]
    public int $step = self::STEP_PASSWORD;

    /** Masked address the code was sent to (n•••@gmail.com). */
    #[Locked]
    public ?string $sentTo = null;

    public string $password = '';

    public string $password_confirmation = '';

    public string $email = '';

    public string $code = '';

    public function mount(DetermineActivationStep $activationStep, IssueOtpCode $otp): mixed
    {
        $user = $this->user();
        $next = $activationStep($user);

        if ($next === null) {
            return $this->redirectRoute('home');
        }

        if ($next === DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_PASSWORD;

            return null;
        }

        $pending = $otp->pendingEmail($user, CompleteActivation::PURPOSE);

        if ($pending !== null) {
            $this->step = self::STEP_CODE;
            $this->sentTo = self::mask($pending);
        } else {
            $this->step = self::STEP_EMAIL;
            $this->email = (string) $user->email;
        }

        return null;
    }

    public function savePassword(DetermineActivationStep $activationStep, SetNewPassword $setPassword): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if ($activationStep($user) !== DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_EMAIL;

            return;
        }

        $this->validate(['password' => $setPassword->rules($user)], self::messages(), ['password' => 'kata sandi']);

        session()->regenerate();
        $setPassword->handle($user, $this->password, session()->getId(), 'account.initial_password_changed');

        $this->reset('password', 'password_confirmation');
        $this->email = (string) $user->email;
        $this->step = self::STEP_EMAIL;
    }

    public function sendCode(DetermineActivationStep $activationStep, IssueOtpCode $otp): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if ($this->redirectIfPasswordPending($activationStep, $user)) {
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

    public function resendCode(IssueOtpCode $otp): void
    {
        $this->resetErrorBag();

        $user = $this->user();
        $pending = $otp->pendingEmail($user, CompleteActivation::PURPOSE);

        if ($pending === null) {
            $this->step = self::STEP_EMAIL;

            return;
        }

        if ($this->sendTo($otp, $user, $pending, 'code')) {
            session()->flash('status', 'Kode baru sudah dikirim.');
        }
    }

    public function changeEmail(): void
    {
        $this->resetErrorBag();
        $this->code = '';
        $this->step = self::STEP_EMAIL;
    }

    public function verifyCode(DetermineActivationStep $activationStep, VerifyOtpCode $verify, CompleteActivation $complete): void
    {
        $this->resetErrorBag();

        $user = $this->user();

        if ($this->redirectIfPasswordPending($activationStep, $user)) {
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
            $complete->handle($user, $otpCode);
        } catch (ValidationException $e) {
            $this->step = self::STEP_EMAIL;

            throw $e;
        }

        session()->regenerate();
        RateLimiter::clear($key);

        $this->code = '';
        $this->sentTo = self::mask($otpCode->email);
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

    public function render(IssueOtpCode $otp): mixed
    {
        $user = $this->user();

        return view('livewire.auth.activation', [
            'user' => $user,
            'testMode' => VerifyOtpCode::testModeActive(),
            'resendIn' => $this->step === self::STEP_CODE ? $otp->secondsUntilResend($user, CompleteActivation::PURPOSE) : 0,
            'minLength' => app(PasswordPolicy::class)->minLength(),
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
            $this->addError($field, 'Tunggu '.$otp->secondsUntilResend($user, CompleteActivation::PURPOSE).' detik sebelum kirim ulang.');

            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    private function redirectIfPasswordPending(DetermineActivationStep $activationStep, User $user): bool
    {
        if ($activationStep($user) === DetermineActivationStep::PASSWORD) {
            $this->step = self::STEP_PASSWORD;

            return true;
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
