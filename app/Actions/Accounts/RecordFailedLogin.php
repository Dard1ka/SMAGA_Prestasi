<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * K3 (docs/SECURITY.md §3a): counts wrong passwords on a student account that has not finished activation.
 * The 10th cumulative failure freezes it (`frozen_reason` = failed_attempts), ends its sessions and alerts Admin + Kesiswaan;
 * only a K2 reopen, an Admin unfreeze or an Admin reset opens it again. Activated accounts are never frozen
 * by failures (they only get the progressive lockout), so classmates cannot lock a victim out on purpose.
 * Called from the Fortify `Failed` listener (FortifyServiceProvider); never changes the login response.
 */
final class RecordFailedLogin
{
    public function __construct(
        private readonly SecurityAlerts $alerts,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    /** @return bool true when this failure froze the account */
    public function handle(User $user): bool
    {
        if (! AccountControls::countsFailedLogins($user)) {
            return false;
        }

        $frozen = DB::transaction(function () use ($user): bool {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== UserStatus::PendingActivation || AccountControls::isFrozen($locked)) {
                return false;
            }

            $attempts = AccountControls::failedAttempts($locked) + 1;
            $freeze = $attempts >= AccountControls::MAX_FAILED_ATTEMPTS;

            $locked->forceFill(['failed_activation_attempts' => $attempts]);

            if ($freeze) {
                $locked->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS]);
            }

            $locked->saveQuietly();

            if ($freeze) {
                activity('security')
                    ->performedOn($locked)
                    ->event('account.frozen')
                    ->withProperties(['reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'attempts' => $attempts])
                    ->log('account.frozen');

                Log::warning('security.activation_frozen', ['user_id' => $locked->getKey(), 'attempts' => $attempts]);
            }

            return $freeze;
        });

        if ($frozen) {
            // A session opened earlier with the initial password (e.g. mid-activation) ends as well.
            $this->revokeSessions->handle($user);
            $this->alerts->accountFrozen($user, AccountControls::FROZEN_FAILED_ATTEMPTS);
        }

        return $frozen;
    }
}
