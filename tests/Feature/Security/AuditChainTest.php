<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Support\Audit\AuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | Backlog #16 / SECURITY §3m-9: hash chain over activity_log. Each committed row gets a seal
 | (hash = sha256(prev_hash|payload)); `sth:audit:verify` detects deleted, edited or truncated rows.
 */

beforeEach(function (): void {
    H::seedRoles();
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('security');
    $this->admin = H::staff(RoleName::Admin);
});

function b2LogActivities(int $count, string $log = 'account'): void
{
    for ($i = 1; $i <= $count; $i++) {
        activity($log)->event('account.reset')->withProperties(['reason' => "alasan {$i}", 'nested' => ['b' => $i, 'a' => 'x']])->log('account.reset');
    }
}

function b2Verify(): array
{
    $code = Artisan::call('sth:audit:verify');

    return [$code, Artisan::output()];
}

it('seals every committed activity row in a linked chain', function (): void {
    b2LogActivities(3);

    $total = Activity::query()->count();
    $seals = DB::table('audit_seals')->orderBy('id')->get();

    expect($seals)->toHaveCount($total)
        ->and($seals->first()->prev_hash)->toBeNull();

    foreach ($seals->values() as $i => $seal) {
        $row = DB::table('activity_log')->where('id', $seal->activity_id)->first();
        expect($seal->hash)->toBe(AuditChain::hash($seal->prev_hash, AuditChain::payload($row)));

        if ($i > 0) {
            expect($seal->prev_hash)->toBe($seals[$i - 1]->hash);
        }
    }

    expect(app(AuditChain::class)->head())->toBe(['seal_id' => (int) $seals->last()->id, 'hash' => $seals->last()->hash]);

    [$code, $output] = b2Verify();
    expect($code)->toBe(0)->and($output)->toContain("Rantai audit utuh: {$total} segel diperiksa.");
});

it('detects an edited audit row', function (): void {
    b2LogActivities(3);
    $target = Activity::query()->where('event', 'account.reset')->orderBy('id')->skip(1)->first();

    DB::table('activity_log')->where('id', $target->getKey())->update(['properties' => json_encode(['reason' => 'diubah diam-diam'])]);

    [$code, $output] = b2Verify();

    expect($code)->toBe(1)
        ->and($output)->toContain("Baris audit #{$target->getKey()} diubah.")
        ->and($output)->toContain('Rantai audit RUSAK');
});

it('detects a deleted audit row', function (): void {
    b2LogActivities(3);
    $target = Activity::query()->where('event', 'account.reset')->orderBy('id')->first();

    DB::table('activity_log')->where('id', $target->getKey())->delete();

    [$code, $output] = b2Verify();

    expect($code)->toBe(1)->and($output)->toContain("Baris audit #{$target->getKey()} hilang (dihapus).");
});

it('detects a removed seal in the middle and a truncated tail', function (): void {
    b2LogActivities(4);
    $seals = DB::table('audit_seals')->orderBy('id')->pluck('id');

    DB::table('audit_seals')->where('id', $seals[1])->delete();
    [$code, $output] = b2Verify();
    expect($code)->toBe(1)->and($output)->toContain('rantai putus');

    // Fresh chain: remove the newest row and its seal → the head no longer matches.
    DB::table('audit_seals')->delete();
    DB::table('audit_chain_heads')->update(['last_seal_id' => null, 'last_hash' => null]);
    app(AuditChain::class)->sealPending();
    $lastSeal = DB::table('audit_seals')->orderByDesc('id')->first();
    DB::table('audit_seals')->where('id', $lastSeal->id)->delete();
    DB::table('activity_log')->where('id', $lastSeal->activity_id)->delete();

    [$code, $output] = b2Verify();
    expect($code)->toBe(1)->and($output)->toContain('Kepala rantai tidak cocok');
});

it('does not seal rows of a rolled-back transaction and the chain stays valid', function (): void {
    b2LogActivities(1);
    $before = DB::table('audit_seals')->count();

    try {
        DB::transaction(function (): void {
            activity('account')->event('account.reset')->log('account.reset');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('audit_seals')->count())->toBe($before)
        ->and(b2Verify()[0])->toBe(0);
});

it('seals rows only after their transaction commits', function (): void {
    $before = DB::table('audit_seals')->count();

    DB::transaction(function () use ($before): void {
        b2LogActivities(2);

        expect(DB::table('audit_seals')->count())->toBe($before);
    });

    expect(DB::table('audit_seals')->count())->toBe(Activity::query()->count());
});

it('seals rows written without the after-commit hook through sth:audit:seal', function (): void {
    b2LogActivities(1);
    DB::table('activity_log')->insert([
        'log_name' => 'account', 'description' => 'account.reset', 'event' => 'account.reset',
        'properties' => '{"reason":"langsung"}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(AuditChain::class)->unsealedQuery()->count())->toBe(1);

    Artisan::call('sth:audit:seal');

    expect(Artisan::output())->toContain('1 baris audit disegel.')
        ->and(app(AuditChain::class)->unsealedQuery()->count())->toBe(0)
        ->and(b2Verify()[0])->toBe(0);

    Artisan::call('sth:audit:seal');
    expect(Artisan::output())->toContain('Semua baris audit sudah disegel.');
});

it('is not fooled by JSON re-formatting (key order, spaces) of the same data', function (): void {
    b2LogActivities(2);
    $row = DB::table('activity_log')->where('event', 'account.reset')->orderBy('id')->first();
    $data = json_decode($row->properties, true);
    $reordered = json_encode(array_reverse($data, true), JSON_PRETTY_PRINT);

    DB::table('activity_log')->where('id', $row->id)->update(['properties' => $reordered]);

    expect(b2Verify()[0])->toBe(0)
        ->and(AuditChain::canonicalJson('{"b": 1, "a": {"y": 2, "x": [3, 1]}}'))->toBe('{"a":{"x":[3,1],"y":2},"b":1}');
});

it('writes the verification result and chain head to the security log', function (): void {
    b2LogActivities(2);
    b2Verify();

    $records = collect(Log::channel('security')->getLogger()->getHandlers()[0]->getRecords());
    $ok = $records->first(fn ($r) => $r->message === 'security.audit_chain_verified');

    expect($ok)->not->toBeNull()
        ->and($ok->context['head_hash'])->toBe(app(AuditChain::class)->head()['hash']);

    DB::table('activity_log')->orderBy('id')->limit(1)->delete();
    b2Verify();

    $broken = collect(Log::channel('security')->getLogger()->getHandlers()[0]->getRecords())
        ->first(fn ($r) => $r->message === 'security.audit_chain_broken');

    expect($broken)->not->toBeNull()->and($broken->level->getName())->toBe('CRITICAL');
});

it('reports rows the sealer has not reached yet as a warning, not as tampering', function (): void {
    b2LogActivities(1);
    DB::table('activity_log')->insert([
        'log_name' => 'account', 'description' => 'x', 'event' => 'account.reset',
        'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
    ]);

    [$code, $output] = b2Verify();

    expect($code)->toBe(0)->and($output)->toContain('1 baris audit belum disegel.');
});
