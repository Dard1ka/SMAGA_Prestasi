<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Accounts\RequestPasswordResetCode;
use App\Actions\Accounts\ResetPasswordWithCode;
use App\Actions\Accounts\VerifyOtpCode;
use App\Actions\Fortify\LoginIdentifier;
use App\Domain\Accounts\PasswordPolicy;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Guest "Lupa kata sandi" (docs/SECURITY.md §3b): 1 NIS/NISN (or staff email) → same message whether or not
 * the account exists → 2 six-digit code from the bound email + new password. Rate limited per identifier
 * bucket and per IP.
 */
#[Layout('layouts::auth', ['variant' => 'simple'])]
#[Title('Lupa kata sandi')]
class ForgotPassword extends Component
{
    #[Locked]
    public int $step = 1;

    /** The identifier the code was requested for; locked so step 2 cannot be pointed at another account. */
    #[Locked]
    public string $requestedFor = '';

    public string $identifier = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function sendCode(RequestPasswordResetCode $request): void
    {
        $this->resetErrorBag();

        $this->validate(['identifier' => ['required', 'string', 'max:254']], ['required' => 'Wajib diisi.', 'max' => 'Terlalu panjang.']);

        $this->throttle('forgot-send', $this->identifier, 3, 900, 'identifier');

        $request->handle($this->identifier);

        $this->requestedFor = LoginIdentifier::normalize($this->identifier);
        $this->step = 2;
        $this->reset('code', 'password', 'password_confirmation');

        session()->flash('status', RequestPasswordResetCode::GENERIC_STATUS);
    }

    public function resendCode(RequestPasswordResetCode $request): void
    {
        $this->resetErrorBag();

        if ($this->requestedFor === '') {
            $this->step = 1;

            return;
        }

        $this->throttle('forgot-send', $this->requestedFor, 3, 900, 'code');

        $request->handle($this->requestedFor);

        session()->flash('status', RequestPasswordResetCode::GENERIC_STATUS);
    }

    public function resetPassword(ResetPasswordWithCode $reset): mixed
    {
        $this->resetErrorBag();

        if ($this->requestedFor === '') {
            $this->step = 1;

            return null;
        }

        $this->validate([
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', 'max:'.PasswordPolicy::MAX_LENGTH],
        ], Activation::messages(), ['password' => 'kata sandi']);

        $this->throttle('forgot-verify', $this->requestedFor, 10, 600, 'code');

        $reset->handle($this->requestedFor, $this->code, $this->password, $this->password_confirmation, Activation::messages());

        session()->flash('status', 'Kata sandi sudah diganti. Silakan masuk.');

        return $this->redirectRoute('login');
    }

    public function startOver(): void
    {
        $this->resetErrorBag();
        $this->reset('code', 'password', 'password_confirmation', 'requestedFor');
        $this->step = 1;
    }

    public function render(): mixed
    {
        return view('livewire.auth.forgot-password', [
            'testMode' => VerifyOtpCode::testModeActive(),
            'minLength' => app(PasswordPolicy::class)->minLength(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function throttle(string $name, string $identifier, int $perIdentifier, int $decaySeconds, string $field): void
    {
        $keys = [
            $name.':'.substr(hash_hmac('sha256', LoginIdentifier::normalize($identifier), (string) config('app.key')), 0, 32) => $perIdentifier,
            $name.'-ip:'.request()->ip() => 30,
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([$field => AppServiceProvider::retryMessage(RateLimiter::availableIn($key))]);
            }
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }
}
