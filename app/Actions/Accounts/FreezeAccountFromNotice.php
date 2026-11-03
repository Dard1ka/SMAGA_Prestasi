<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * K8 "Bukan saya?" (docs/SECURITY.md §3a): the recipient of an email-binding notice says the change was not
 * theirs. Single use: the `email_notices` row is spent, the account is frozen (`frozen_reason` = not_me),
 * every session + remember token is revoked and every Admin gets the alert (the recovery "ticket").
 * Recovery: Admin meets the owner, then resets the account (K10) or unfreezes it.
 */
final class FreezeAccountFromNotice
{
    public function __construct(
        private readonly RevokeUserSessions $revokeSessions,
        private readonly SecurityAlerts $alerts,
    ) {}

    /** Unknown, used or expired notices are not usable (the page shows "tautan tidak berlaku"). */
    public static function usable(string $noticeId): bool
    {
        return DB::table('email_notices')
            ->where('id', $noticeId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** @return User|null the frozen account, or null when the notice cannot be used */
    public function handle(string $noticeId): ?User
    {
        $user = DB::transaction(function () use ($noticeId): ?User {
            $notice = DB::table('email_notices')->where('id', $noticeId)->lockForUpdate()->first();

            if ($notice === null || $notice->used_at !== null || now()->greaterThanOrEqualTo($notice->expires_at)) {
                return null;
            }

            DB::table('email_notices')->where('id', $noticeId)->update(['used_at' => now(), 'updated_at' => now()]);

            /** @var User|null $user */
            $user = User::query()->whereKey($notice->user_id)->lockForUpdate()->first();

            if ($user === null) {
                return null;
            }

            $alreadyFrozen = AccountControls::isFrozen($user);

            if (! $alreadyFrozen) {
                $user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME])->saveQuietly();
            }

            activity('security')
                ->performedOn($user)
                ->event('account.frozen')
                ->withProperties([
                    'reason' => AccountControls::FROZEN_NOT_ME,
                    'notice_kind' => $notice->kind,
                    'already_frozen' => $alreadyFrozen,
                ])
                ->log('account.frozen');

            return $user;
        });

        if ($user === null) {
            return null;
        }

        Log::warning('security.not_me_reported', ['user_id' => $user->getKey()]);

        $this->revokeSessions->handle($user);
        $this->alerts->accountFrozen($user, AccountControls::FROZEN_NOT_ME);

        return $user;
    }
}
