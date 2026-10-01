<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Last activation step: binds the email proven by a verified `verify_email` OTP and marks the account active.
 * In OTP test mode the email stays unverified (email_verified_at = null) so it is re-verified once real OTP
 * delivery is on (docs/SECURITY.md §3b point 4).
 * B1: stores `activated_at` (K9 report), clears the K1/K3 fields and mails the K8 notice with the
 * "Bukan saya?" link to the bound address (SendEmailBindingNotice).
 */
final class CompleteActivation
{
    public const PURPOSE = OtpPurpose::VerifyEmail->value;

    public function __construct(private readonly SendEmailBindingNotice $notices) {}

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
                // B1: K9 activation date; the K1/K3 counters are no longer needed once activated.
                'activated_at' => now(),
                'initial_password_expires_at' => null,
                'failed_activation_attempts' => 0,
            ])->save();

            activity('auth')
                ->performedOn($user)
                ->causedBy($user)
                ->event('account.activated')
                ->withProperties(['otp_test_mode' => VerifyOtpCode::testModeActive()])
                ->log('account.activated');
        });

        // B1 — K8: tell the bound address, with the single-use "Bukan saya?" link.
        $this->notices->handle($user, EmailBindingNotice::BOUND, (string) $otp->email, (string) $otp->email);
    }
}
