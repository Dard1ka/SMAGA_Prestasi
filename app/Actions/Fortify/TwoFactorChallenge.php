<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Accounts\AccountControls;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Guards the staff TOTP challenge (`/two-factor-challenge`) between a correct password and the sign-in (QA10).
 * Fortify keeps only `login.id` in a guest session that has no user_id, so revoking the account's sessions never
 * reaches it, and it signs in on that id alone. Here:
 * - started(): when Fortify opens the challenge, the session also gets its start time and a keyed fingerprint of the
 *   password hash (never the hash itself);
 * - refusal(): the challenge is over when it is older than MAX_AGE_SECONDS, the password changed since it started
 *   (own change, Admin reset), the account may no longer log in or is frozen, or the account had MAX_FAILURES wrong
 *   codes within FAILURE_WINDOW_SECONDS (counted per account, so a new password login brings no new guesses);
 *   GuardedTwoFactorLoginRequest asks it on every request to the challenge and sends the browser back to the login;
 * - failed() / succeeded(): count wrong codes per account (`security.two_factor_challenge_ended` when the limit is
 *   reached; every wrong code is also an audit row, TwoFactorAuditSubscriber) and forget them after a sign-in.
 * Wired in FortifyServiceProvider; the per-minute limiter (`two-factor`, per account) is in AppServiceProvider.
 */
final class TwoFactorChallenge
{
    public const MAX_AGE_SECONDS = 600;

    public const MAX_FAILURES = 10;

    public const FAILURE_WINDOW_SECONDS = 3600;

    public const ENDED = 'Verifikasi dua langkah berakhir. Silakan masuk lagi.';

    private const SESSION_KEY = 'login.sth_challenge';

    public function __construct(
        private readonly Cache $cache,
        private readonly LoginLockout $lockout,
    ) {}

    public function started(TwoFactorAuthenticationChallenged $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        session()->put(self::SESSION_KEY, [
            'at' => now()->getTimestamp(),
            'password' => self::fingerprint($event->user),
        ]);
    }

    public function failed(TwoFactorAuthenticationFailed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $key = $this->failureKey($event->user);

        // Fixed window from the first wrong code: later ones do not stretch it.
        $this->cache->add($key.':until', now()->getTimestamp() + self::FAILURE_WINDOW_SECONDS, self::FAILURE_WINDOW_SECONDS);
        $this->cache->add($key, 0, self::FAILURE_WINDOW_SECONDS);
        $failures = (int) $this->cache->increment($key);

        if ($failures === self::MAX_FAILURES) {
            Log::warning('security.two_factor_challenge_ended', ['user_id' => $event->user->getKey(), 'failures' => $failures]);
        }
    }

    public function succeeded(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        if ($event->user instanceof User) {
            $this->cache->forget($this->failureKey($event->user));
            $this->cache->forget($this->failureKey($event->user).':until');
        }

        session()->forget(self::SESSION_KEY);
    }

    /** Why the open challenge may not go on (the text for the login page), or null. No challenge open: null. */
    public function refusal(Request $request): ?string
    {
        $id = $request->session()->get('login.id');

        if ($id === null) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);
        $user = User::query()->find($id);

        if (! $user instanceof User || ! is_array($state) || ! is_int($state['at'] ?? null)
            || now()->getTimestamp() - $state['at'] > self::MAX_AGE_SECONDS
            || ! is_string($state['password'] ?? null) || ! hash_equals($state['password'], self::fingerprint($user))
            || ! $user->status?->canLogIn() || AccountControls::isFrozen($user)) {
            return self::ENDED;
        }

        $key = $this->failureKey($user);

        if ((int) $this->cache->get($key, 0) >= self::MAX_FAILURES) {
            return AppServiceProvider::retryMessage(max(1, (int) $this->cache->get($key.':until', 0) - now()->getTimestamp()));
        }

        return null;
    }

    public function end(Request $request): void
    {
        $request->session()->forget(['login.id', 'login.remember', self::SESSION_KEY]);
    }

    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), 'sth-two-factor-challenge|'.config('app.key'));
    }

    /** Under the account's lock generation, so LoginLockout::clear() (Admin reset, unfreeze) forgets these too. */
    private function failureKey(User $user): string
    {
        return 'sth:2fa-fail:'.$this->lockout->scope(LoginIdentifier::accountBucket($user));
    }
}
