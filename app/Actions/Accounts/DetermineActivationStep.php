<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\UserStatus;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Which first-login activation step a user still has to do (docs/SECURITY.md §3a K4, order of the lead's decision
 * of 2026-10-04): 'email' → bind + verify an email (address, then the 6-digit code); 'password' → the password
 * step (a student may change the school password or keep it; staff, and a student with the temporary password of
 * an Admin reset, must change it: CompleteActivation::mayKeepPassword); null → done.
 *
 * The email comes first, so a login that stops half-way resumes at the right step: no verified email → 'email';
 * verified email but the password step not closed (`must_change_password`, or the account is still
 * `pending_activation`) → 'password'.
 *
 * While OTP test mode is active (local only) an email bound without real verification counts as verified;
 * once test mode is off such users are asked to verify again (SECURITY §3b point 4).
 */
final class DetermineActivationStep
{
    public const EMAIL = 'email';

    public const PASSWORD = 'password';

    /** Audit events that open the email step again; a later `account.email_bound` closes it (test mode only). */
    private const EMAIL_STEP_REOPENED = ['account.reset', 'account.staff_reset'];

    public function __invoke(User $user): ?string
    {
        if (! self::emailVerified($user)) {
            return self::EMAIL;
        }

        if ($user->must_change_password || $user->status === UserStatus::PendingActivation) {
            return self::PASSWORD;
        }

        return null;
    }

    /** The account has a bound address that was proven with a code (or bound in local OTP test mode). */
    public static function emailVerified(User $user): bool
    {
        if (blank($user->email)) {
            return false;
        }

        if ($user->email_verified_at !== null) {
            return true;
        }

        if (! VerifyOtpCode::testModeActive()) {
            return false;
        }

        // Local OTP test mode: a bound address stays unverified on purpose. An account that is already active keeps
        // working; a pending one has passed the email step only when it bound the address itself, which is not the
        // case for the address an Admin typed for a new or reset staff account.
        return $user->status !== UserStatus::PendingActivation || self::boundInTestMode($user);
    }

    /** First-login activation finished: nothing left to do and the account is `active`. */
    public function finished(User $user): bool
    {
        return $user->status === UserStatus::Active && $this($user) === null;
    }

    /**
     * Test mode leaves no verification timestamp, so the audit trail answers: the newest of "email bound" and
     * "account reset" for this account is "email bound" (CompleteActivation::bindEmail).
     */
    private static function boundInTestMode(User $user): bool
    {
        $event = Activity::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', (string) $user->getKey())
            ->whereIn('event', [CompleteActivation::EVENT_EMAIL_BOUND, ...self::EMAIL_STEP_REOPENED])
            ->latest('id')
            ->value('event');

        return $event === CompleteActivation::EVENT_EMAIL_BOUND;
    }
}
