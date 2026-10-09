<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginLockout;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The screen limits of "Lupa kata sandi" (App\Livewire\Auth\ForgotPassword): asking for a code (SEND) and checking one
 * (VERIFY). Keyed on the account the identifier resolves to, as at login (NIS, NISN and email of one student share it;
 * an identifier without an account has its own stable bucket, so nothing reveals whether it exists) AND the client
 * address (an IPv6 address as its /64), plus a looser limit per address over every account (QA10 review: a limit per
 * account alone let a stranger who knows a NIS use up the owner's tries). The codes themselves are limited per browser
 * and per account by IssueOtpCode and VerifyOtpCode. Code requests per address: 30 an hour (SECURITY §3b). The
 * per-account keys hang under the account's lock generation (LoginLockout::scope()), so an Admin reset, an unfreeze,
 * "Buka ulang" or a reset by code releases them as well (QA10, second review).
 */
final class ResetCodeLimits
{
    public const SEND = 'forgot-send';

    public const VERIFY = 'forgot-verify';

    /** step => list of [key suffix, tries, window in seconds] */
    private const LIMITS = [
        self::SEND => [['account', 3, 900], ['account-day', 10, 86400], ['address', 30, 3600]],
        self::VERIFY => [['account', 10, 600], ['address', 30, 600]],
    ];

    public function __construct(
        private readonly LoginIdentifier $identifiers,
        private readonly LoginLockout $lockout,
    ) {}

    /** Counts the try and returns null, or the seconds to wait when a limit is full (nothing is counted then). */
    public function hit(string $step, string $identifier, string $ip): ?int
    {
        $address = LoginLockout::addressKey($ip);
        $account = $this->lockout->scope($this->identifiers->bucketFor($identifier)).'|'.$address;
        $limits = [];

        foreach (self::LIMITS[$step] ?? [] as [$kind, $max, $decay]) {
            $key = match ($kind) {
                'account' => $step.':'.$account,
                'account-day' => $step.'-d:'.$account,
                default => $step.'-ip:'.$address,
            };

            $limits[] = [$key, $max, $decay];
        }

        $wait = 0;

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key), 1);
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($limits as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }
}
