<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Progressive lockout per account bucket (docs/SECURITY.md §3a): failure 5 → 1 min, 10 → 15 min,
 * 20 and every one after → 1 hour (config fortify.sth.login_lockout). Works the same for unknown
 * identifiers (stable HMAC bucket), so it does not reveal whether an account exists. Accounts are never
 * locked permanently; the counter expires 24 h after the last failure.
 */
final class LoginLockout
{
    private const COUNTER_TTL = 86400;

    public function __construct(private readonly Cache $cache) {}

    /**
     * @throws ValidationException
     */
    public function ensureNotLocked(Request $request, string $bucket): void
    {
        $until = (int) $this->cache->get($this->lockKey($bucket), 0);
        $remaining = $until - now()->getTimestamp();

        if ($remaining <= 0) {
            return;
        }

        event(new Lockout($request));

        throw ValidationException::withMessages([
            'identifier' => AppServiceProvider::retryMessage($remaining),
        ]);
    }

    public function recordFailure(string $bucket): void
    {
        $counterKey = $this->counterKey($bucket);
        $this->cache->add($counterKey, 0, self::COUNTER_TTL);
        $failures = (int) $this->cache->increment($counterKey);
        $this->cache->put($counterKey, $failures, self::COUNTER_TTL);

        $seconds = $this->lockSecondsFor($failures);

        if ($seconds === null) {
            return;
        }

        $this->cache->put($this->lockKey($bucket), now()->getTimestamp() + $seconds, $seconds);

        Log::warning('security.login_lockout', ['bucket' => $bucket, 'failures' => $failures, 'seconds' => $seconds]);
    }

    public function failures(string $bucket): int
    {
        return (int) $this->cache->get($this->counterKey($bucket), 0);
    }

    public function clear(string $bucket): void
    {
        $this->cache->forget($this->counterKey($bucket));
        $this->cache->forget($this->lockKey($bucket));
    }

    private function lockSecondsFor(int $failures): ?int
    {
        /** @var array<int, int> $steps */
        $steps = config('fortify.sth.login_lockout', []);
        ksort($steps);

        if (isset($steps[$failures])) {
            return (int) $steps[$failures];
        }

        $last = array_key_last($steps);

        return $last !== null && $failures > $last ? (int) $steps[$last] : null;
    }

    private function counterKey(string $bucket): string
    {
        return 'sth:login-fail:'.$bucket;
    }

    private function lockKey(string $bucket): string
    {
        return 'sth:login-lock:'.$bucket;
    }
}
