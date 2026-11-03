<?php

declare(strict_types=1);

use App\Actions\Fortify\LoginLockout;
use App\Providers\AppServiceProvider;
use App\Support\RetryingRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Sleep;

/*
 | SV1 (browser verification for the school's server, 2026-10-30): every rate limiter counts in the `database` cache
 | store, and two requests of one user at the end of a window deadlocked there (SQLSTATE 40001 / 1213), which answered
 | a Livewire action with a 500. RetryingRateLimiter makes a refused storage call again, one call at a time, so an
 | attempt is never counted twice; any other error, a refusal inside an open transaction and the third refusal of one
 | call are thrown at once.
 */

uses(RefreshDatabase::class);

/** What MySQL raises for the refused statement of a deadlock (SQLSTATE 40001, error 1213). */
function limiterRetryDeadlock(string $sql = 'insert ignore into `cache` (`expiration`, `key`, `value`) values (?, ?, ?)'): QueryException
{
    $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    (new ReflectionProperty($pdo, 'code'))->setValue($pdo, '40001');
    $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    return new QueryException('mysql', $sql, [], $pdo);
}

/**
 * An array store whose next calls of the named operations are refused with the given errors, as MySQL refuses a
 * statement in a deadlock: the refused call writes nothing. `calls` counts every call per operation.
 *
 * @param  array<string, list<Throwable>>  $refusals  get | put | increment | forget => errors, in order
 */
function limiterRetryFlakyStore(array $refusals = []): ArrayStore
{
    return new class($refusals) extends ArrayStore
    {
        /** @var array<string, int> */
        public array $calls = [];

        /** @param array<string, list<Throwable>> $refusals */
        public function __construct(public array $refusals)
        {
            parent::__construct();
        }

        public function get($key)
        {
            $this->refuseOrCount('get');

            return parent::get($key);
        }

        public function put($key, $value, $seconds)
        {
            $this->refuseOrCount('put');

            return parent::put($key, $value, $seconds);
        }

        public function increment($key, $value = 1)
        {
            $this->refuseOrCount('increment');

            return parent::increment($key, $value);
        }

        public function forget($key)
        {
            $this->refuseOrCount('forget');

            return parent::forget($key);
        }

        private function refuseOrCount(string $operation): void
        {
            $this->calls[$operation] = ($this->calls[$operation] ?? 0) + 1;

            if (($this->refusals[$operation] ?? []) !== []) {
                throw array_shift($this->refusals[$operation]);
            }
        }
    };
}

/**
 * The real database store on a cache table of its own (a second in-memory SQLite database, so no test transaction is
 * open around it), whose first `$refusedAdds` add() calls are refused like the INSERT IGNORE of a deadlock.
 */
function limiterRetryDatabaseStore(int $refusedAdds): DatabaseStore
{
    config(['database.connections.limiter_retry' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);

    $connection = DB::connection('limiter_retry');
    $connection->getSchemaBuilder()->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->bigInteger('expiration')->index();
    });

    return new class($connection, $refusedAdds) extends DatabaseStore
    {
        public int $adds = 0;

        public function __construct(Connection $connection, public int $refuse)
        {
            parent::__construct($connection, 'cache');
        }

        public function add($key, $value, $seconds)
        {
            $this->adds++;

            if ($this->refuse > 0) {
                $this->refuse--;

                throw limiterRetryDeadlock();
            }

            return parent::add($key, $value, $seconds);
        }
    };
}

beforeEach(function (): void {
    Sleep::fake();
});

it('gives every caller the retrying limiter, on the configured cache store, with the named limiters of boot()', function (): void {
    $limiter = app(RateLimiter::class);

    expect($limiter)->toBeInstanceOf(RetryingRateLimiter::class)
        ->and(RateLimiterFacade::getFacadeRoot())->toBe($limiter)
        ->and($limiter->limiter('livewire-update'))->not->toBeNull()
        ->and($limiter->limiter('two-factor'))->not->toBeNull()
        ->and($limiter->limiter(LoginLockout::LIMITER))->not->toBeNull();

    // The same store as before the change: the counter is a key of the default cache store.
    $limiter->hit('sv1-store', 60);
    expect(Cache::get('sv1-store'))->toBe(1);
});

it('still answers the livewire-update throttle with its 429 text', function (): void {
    config(['livewire.sth_throttle.guest_per_minute' => 1]);
    $uri = app('livewire')->getUpdateUri();

    $this->postJson($uri, ['components' => []], ['X-Livewire' => '1'])->assertNotFound();
    $blocked = $this->postJson($uri, ['components' => []], ['X-Livewire' => '1']);

    $blocked->assertStatus(429)->assertHeader('Retry-After');
    expect($blocked->getContent())->toBe(AppServiceProvider::LIVEWIRE_THROTTLED_MESSAGE);
});

it('counts, limits and clears exactly like Laravel\'s limiter when nothing is refused', function (): void {
    $this->freezeTime();

    $run = function (RateLimiter $limiter): array {
        $seen = [];

        foreach (range(1, 4) as $i) {
            $seen[] = $limiter->hit('kunci', 60);
        }

        $seen[] = $limiter->tooManyAttempts('kunci', 3);
        $seen[] = $limiter->remaining('kunci', 6);
        $seen[] = $limiter->availableIn('kunci');
        $seen[] = $limiter->attempt('sekali', 1, fn (): string => 'jalan');
        $seen[] = $limiter->attempt('sekali', 1, fn (): string => 'jalan');
        $seen[] = $limiter->decrement('kunci', 60);
        $limiter->clear('kunci');
        $seen[] = $limiter->attempts('kunci');
        $seen[] = $limiter->tooManyAttempts('kunci', 3);
        $seen[] = $limiter->hit('lama', 10);

        return $seen;
    };

    $plain = new RateLimiter(new Repository(new ArrayStore));
    $retrying = new RetryingRateLimiter(new Repository(new ArrayStore));

    expect($run($retrying))->toBe($run($plain));

    // After the window: both start again from 1.
    $this->travel(61)->seconds();
    expect([$retrying->tooManyAttempts('sekali', 1), $retrying->hit('lama', 10)])
        ->toBe([$plain->tooManyAttempts('sekali', 1), $plain->hit('lama', 10)]);

    Sleep::assertNeverSlept();
});

it('makes a refused insert of the database store again and counts the attempt once', function (): void {
    $store = limiterRetryDatabaseStore(refusedAdds: 1);
    $limiter = new RetryingRateLimiter(new Repository($store));

    expect($limiter->hit('lw-user:7', 60))->toBe(1)
        ->and($limiter->hit('lw-user:7', 60))->toBe(2)
        ->and((int) $limiter->attempts('lw-user:7'))->toBe(2)
        // Two add() calls per hit (timer and counter) and the refused one.
        ->and($store->adds)->toBe(5);

    Sleep::assertSleptTimes(1);
});

it('makes a refused increment again and counts the attempt once', function (): void {
    $store = limiterRetryFlakyStore(['increment' => [limiterRetryDeadlock('select * from `cache` where `key` = ? limit 1 for update')]]);
    $limiter = new RetryingRateLimiter(new Repository($store));

    expect($limiter->hit('kunci'))->toBe(1)
        ->and($limiter->hit('kunci'))->toBe(2)
        ->and($store->calls['increment'])->toBe(3);

    Sleep::assertSleptTimes(1);
});

it('never counts an attempt twice when the last write of increment() is refused', function (): void {
    $store = limiterRetryFlakyStore();
    $cache = new Repository($store);
    $limiter = new RetryingRateLimiter($cache);

    // Another request has just opened the window (timer, counter 0) but not raised it yet: increment() then raises the
    // counter and writes it once more with a fresh lifetime (its last step), and that write is refused.
    $cache->put('kunci:timer', now()->addMinute()->getTimestamp(), 60);
    $cache->put('kunci', 0, 60);
    $store->calls = [];
    $store->refusals['put'] = [limiterRetryDeadlock('insert into `cache` … on duplicate key update …')];

    // Repeating the whole increment() would answer 2 here and store 2.
    expect($limiter->hit('kunci'))->toBe(1)
        ->and($cache->get('kunci'))->toBe(1)
        ->and($store->calls['increment'])->toBe(1)
        ->and($store->calls['put'])->toBe(2);

    Sleep::assertSleptTimes(1);
});

it('makes a refused read or removal of the counter and the timer again', function (): void {
    $store = limiterRetryFlakyStore();
    $cache = new Repository($store);
    $limiter = new RetryingRateLimiter($cache);
    $limiter->hit('kunci');
    $limiter->hit('kunci');

    // tooManyAttempts(): the counter, then the timer, each refused once.
    $store->refusals['get'] = [limiterRetryDeadlock(), limiterRetryDeadlock()];
    expect($limiter->tooManyAttempts('kunci', 2))->toBeTrue();

    $store->refusals['get'] = [limiterRetryDeadlock()];
    expect($limiter->availableIn('kunci'))->toBeGreaterThan(0);

    // clear(): the counter, then the timer, each refused once.
    $store->refusals['forget'] = [limiterRetryDeadlock('delete from `cache` where `key` in (?, ?)'), limiterRetryDeadlock('delete from `cache` where `key` in (?, ?)')];
    $limiter->clear('kunci');

    expect($limiter->attempts('kunci'))->toBe(0)
        ->and($cache->has('kunci:timer'))->toBeFalse();

    Sleep::assertSleptTimes(5);
});

it('throws any other database error at once, without a repeat', function (): void {
    $missing = new QueryException('mysql', 'select * from `cache`', [], new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'sth.cache' doesn't exist"));
    $store = limiterRetryFlakyStore(['increment' => [$missing]]);
    $limiter = new RetryingRateLimiter(new Repository($store));

    expect(fn () => $limiter->hit('kunci'))->toThrow(QueryException::class, 'Base table or view not found')
        ->and($store->calls['increment'])->toBe(1);

    Sleep::assertNeverSlept();
});

it('gives up after three refusals of one call and throws the last one', function (): void {
    $store = limiterRetryFlakyStore(['increment' => [
        limiterRetryDeadlock('percobaan 1'),
        limiterRetryDeadlock('percobaan 2'),
        limiterRetryDeadlock('percobaan 3'),
    ]]);
    $limiter = new RetryingRateLimiter(new Repository($store));

    expect(fn () => $limiter->hit('kunci'))->toThrow(QueryException::class, 'percobaan 3')
        ->and($store->calls['increment'])->toBe(RetryingRateLimiter::ATTEMPTS);

    Sleep::assertSleptTimes(2);
});

it('does not repeat a call that waited for a lock until the timeout', function (): void {
    $timeout = new QueryException('mysql', 'select * from `cache` where `key` = ? limit 1 for update', [], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
    $store = limiterRetryFlakyStore(['increment' => [$timeout]]);
    $limiter = new RetryingRateLimiter(new Repository($store));

    expect(fn () => $limiter->hit('kunci'))->toThrow(QueryException::class, 'Lock wait timeout exceeded')
        ->and($store->calls['increment'])->toBe(1);

    Sleep::assertNeverSlept();
});

it('does not repeat a refused call inside an open transaction of the cache connection', function (): void {
    $store = limiterRetryDatabaseStore(refusedAdds: 1);
    $limiter = new RetryingRateLimiter(new Repository($store));

    // MySQL rolled the whole outer transaction back: only its owner may start it again.
    expect(fn () => DB::connection('limiter_retry')->transaction(fn (): int => $limiter->hit('kunci')))
        ->toThrow(QueryException::class, 'Deadlock found')
        ->and($store->adds)->toBe(1);

    Sleep::assertNeverSlept();

    // Outside a transaction the next attempt counts as usual.
    expect($limiter->hit('kunci'))->toBe(1);
});

it('repeats the steps of the RateLimiter it was written for', function (): void {
    // increment(), tooManyAttempts() and clear() of RetryingRateLimiter repeat the steps of Laravel 13's RateLimiter with
    // each storage call wrapped, and the other methods rely on these calling each other. When this fails after a
    // Laravel update, compare RetryingRateLimiter with the new Illuminate\Cache\RateLimiter, then put the new value here.
    $source = function (string $method): string {
        $reflection = new ReflectionMethod(RateLimiter::class, $method);
        $lines = array_slice(file((string) $reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1);

        return (string) preg_replace('/\s+/', ' ', trim(implode('', $lines)));
    };

    $methods = ['attempt', 'tooManyAttempts', 'hit', 'increment', 'decrement', 'attempts', 'resetAttempts', 'remaining', 'clear', 'availableIn'];

    expect(sha1(implode("\n", array_map($source, $methods))))->toBe('10a1fc47b0e1e488e80fdca995fd163f0054716e');
});
