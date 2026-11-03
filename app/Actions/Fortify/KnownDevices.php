<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Browsers an account has signed in from (QA10 review). After a full login (password, and the TOTP code for staff) the
 * browser gets a long-lived, httpOnly cookie with a keyed token per account (at most MAX_ACCOUNTS accounts, newest
 * last). Laravel encrypts and authenticates every cookie, so nobody can make one for another account. The token is
 * bound to the account and its current password: a password change (own, by code, by an Admin) forgets every device.
 * LoginLockout lets a known device in while the account is closed to unknown devices after many failures (K3: the
 * owner keeps a way in that a classmate cannot block).
 */
final class KnownDevices
{
    public const COOKIE = 'sth_device';

    public const MAX_ACCOUNTS = 8;

    public const LIFETIME_MINUTES = 180 * 24 * 60;

    public static function recognizes(Request $request, ?User $account): bool
    {
        return $account !== null && in_array(self::token($account), self::tokens($request), true);
    }

    /** Listener of the Login event: this browser is now a known device of the account. */
    public static function remember(User $user): void
    {
        $token = self::token($user);
        $tokens = array_values(array_diff(self::tokens(request()), [$token]));
        $tokens[] = $token;

        // Path, domain, Secure and SameSite follow the session cookie (config/session.php).
        Cookie::queue(Cookie::make(
            self::COOKIE,
            (string) json_encode(array_slice($tokens, -self::MAX_ACCOUNTS)),
            self::LIFETIME_MINUTES,
            null,
            null,
            null,
            true,
        ));
    }

    /** @return list<string> */
    private static function tokens(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);
        $tokens = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($tokens) ? array_values(array_filter($tokens, is_string(...))) : [];
    }

    private static function token(User $user): string
    {
        return substr(hash_hmac('sha256', 'device|'.$user->getKey().'|'.$user->getAuthPassword(), (string) config('app.key')), 0, 32);
    }
}
