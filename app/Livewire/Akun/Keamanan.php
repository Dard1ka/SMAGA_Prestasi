<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ChangeEmail;
use App\Actions\Accounts\ChangePassword;
use App\Actions\Accounts\ListUserSessions;
use App\Actions\Accounts\RevokeOtherSession;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Accounts\VerifyOtpCode;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Akun › Keamanan (`akun.keamanan`, every role, docs/UI-MAP.md §2, B1; since D-152 the tab "Keamanan akun" of
 * Profil saya, titled "Profil saya", next to the tab Data diri of Akun\Profil): change password (current password
 * required; students PasswordPolicy(10), staff Password::defaults(); after a change the session id is renewed and
 * the old session row deleted, a refused attempt changes nothing), change the bound email (password + OTP to
 * the new address, K8 notices to old + new), list/sign out other devices, and for staff the 2FA status with a
 * link to /akun/2fa. Holds no rules: everything goes through App\Actions\Accounts\*. Always acts on the signed-in
 * user only (no ids from the client except the HMAC session key, which is matched server-side).
 */
#[Title('Profil saya')]
#[Layout('layouts::app', ['subtitle' => 'Kata sandi, email, dan perangkat yang masuk.'])]
final class Keamanan extends Component
{
    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $newEmail = '';

    public string $emailPassword = '';

    public string $emailCode = '';

    /** Masked address the change-email code was sent to (null = step 1 of the email form). */
    #[Locked]
    public ?string $codeSentTo = null;

    public function mount(ChangeEmail $changeEmail): void
    {
        $pending = $changeEmail->pendingEmail($this->user());
        $this->codeSentTo = $pending !== null ? AccountControls::maskEmail($pending) : null;
    }

    public function changePassword(ChangePassword $change): void
    {
        $this->resetErrorBag();
        $user = $this->user();

        $this->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ], self::messages(), ['currentPassword' => 'kata sandi sekarang', 'password' => 'kata sandi baru']);

        $this->throttle('akun-sandi:'.$user->getKey(), 5, 900, 'currentPassword');

        try {
            $change->handle($user, $this->currentPassword, $this->password, session()->getId());
        } catch (ValidationException $e) {
            // A refused attempt (wrong current password, weak new one) leaves the session as it is (QA Q2-07).
            throw self::remap($e, ['current_password' => 'currentPassword']);
        }

        // Only now, with the new password stored: a new session id, and the old row is deleted, not left behind.
        session()->regenerate(true);

        RateLimiter::clear('akun-sandi:'.$user->getKey());
        session()->put('auth.password_confirmed_at', time());

        $this->reset('currentPassword', 'password', 'password_confirmation');
        $this->dispatch('sth-toast', message: 'Kata sandi diganti. Perangkat lain sudah dikeluarkan.', tone: 'success');
    }

    public function sendEmailCode(ChangeEmail $changeEmail): void
    {
        $this->resetErrorBag();
        $user = $this->user();

        $this->validate([
            'newEmail' => ['required', 'string', 'max:254'],
            'emailPassword' => ['required', 'string'],
        ], self::messages(), ['newEmail' => 'email baru', 'emailPassword' => 'kata sandi']);

        $this->throttle('akun-email:'.$user->getKey(), 5, 3600, 'newEmail');

        try {
            $sent = $changeEmail->requestCode($user, $this->emailPassword, $this->newEmail);
        } catch (ValidationException $e) {
            throw self::remap($e, ['current_password' => 'emailPassword', 'email' => 'newEmail']);
        }

        if (! $sent) {
            $this->addError('newEmail', 'Tunggu sebentar sebelum meminta kode lagi.');

            return;
        }

        $this->codeSentTo = AccountControls::maskEmail(mb_strtolower(trim($this->newEmail)));
        $this->reset('emailPassword', 'emailCode');
    }

    public function confirmEmail(VerifyOtpCode $verify, ChangeEmail $changeEmail): void
    {
        $this->resetErrorBag();
        $user = $this->user();

        $this->validate(['emailCode' => ['required', 'digits:6']], self::messages() + ['emailCode.digits' => 'Isi keenam angka kodenya.']);

        $this->throttle('akun-email-kode:'.$user->getKey(), 10, 60, 'emailCode');

        $otp = $verify->handle($user, ChangeEmail::PURPOSE, $this->emailCode);

        if ($otp === null) {
            throw ValidationException::withMessages(['emailCode' => 'Kode salah atau sudah kedaluwarsa.']);
        }

        try {
            $email = $changeEmail->confirm($user, $otp);
        } catch (ValidationException $e) {
            $this->codeSentTo = null;

            throw self::remap($e, ['email' => 'newEmail', 'code' => 'emailCode']);
        }

        RateLimiter::clear('akun-email-kode:'.$user->getKey());
        $this->reset('newEmail', 'emailPassword', 'emailCode', 'codeSentTo');
        $this->dispatch('sth-toast', message: 'Email diganti ke '.AccountControls::maskEmail($email).'.', tone: 'success');
    }

    public function cancelEmailChange(): void
    {
        $this->resetErrorBag();
        $this->reset('newEmail', 'emailPassword', 'emailCode', 'codeSentTo');
    }

    public function revokeSession(string $key, RevokeOtherSession $revoke): void
    {
        $done = $revoke->one($this->user(), $key, session()->getId());

        $this->dispatch('sth-toast', message: $done ? 'Perangkat dikeluarkan.' : 'Perangkat itu sudah keluar.', tone: $done ? 'success' : 'info');
    }

    public function revokeOtherSessions(RevokeOtherSession $revoke): void
    {
        $revoke->others($this->user(), session()->getId());

        $this->dispatch('sth-toast', message: 'Semua perangkat lain sudah dikeluarkan.', tone: 'success');
    }

    public function render(ListUserSessions $sessions, SetNewPassword $setPassword): View
    {
        $user = $this->user();
        $isStaff = EnsureStaffTwoFactor::isStaff($user);

        return view('livewire.akun.keamanan', [
            'user' => $user,
            // D-152: this page is the tab "Keamanan akun" of Profil saya.
            'tabs' => Profil::tabs($user),
            'isStaff' => $isStaff,
            'passwordHint' => $setPassword->hint($user),
            'passwordChangedAt' => AccountControls::date($user, 'password_changed_at'),
            'email' => AccountControls::maskEmail($user->email),
            'emailVerified' => $user->email_verified_at !== null,
            'testMode' => VerifyOtpCode::testModeActive(),
            'sessionsAvailable' => ListUserSessions::available(),
            'sessions' => $sessions->handle($user, session()->getId(), request()->userAgent(), request()->ip()),
            'twoFactorConfirmed' => $user->two_factor_confirmed_at !== null,
            'twoFactorUrl' => $isStaff && Route::has('two-factor.setup') ? route('two-factor.setup') : null,
        ]);
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'confirmed' => 'Kata sandi belum sama.',
            'max' => 'Terlalu panjang.',
            'password.min' => 'Minimal :min karakter.',
            'password.max' => 'Maksimal :max karakter.',
        ];
    }

    /**
     * Rate limit per user for this screen (password guessing through the "current password" fields).
     *
     * @throws ValidationException
     */
    private function throttle(string $key, int $max, int $decaySeconds, string $field): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([$field => AppServiceProvider::retryMessage(RateLimiter::availableIn($key))]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /** @param array<string, string> $map action error key => form field */
    private static function remap(ValidationException $e, array $map): ValidationException
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors[$map[$key] ?? $key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
