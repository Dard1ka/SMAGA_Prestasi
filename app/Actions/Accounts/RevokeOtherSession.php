<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Akun › Keamanan (B1): sign out one other device (by its HMAC key from ListUserSessions) or every other
 * device (RevokeUserSessions, which also rotates the remember token). Only the user's own sessions, never
 * the current one. Audited (`account.session_revoked`, `account.sessions_revoked`).
 */
final class RevokeOtherSession
{
    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    public function one(User $user, string $key, ?string $currentSessionId): bool
    {
        if (! ListUserSessions::available()) {
            return false;
        }

        $table = DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'));

        $id = $table->clone()
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('id')
            ->first(fn (string $sessionId): bool => hash_equals(ListUserSessions::key($sessionId), $key)
                && ($currentSessionId === null || ! hash_equals($sessionId, $currentSessionId)));

        if ($id === null) {
            return false;
        }

        $table->clone()->where('id', $id)->where('user_id', $user->getAuthIdentifier())->delete();

        activity('auth')->performedOn($user)->causedBy($user)
            ->event('account.session_revoked')
            ->log('account.session_revoked');

        return true;
    }

    public function others(User $user, ?string $currentSessionId): void
    {
        $this->revokeSessions->handle($user, $currentSessionId);

        activity('auth')->performedOn($user)->causedBy($user)
            ->event('account.sessions_revoked')
            ->log('account.sessions_revoked');
    }
}
