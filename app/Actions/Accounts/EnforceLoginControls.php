<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\AuthenticateUser;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Runs after AuthenticateUser accepted the password (FortifyServiceProvider wraps the callback), B1:
 * - K3/K8: a frozen account is refused;
 * - K1: an initial/temporary password whose window has passed is refused ("hubungi Admin" on the login page).
 * Both use the same generic message as a wrong password (K11), so the response never reveals whether the
 * password was right. A `Failed` event carrying the user is fired (not counted as a K3 failure).
 */
final class EnforceLoginControls
{
    /**
     * @throws ValidationException
     */
    public function handle(Request $request, User $user): void
    {
        if (AccountControls::isFrozen($user)) {
            Log::notice('security.login_refused_frozen', ['user_id' => $user->getKey(), 'reason' => AccountControls::frozenReason($user)]);

            $this->fail($request, $user);
        }

        if (AccountControls::initialPasswordExpired($user)) {
            Log::notice('security.initial_password_expired', ['user_id' => $user->getKey()]);

            $this->fail($request, $user);
        }
    }

    /**
     * @throws ValidationException
     */
    private function fail(Request $request, User $user): never
    {
        event(new Failed(config('fortify.guard', 'web'), $user, [
            Fortify::username() => (string) $request->input(Fortify::username()),
        ]));

        throw ValidationException::withMessages([Fortify::username() => AuthenticateUser::GENERIC_ERROR]);
    }
}
