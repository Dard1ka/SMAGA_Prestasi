<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Accounts\SecurityNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Akun › Keamanan "Ganti email" (B1, SECURITY K8: "ganti email = password + OTP"):
 * 1. requestCode(): current password + new address → 6-digit code to the NEW address (IssueOtpCode, purpose
 *    verify_email — the same purpose as activation; an activated user can no longer reach /aktivasi).
 * 2. confirm(): verified OtpCode → email replaced (unique, locked), audited (`account.email_changed`), the old
 *    address and the new one each get a K8 notice with a "Bukan saya?" link, the owner gets a bell notice.
 * In OTP test mode (local only) the new email stays unverified, as at activation (SECURITY §3b point 4).
 * Error keys: current_password, email, code.
 */
final class ChangeEmail
{
    public const PURPOSE = OtpPurpose::VerifyEmail->value;

    public function __construct(
        private readonly IssueOtpCode $otp,
        private readonly SendEmailBindingNotice $notices,
    ) {}

    /** @return array<int, mixed> */
    public static function emailRules(User $user): array
    {
        return ['required', 'string', 'max:254', 'email:rfc', Rule::unique('users', 'email')->ignore($user->getKey())];
    }

    /**
     * @return bool false while the 60-second resend cooldown runs (nothing sent)
     *
     * @throws ValidationException
     */
    public function requestCode(User $user, string $currentPassword, string $email): bool
    {
        if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi salah.']);
        }

        $email = mb_strtolower(trim($email));

        Validator::make(['email' => $email], ['email' => self::emailRules($user)], [
            'required' => 'Wajib diisi.',
            'email' => 'Tulis alamat email yang benar.',
            'max' => 'Email terlalu panjang.',
            'unique' => 'Email ini sudah dipakai akun lain.',
        ])->validate();

        if ($email === mb_strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['email' => 'Ini sudah email akunmu.']);
        }

        return $this->otp->handle($user, self::PURPOSE, $email);
    }

    public function pendingEmail(User $user): ?string
    {
        return $this->otp->pendingEmail($user, self::PURPOSE);
    }

    /**
     * @return string the new email
     *
     * @throws ValidationException
     */
    public function confirm(User $user, OtpCode $otp): string
    {
        if ((string) $otp->user_id !== (string) $user->getKey() || $otp->purpose !== OtpPurpose::VerifyEmail) {
            throw ValidationException::withMessages(['code' => 'Kode tidak valid.']);
        }

        $new = mb_strtolower((string) $otp->email);
        $old = $user->email;

        DB::transaction(function () use ($user, $new, $old): void {
            $taken = User::query()
                ->where('email', $new)
                ->whereKeyNot($user->getKey())
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah dipakai akun lain.']);
            }

            $user->forceFill([
                'email' => $new,
                'email_verified_at' => VerifyOtpCode::testModeActive() ? null : now(),
            ])->save();

            activity('auth')
                ->performedOn($user)
                ->causedBy($user)
                ->event('account.email_changed')
                ->withProperties([
                    'from' => AccountControls::maskEmail($old),
                    'to' => AccountControls::maskEmail($new),
                    'otp_test_mode' => VerifyOtpCode::testModeActive(),
                ])
                ->log('account.email_changed');
        });

        if ($old !== null && $old !== '' && mb_strtolower($old) !== $new) {
            $this->notices->handle($user, EmailBindingNotice::CHANGED_OLD, $old, $new);
        }

        $this->notices->handle($user, EmailBindingNotice::CHANGED_NEW, $new, $new);
        $user->notify(SecurityNotice::emailChanged((string) AccountControls::maskEmail($new)));

        return $new;
    }
}
