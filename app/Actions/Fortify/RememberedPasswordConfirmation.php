<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Http\Middleware\RestrictTwoFactorToStaff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Date;

/**
 * The lead's request of 2026-11-05: a staff account that typed its password in this browser (the login, or the
 * password page `/user/confirm-password`) is not asked for it again by RequiresRecentPassword or the
 * `password.confirm` middleware for 30 days in that browser, also after "Keluar" and a new login.
 *
 * How: an httpOnly, SameSite=Lax cookie (Secure on https) whose value Laravel encrypts and authenticates
 * (EncryptCookies), holding the account id, the moment it ends and a keyed fingerprint of the account's current
 * password hash. A password change (own, by code, by an Admin reset) changes the hash and ends it; another account
 * on the same browser gets nothing from it; a student account never gets one. The middleware
 * RestoreRememberedPasswordConfirmation reads it on every request of the `web` group and refreshes
 * `auth.password_confirmed_at` in the session, which is what both checks read. Nothing of it is logged.
 */
final class RememberedPasswordConfirmation
{
    public const COOKIE = 'sth_password_confirmed';

    public const LIFETIME_DAYS = 30;

    /** The session value is refreshed at most once per this many seconds (no session write on every request). */
    private const REFRESH_SECONDS = 60;

    /** Set the cookie after the account typed its password (login, confirm page). Students never get one. */
    public static function remember(User $user, ?Request $request = null): void
    {
        if (! self::eligible($user)) {
            return;
        }

        $request ??= request();
        $payload = [
            'u' => $user->getKey(),
            'e' => Date::now()->getTimestamp() + self::LIFETIME_DAYS * 86400,
        ];
        $payload['p'] = self::fingerprint($user, (int) $payload['e']);

        Cookie::queue(Cookie::make(
            self::COOKIE,
            (string) json_encode($payload),
            self::LIFETIME_DAYS * 24 * 60,
            null,
            null,
            $request->isSecure() ? true : null,
            true,
            false,
            'lax',
        ));
    }

    /**
     * Middleware step: a valid cookie of the signed-in staff account counts as a fresh password confirmation;
     * an invalid one (another account, a changed password, expired, tampered) is removed.
     */
    public static function restore(Request $request): void
    {
        $raw = $request->cookie(self::COOKIE);

        if ($raw === null || ! $request->hasSession()) {
            return;
        }

        $user = $request->user();

        // A guest keeps it: the same account logging in again sets a new one anyway.
        if (! $user instanceof User) {
            return;
        }

        if (! is_string($raw) || ! self::validFor($user, $raw)) {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return;
        }

        $session = $request->session();

        $now = Date::now()->getTimestamp();

        if ($now - (int) $session->get('auth.password_confirmed_at', 0) > self::REFRESH_SECONDS) {
            $session->put('auth.password_confirmed_at', $now);
        }
    }

    public static function validFor(User $user, string $raw): bool
    {
        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! is_int($payload['e'] ?? null) || ! is_string($payload['p'] ?? null)) {
            return false;
        }

        return (string) ($payload['u'] ?? '') === (string) $user->getKey()
            && $payload['e'] >= Date::now()->getTimestamp()
            && hash_equals(self::fingerprint($user, $payload['e']), $payload['p'])
            && self::eligible($user);
    }

    /** Staff only, by the same test as TOTP (no `siswa` role and no student record). */
    private static function eligible(User $user): bool
    {
        return RestrictTwoFactorToStaff::allows($user);
    }

    private static function fingerprint(User $user, int $expires): string
    {
        return hash_hmac(
            'sha256',
            'password-confirmed|'.$user->getKey().'|'.$expires.'|'.$user->getAuthPassword(),
            (string) config('app.key'),
        );
    }
}
