<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Last activation step: binds the email proven by a verified `verify_email` OTP and marks the account active.
 * In OTP test mode the email stays unverified (email_verified_at = null) so it is re-verified once real OTP
 * delivery is on (docs/SECURITY.md §3b point 4).
 */
final class CompleteActivation
{
    public const PURPOSE = OtpPurpose::VerifyEmail->value;

    /**
     * @throws ValidationException when the email was taken by another account in the meantime
     */
    public function handle(User $user, OtpCode $otp): void
    {
        if ((string) $otp->user_id !== (string) $user->getKey() || $otp->purpose !== OtpPurpose::VerifyEmail) {
            throw ValidationException::withMessages(['code' => 'Kode tidak valid.']);
        }

        DB::transaction(function () use ($user, $otp): void {
            $taken = User::query()
                ->where('email', $otp->email)
                ->whereKeyNot($user->getKey())
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah dipakai akun lain.']);
            }

            $user->forceFill([
                'email' => $otp->email,
                'email_verified_at' => VerifyOtpCode::testModeActive() ? null : now(),
                'status' => UserStatus::Active,
            ])->save();

            activity('auth')
                ->performedOn($user)
                ->causedBy($user)
                ->event('account.activated')
                ->withProperties(['otp_test_mode' => VerifyOtpCode::testModeActive()])
                ->log('account.activated');
        });
    }
}
