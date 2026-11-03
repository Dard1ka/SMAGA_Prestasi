<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends a user's sessions everywhere: deletes rows from the database session table (SESSION_DRIVER=database)
 * and rotates the remember token so "remember me" cookies stop working. Optionally keeps one session
 * (the caller's current one, e.g. after a password change).
 */
final class RevokeUserSessions
{
    public function handle(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
