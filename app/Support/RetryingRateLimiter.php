<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The app's rate limiter (SV1, 2026-10-30). Every limiter (the `livewire-update` throttle, logins, codes, uploads,
 * searches) keeps its counters in the `database` cache store: CACHE_STORE=database and config/cache.php has no
 * separate `limiter` store. Two requests of one user at the end of a window can deadlock there: reading an expired
 * counter deletes its row, DatabaseStore::add() then inserts the new one (INSERT IGNORE), and MySQL refuses one of the
 * two statements with SQLSTATE 40001 / 1213. That ended a Livewire action with a 500 twice in the verification of the
 * school's server.
 *
 * Every storage call of the limiter that MySQL refused that way is made again, at most ATTEMPTS times in all, after a
 * short random pause:
 * - one call at a time. A refused call leaves nothing behind that a repeat would apply twice: MySQL rolls the refused
 *   statement back (the increment runs in its own transaction, which is rolled back whole), and the expired row an
 *   add() may already have deleted stays deleted. Repeating the whole increment() instead could count an attempt twice:
 *   when its last call (the put after an increment that was already committed) were refused, the counter would rise
 *   once more. So increment(), tooManyAttempts() and clear() repeat the steps of Laravel 13's RateLimiter with each
 *   storage call wrapped; attempts(), resetAttempts() and availableIn() make one storage call each and wrap the parent.
 * - never inside an open transaction of the cache connection: there MySQL rolled back the whole outer transaction, and
 *   only its owner can start it again (DB::transaction() with attempts does that for a concurrency error).
 * - never after a lock wait timeout: that request already waited innodb_lock_wait_timeout; a repeat would hold the
 *   php-fpm worker as long again.
 * - any other error, and the last refusal, is thrown at once.
 * Bound in AppServiceProvider::register() with extend(): the same cache store and the named limiters of boot().
 */
final class RetryingRateLimiter extends RateLimiter
{
    use DetectsConcurrencyErrors;

    /** Tries per storage call, the first one included. */
    public const ATTEMPTS = 3;

    /** Random pause before the n-th repeat: n × 5–25 ms. */
    private const PAUSE_MICROSECONDS = [5_000, 25_000];

    /** The limiter CacheServiceProvider built: its cache store and the limiters registered on it so far. */
    public static function from(RateLimiter $limiter): self
    {
        $retrying = new self($limiter->cache);
        $retrying->limiters = $limiter->limiters;

        return $retrying;
    }

    /** The steps of parent::increment(), each storage call repeated on its own (see the class comment). */
    public function increment($key, $decaySeconds = 60, $amount = 1): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->retrying(fn (): bool => $this->cache->add(
            $key.':timer', $this->availableAt($decaySeconds), $decaySeconds
        ));

        $added = $this->retrying(fn (): bool => $this->withoutSerializationOrCompression(
            fn (): bool => $this->cache->add($key, 0, $decaySeconds)
        ));

        $hits = (int) $this->retrying(fn (): mixed => $this->cache->increment($key, $amount));

        if (! $added && $hits == $amount) {
            $this->retrying(fn (): bool => $this->withoutSerializationOrCompression(
                fn (): bool => $this->cache->put($key, $amount, $decaySeconds)
            ));
        }

        return $hits;
    }

    /** The steps of parent::tooManyAttempts(), the timer check repeated too. */
    public function tooManyAttempts($key, $maxAttempts): bool
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->retrying(fn (): bool => $this->cache->has($this->cleanRateLimiterKey($key).':timer'))) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    public function attempts($key): mixed
    {
        return $this->retrying(fn (): mixed => parent::attempts($key));
    }

    public function resetAttempts($key): bool
    {
        return (bool) $this->retrying(fn (): mixed => parent::resetAttempts($key));
    }

    public function availableIn($key): int
    {
        return (int) $this->retrying(fn (): mixed => parent::availableIn($key));
    }

    /** The steps of parent::clear(), the timer's removal repeated too. */
    public function clear($key): void
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->resetAttempts($key);

        $this->retrying(fn (): bool => $this->cache->forget($key.':timer'));
    }

    /**
     * Runs one storage call; a deadlock or serialization failure outside a transaction makes it again (class comment).
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function retrying(Closure $call): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (Throwable $e) {
                if ($attempt >= self::ATTEMPTS || ! $this->mayRepeat($e)) {
                    throw $e;
                }

                // Only the attempt number: a limiter key holds a user id or an address.
                Log::info('cache.limiter_retried', ['attempt' => $attempt, 'code' => $e->getCode()]);

                Sleep::usleep(random_int(...self::PAUSE_MICROSECONDS) * $attempt);
            }
        }
    }

    private function mayRepeat(Throwable $e): bool
    {
        if (! $this->causedByConcurrencyError($e) || str_contains($e->getMessage(), 'Lock wait timeout exceeded')) {
            return false;
        }

        $store = $this->cache->getStore();

        return ! ($store instanceof DatabaseStore && $store->getConnection()->transactionLevel() > 0);
    }
}
