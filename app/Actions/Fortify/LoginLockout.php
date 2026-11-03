<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Throwable;

/**
 * Login locks and limits (docs/SECURITY.md §3a). Works the same for unknown identifiers (stable HMAC bucket), so it
 * does not reveal whether an account exists. Accounts are never locked permanently.
 *
 * - Progressive lock per account AND client address (QA10, K3): failure 5 → 1 min, 10 → 15 min, 20 and every one
 *   after → 1 hour (config fortify.sth.login_lockout). A classmate who knows the NIS only locks the address the wrong
 *   passwords come from. An IPv6 address counts as its /64 (addressKey()).
 * - Per account (QA10 review): ACCOUNT_LOCK_FAILURES failures within 24 h from every address close the account to
 *   devices that never signed in to it (KnownDevices) until those 24 h are over; the owner's own devices still get in,
 *   and the Admins are alerted once (LoginAttackAlert). The total also feeds `security.login_failures_account`.
 * - The lock and the per-account route limits are kept apart for a browser the account signed in from before
 *   (pair(), QA10 second review): a classmate behind the same school address cannot use up or lock the owner's
 *   known device.
 * - The route limits of `throttle:login` (limits(), per account and address, plus per address) are defined here too,
 *   so that secondsUntilAllowed() can answer every refusal with ONE time: the longest of every block that applies
 *   (QA10, the lead's report of times that jumped back and forth). A refused request counts towards the per-address
 *   limit only (countRefusal()), never towards the account's, so it cannot stretch a block.
 * Counters run 24 h from their first failure (a failure does not extend them). A successful login clears the counter
 * of its own address only; clear() (reset with the email code, Admin reset, "Buka ulang", unfreeze) starts a new
 * generation of the account, which empties every counter, lock and route limit of it, the TOTP failures of
 * TwoFactorChallenge, and the OTP limits (IssueOtpCode counts codes after clearedAt()).
 */
final class LoginLockout
{
    /** Failures of one account within 24 h, from every address, that close it to unknown devices. */
    public const ACCOUNT_LOCK_FAILURES = 30;

    /** Name of the route limiter (`throttle:login`, config fortify.limiters.login). */
    public const LIMITER = 'login';

    private const COUNTER_TTL = 86400;

    /**
     * A generation is kept two days, longer than the 24 h counters and the 1 h locks and limits made right after it.
     * When it expires the account falls back to generation "0": what was still counted under the expired generation
     * (a counter or lock started late in it) is released early. That only ever shortens a block.
     */
    private const GENERATION_TTL = 2 * 86400;

    public function __construct(
        private readonly Cache $cache,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * The `throttle:login` limits of this account and address (and device kind, see pair()). Counted before the password
     * check (so parallel requests cannot get extra guesses), except the hourly one, which counts refused passwords only
     * (the owner's own logins never fill it). The per-address limit counts every device and every account.
     *
     * @return list<Limit>
     */
    public function limits(Request $request, string $bucket, bool $knownDevice = false): array
    {
        $pair = $this->pair($request, $bucket, $knownDevice);

        return [
            Limit::perMinute(5)->by('login:'.$pair),
            Limit::perHour(20)->by('login-h:'.$pair)->after(fn (): bool => AuthenticateUser::failed($request)),
            $this->addressLimit($request),
        ];
    }

    /**
     * Seconds until a correct login of this account from this browser and address could succeed: the longest of the
     * lock of the address, the account's lock for unknown devices and every full route limit. 0 = nothing blocks.
     */
    public function secondsUntilAllowed(Request $request, string $bucket, bool $knownDevice = false): int
    {
        $wait = $this->lockSeconds($request, $bucket, $knownDevice);

        foreach ($this->limits($request, $bucket, $knownDevice) as $limit) {
            $key = self::limiterKey($limit);

            if ($this->limiter->tooManyAttempts($key, $limit->maxAttempts)) {
                $wait = max($wait, $this->limiter->availableIn($key));
            }
        }

        return $wait;
    }

    /**
     * A try refused before its password was checked counts towards the per-address limit only (QA10, second review):
     * never towards the limits of the account, so it cannot stretch a block, but one address cannot send refused tries
     * without end either.
     */
    public function countRefusal(Request $request): void
    {
        $limit = $this->addressLimit($request);

        $this->limiter->hit(self::limiterKey($limit), $limit->decaySeconds);
    }

    /**
     * Refuses the password check while a lock holds (the route limits were checked, and this try counted, by
     * `throttle:login` already). The message names the time of every block, as the route limiter's does.
     *
     * @throws ValidationException
     */
    public function ensureNotLocked(Request $request, string $bucket, bool $knownDevice = false): void
    {
        if ($this->lockSeconds($request, $bucket, $knownDevice) <= 0) {
            return;
        }

        event(new Lockout($request));

        throw ValidationException::withMessages([
            'identifier' => AppServiceProvider::retryMessage(max(1, $this->secondsUntilAllowed($request, $bucket, $knownDevice))),
        ]);
    }

    /** Seconds left of the lock of this address and of the account's lock for unknown devices (0 = none). */
    private function lockSeconds(Request $request, string $bucket, bool $knownDevice): int
    {
        $now = now()->getTimestamp();
        $wait = (int) $this->cache->get($this->lockKey($this->pair($request, $bucket, $knownDevice)), 0) - $now;

        if (! $knownDevice && $this->failures($bucket) >= self::ACCOUNT_LOCK_FAILURES) {
            $wait = max($wait, (int) $this->cache->get($this->counterKey($this->scope($bucket)).':until', 0) - $now);
        }

        return max(0, $wait);
    }

    /**
     * A refused password (or unknown identifier). $account = the account the identifier names, if any. The lock is
     * written before the alert, and a failing alert is only reported, so the 30th failure of a real account is answered
     * exactly like that of an unknown identifier.
     */
    public function recordFailure(Request $request, string $bucket, ?User $account = null, bool $knownDevice = false): void
    {
        $pair = $this->pair($request, $bucket, $knownDevice);
        $failures = $this->count($this->counterKey($pair));
        $total = $this->count($this->counterKey($this->scope($bucket)));
        $seconds = $this->lockSecondsFor($failures);

        if ($seconds !== null) {
            $this->cache->put($this->lockKey($pair), now()->getTimestamp() + $seconds, $seconds);

            Log::warning('security.login_lockout', [
                'bucket' => $bucket,
                'failures' => $failures,
                'account_failures' => $total,
                'seconds' => $seconds,
            ]);
        }

        if ($this->stepReached($total)) {
            Log::warning('security.login_failures_account', ['bucket' => $bucket, 'failures' => $total]);
        }

        if ($total !== self::ACCOUNT_LOCK_FAILURES) {
            return;
        }

        Log::warning('security.login_account_locked', ['bucket' => $bucket, 'failures' => $total]);

        if ($account !== null) {
            try {
                app(LoginAttackAlert::class)->handle($account, $total);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** The right password from this address and device kind: its own counter and lock go, every other keeps its own. */
    public function succeeded(Request $request, string $bucket, bool $knownDevice = false): void
    {
        $pair = $this->pair($request, $bucket, $knownDevice);

        $this->cache->forget($this->counterKey($pair));
        $this->cache->forget($this->lockKey($pair));
    }

    /** Failures of the account from every address within the counter window. */
    public function failures(string $bucket): int
    {
        return (int) $this->cache->get($this->counterKey($this->scope($bucket)), 0);
    }

    /**
     * Everything of the account, from every address: counters, locks, route limits, TOTP failures, OTP limits and the
     * "Lupa kata sandi" screen limits (ResetCodeLimits keys them under scope()).
     */
    public function clear(string $bucket): void
    {
        $this->cache->put($this->generationKey($bucket), Str::random(12), self::GENERATION_TTL);
        $this->cache->put($this->clearedKey($bucket), now()->getTimestamp(), self::GENERATION_TTL);
    }

    /** When clear() last ran for the account (within two days), as a Unix time; null = not recently. */
    public function clearedAt(string $bucket): ?int
    {
        $at = $this->cache->get($this->clearedKey($bucket));

        return is_int($at) ? $at : null;
    }

    /** The account bucket of the current generation ("u:12#…"); the per-address keys hang below it. */
    public function scope(string $bucket): string
    {
        return $bucket.'#'.(string) $this->cache->get($this->generationKey($bucket), '0');
    }

    /**
     * Account, client address and device kind: the unit that is limited and locked. A browser the account signed in
     * from before (KnownDevices) has its own unit ("|k"), so a classmate behind the same school address never uses up
     * the owner's tries or locks the owner's own device (QA10, second review); the owner's wrong passwords on that device
     * still lock it. An identifier without an account is never "known".
     */
    public function pair(Request $request, string $bucket, bool $knownDevice = false): string
    {
        return $this->scope($bucket).'|'.self::addressKey($request->ip()).($knownDevice ? '|k' : '');
    }

    /** Every login try from one address (any account, any device): 60 a minute. */
    private function addressLimit(Request $request): Limit
    {
        return Limit::perMinute(60)->by('login-ip:'.self::addressKey($request->ip()));
    }

    /**
     * The address a limit counts: an IPv4 address as it is, an IPv6 address as its /64 (one home or phone network
     * gets a whole /64, so the full address would give an attacker endless fresh addresses).
     */
    public static function addressKey(?string $ip): string
    {
        $ip = (string) $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false || ($binary = inet_pton($ip)) === false) {
            return $ip;
        }

        // An IPv4 address written as IPv6 (::ffff:a.b.c.d) is that IPv4 address.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        return bin2hex(substr($binary, 0, 8)).'::/64';
    }

    /**
     * The cache key ThrottleRequests keeps a limit of the named limiter under (handleRequestUsingNamedLimiter(): hashed
     * unless ThrottleRequests::shouldHashKeys(false) was called; the flag itself is protected).
     */
    private static function limiterKey(Limit $limit): string
    {
        $hashed = (bool) (new ReflectionProperty(ThrottleRequests::class, 'shouldHashKeys'))->getValue();

        return $hashed ? md5(self::LIMITER.$limit->key) : self::LIMITER.':'.$limit->key;
    }

    /** Increments a counter that expires 24 h after its first failure (":until" keeps that moment). */
    private function count(string $key): int
    {
        $this->cache->add($key.':until', now()->getTimestamp() + self::COUNTER_TTL, self::COUNTER_TTL);
        $this->cache->add($key, 0, self::COUNTER_TTL);

        return (int) $this->cache->increment($key);
    }

    private function lockSecondsFor(int $failures): ?int
    {
        $steps = $this->steps();

        if (isset($steps[$failures])) {
            return (int) $steps[$failures];
        }

        $last = array_key_last($steps);

        return $last !== null && $failures > $last ? (int) $steps[$last] : null;
    }

    /** The account total hits a lock step (5, 10, 20), then every further multiple of the last step (40, 60, …). */
    private function stepReached(int $failures): bool
    {
        $steps = $this->steps();
        $last = array_key_last($steps);

        return isset($steps[$failures]) || ($last !== null && $failures > $last && $failures % $last === 0);
    }

    /** @return array<int, int> [failures => lockout seconds], ascending */
    private function steps(): array
    {
        /** @var array<int, int> $steps */
        $steps = config('fortify.sth.login_lockout', []);
        ksort($steps);

        return $steps;
    }

    private function counterKey(string $scope): string
    {
        return 'sth:login-fail:'.$scope;
    }

    private function lockKey(string $pair): string
    {
        return 'sth:login-lock:'.$pair;
    }

    private function generationKey(string $bucket): string
    {
        return 'sth:login-gen:'.$bucket;
    }

    private function clearedKey(string $bucket): string
    {
        return 'sth:login-cleared:'.$bucket;
    }
}
