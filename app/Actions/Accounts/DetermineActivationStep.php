<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Which first-login activation step a user still has to do (docs/SECURITY.md §3a K4):
 * 'password' → change the initial/temporary password; 'email' → bind + verify an email; null → done.
 *
 * While OTP test mode is active (local only) an email bound without real verification counts as done;
 * once test mode is off such users are asked to verify again (SECURITY §3b point 4).
 */
final class DetermineActivationStep
{
    public const PASSWORD = 'password';

    public const EMAIL = 'email';

    public function __invoke(User $user): ?string
    {
        if ($user->must_change_password) {
            return self::PASSWORD;
        }

        if (blank($user->email)) {
            return self::EMAIL;
        }

        if ($user->email_verified_at === null && ! VerifyOtpCode::testModeActive()) {
            return self::EMAIL;
        }

        if ($user->status === UserStatus::PendingActivation) {
            return self::EMAIL;
        }

        return null;
    }
}
