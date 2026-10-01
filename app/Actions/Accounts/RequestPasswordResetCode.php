<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginIdentifier;
use App\Enums\OtpPurpose;
use App\Models\User;

/**
 * "Lupa kata sandi" step 1 (docs/SECURITY.md §3b). Sends a reset OTP to the account's bound email when the
 * identifier (NIS/NISN, staff email/username) belongs to an account that may log in and has a usable email.
 * Callers always show the same message, whatever happened here (no user enumeration).
 */
final class RequestPasswordResetCode
{
    public const PURPOSE = OtpPurpose::ResetPassword->value;

    public const GENERIC_STATUS = 'Jika data cocok, kode telah dikirim ke email terdaftar.';

    public function __construct(
        private readonly LoginIdentifier $identifiers,
        private readonly IssueOtpCode $otp,
    ) {}

    public function handle(string $identifier): void
    {
        $user = $this->eligibleUser($identifier);

        if ($user !== null) {
            $this->otp->handle($user, self::PURPOSE, (string) $user->email);
        }
    }

    public function eligibleUser(string $identifier): ?User
    {
        $user = $this->identifiers->resolve($identifier);

        if ($user === null || ! $user->status?->canLogIn() || blank($user->email)) {
            return null;
        }

        if ($user->email_verified_at === null && ! VerifyOtpCode::testModeActive()) {
            return null;
        }

        return $user;
    }
}
