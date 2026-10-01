<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use stdClass;
use Throwable;

/**
 * Hash chain over `activity_log` (SECURITY §3m-9 "audit log immutable"), so that deleting or editing audit rows
 * directly in the database is detected by `php artisan sth:audit:verify`.
 *
 * - Every committed activity row gets one seal in `audit_seals`: hash = sha256(prev_hash . "|" . payload(row)),
 *   prev_hash = the previous seal's hash (NULL for the first seal). The chain order is the seal order (`audit_seals.id`).
 * - Sealing runs right AFTER the surrounding transaction commits (DB::afterCommit), in its own short transaction
 *   that locks the single `audit_chain_heads` row. So no business transaction ever holds the chain lock (no
 *   deadlocks, no waits behind long transactions), rolled-back rows are never sealed, and `activity_log` itself
 *   stays INSERT/SELECT only for the app DB user (SECURITY §3h).
 * - Safety net: `sth:audit:seal` (scheduled) seals rows whose after-commit seal was lost (crash, error).
 * - The payload is canonical (JSON keys sorted, scalars as strings), read back from the database, so a different
 *   JSON formatting of the same data (MySQL normalises JSON) does not break the chain.
 * - The head (last seal id + hash) is written to the `security` log by the daily verify run, which ends up in the
 *   off-site backup: truncating the newest rows is then detectable too.
 */
final class AuditChain
{
    public const SEALS = 'audit_seals';

    public const HEADS = 'audit_chain_heads';

    public const HEAD_ID = 1;

    /** Hook into activity creation (called once from AppServiceProvider::boot()). */
    public static function register(): void
    {
        Activity::created(function (Activity $activity): void {
            $id = (int) $activity->getKey();

            $activity->getConnection()->afterCommit(function () use ($id): void {
                try {
                    app(self::class)->seal($id);
                } catch (Throwable $e) {
                    // The business change is committed already; the scheduled sealer retries this row.
                    SecurityLog::warning('security.audit_seal_failed', ['activity_id' => $id, 'error' => $e::class]);
                }
            });
        });
    }

    /**
     * Seal one committed activity row. Idempotent: false when it is already sealed or does not exist.
     */
    public function seal(int $activityId): bool
    {
        return DB::transaction(function () use ($activityId): bool {
            $head = $this->lockHead();

            if (DB::table(self::SEALS)->where('activity_id', $activityId)->exists()) {
                return false;
            }

            $row = DB::table('activity_log')->where('id', $activityId)->first();

            if ($row === null) {
                return false;
            }

            $this->append($head, $row);

            return true;
        });
    }

    /**
     * Seal every committed row that has no seal yet (oldest first). Returns the number of rows sealed.
     */
    public function sealPending(int $limit = 5000): int
    {
        $sealed = 0;

        while ($sealed < $limit) {
            $ids = $this->unsealedQuery()->orderBy('activity_log.id')->limit(min(500, $limit - $sealed))->pluck('activity_log.id');

            if ($ids->isEmpty()) {
                break;
            }

            $count = DB::transaction(function () use ($ids): int {
                $head = $this->lockHead();
                $done = 0;

                foreach (DB::table('activity_log')->whereIn('id', $ids)->orderBy('id')->get() as $row) {
                    if (DB::table(self::SEALS)->where('activity_id', $row->id)->exists()) {
                        continue;
                    }

                    $head = $this->append($head, $row);
                    $done++;
                }

                return $done;
            });

            if ($count === 0) {
                break;
            }

            $sealed += $count;
        }

        return $sealed;
    }

    /** Rows of activity_log without a seal. */
    public function unsealedQuery(): Builder
    {
        return DB::table('activity_log')
            ->leftJoin(self::SEALS, self::SEALS.'.activity_id', '=', 'activity_log.id')
            ->whereNull(self::SEALS.'.id');
    }

    /**
     * Walk every seal in chain order and recompute it from the current activity row.
     */
    public function verify(int $maxProblems = 20, int $graceMinutes = 15): AuditChainReport
    {
        $problems = [];
        $previous = null;
        $lastSealId = null;
        $checked = 0;

        DB::table(self::SEALS)->orderBy('id')->chunkById(1000, function ($seals) use (&$problems, &$previous, &$lastSealId, &$checked, $maxProblems): bool {
            $rows = DB::table('activity_log')->whereIn('id', $seals->pluck('activity_id')->all())->get()->keyBy('id');

            foreach ($seals as $seal) {
                $checked++;

                if ($seal->prev_hash !== $previous) {
                    $problems[] = "Segel #{$seal->id}: rantai putus (segel sebelumnya hilang atau diubah).";
                }

                $row = $rows->get($seal->activity_id);

                if ($row === null) {
                    $problems[] = "Baris audit #{$seal->activity_id} hilang (dihapus).";
                } elseif (! hash_equals((string) $seal->hash, self::hash($seal->prev_hash, self::payload($row)))) {
                    $problems[] = "Baris audit #{$seal->activity_id} diubah.";
                }

                $previous = $seal->hash;
                $lastSealId = (int) $seal->id;

                if (count($problems) >= $maxProblems) {
                    return false;
                }
            }

            return true;
        });

        $head = $this->head();

        if (count($problems) < $maxProblems && ($head['hash'] !== $previous || $head['seal_id'] !== $lastSealId)) {
            $problems[] = 'Kepala rantai tidak cocok dengan segel terakhir (segel terbaru dihapus atau diubah).';
        }

        $unsealed = $this->unsealedQuery()
            ->where('activity_log.created_at', '<', now()->subMinutes($graceMinutes))
            ->count();

        return new AuditChainReport(
            checked: $checked,
            problems: $problems,
            unsealed: $unsealed,
            headSealId: $head['seal_id'],
            headHash: $head['hash'],
        );
    }

    public static function hash(?string $prevHash, string $payload): string
    {
        return hash('sha256', ($prevHash ?? '').'|'.$payload);
    }

    /**
     * Canonical text of one activity_log row (as read from the database).
     */
    public static function payload(stdClass $row): string
    {
        $fields = [
            'id' => $row->id,
            'log_name' => $row->log_name,
            'description' => $row->description,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'event' => $row->event,
            'causer_type' => $row->causer_type,
            'causer_id' => $row->causer_id,
            'attribute_changes' => self::canonicalJson($row->attribute_changes),
            'properties' => self::canonicalJson($row->properties),
            'created_at' => $row->created_at,
        ];

        $fields = array_map(fn (mixed $v): ?string => $v === null ? null : (string) $v, $fields);

        return (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** JSON with object keys sorted recursively; invalid JSON is kept verbatim (still hashed). */
    public static function canonicalJson(mixed $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode((string) $json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'raw:'.$json;
        }

        return (string) json_encode(self::sortKeys($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{seal_id: int|null, hash: string|null} */
    public function head(): array
    {
        $head = DB::table(self::HEADS)->where('id', self::HEAD_ID)->first();

        return [
            'seal_id' => $head?->last_seal_id !== null ? (int) $head->last_seal_id : null,
            'hash' => $head?->last_hash,
        ];
    }

    private function lockHead(): stdClass
    {
        $head = DB::table(self::HEADS)->where('id', self::HEAD_ID)->lockForUpdate()->first();

        if ($head === null) {
            DB::table(self::HEADS)->insertOrIgnore(['id' => self::HEAD_ID, 'last_seal_id' => null, 'last_hash' => null, 'updated_at' => now()]);
            $head = DB::table(self::HEADS)->where('id', self::HEAD_ID)->lockForUpdate()->first();
        }

        return $head;
    }

    /** Insert the seal for $row after $head and move the head; returns the new head. */
    private function append(stdClass $head, stdClass $row): stdClass
    {
        $hash = self::hash($head->last_hash, self::payload($row));

        $sealId = DB::table(self::SEALS)->insertGetId([
            'activity_id' => $row->id,
            'prev_hash' => $head->last_hash,
            'hash' => $hash,
            'created_at' => now(),
        ]);

        DB::table(self::HEADS)->where('id', self::HEAD_ID)->update([
            'last_seal_id' => $sealId,
            'last_hash' => $hash,
            'updated_at' => now(),
        ]);

        $head->last_seal_id = $sealId;
        $head->last_hash = $hash;

        return $head;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
