<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Points\PointCalculator;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates every achievement with a new weight version (D-08: "perubahan memicu rekalkulasi dengan
 * laporan diff"). Snapshots (level, result, weight_category) stay; w_* weights, points and
 * weight_version_id are replaced. Runs in chunks, each in a transaction with lockForUpdate().
 *
 * Idempotent: rows already on this version are skipped; when a newer version became active in the
 * meantime the job stops (that version's own job recalculates). One audit entry `weight.recomputed`
 * with counts, point totals before/after and the per-row diff (capped at 1 000 rows).
 */
final class RecomputePoints implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DIFF_LIMIT = 1000;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly string $weightVersionId, public readonly ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return $this->weightVersionId;
    }

    public function handle(PointCalculator $calculator): void
    {
        $version = WeightVersion::query()->find($this->weightVersionId);
        $active = WeightVersion::active();

        if ($version === null || $active === null || $active->getKey() !== $version->getKey()) {
            activity('weight')
                ->event('weight.recompute_skipped')
                ->withProperties(['version' => $this->weightVersionId, 'active' => $active?->getKey()])
                ->log('weight.recompute_skipped');

            return;
        }

        $table = $version->toWeightTable();
        $stats = ['checked' => 0, 'changed' => 0, 'before' => '0.00', 'after' => '0.00'];
        $diff = [];

        Achievement::query()
            ->where('weight_version_id', '!=', $version->getKey())
            ->select('id')
            ->chunkById(200, function (Collection $chunk) use ($calculator, $table, $version, &$stats, &$diff): void {
                DB::transaction(function () use ($chunk, $calculator, $table, $version, &$stats, &$diff): void {
                    $rows = Achievement::query()
                        ->whereKey($chunk->pluck('id')->all())
                        ->where('weight_version_id', '!=', $version->getKey())
                        ->lockForUpdate()
                        ->get();

                    foreach ($rows as $achievement) {
                        $stats['checked']++;
                        $new = $calculator->breakdown($table, $achievement->level, $achievement->result, $achievement->weight_category)->toArray();
                        $old = (string) $achievement->points;

                        $stats['before'] = bcadd($stats['before'], $old, 2);
                        $stats['after'] = bcadd($stats['after'], $new['points'], 2);

                        if (bccomp($old, $new['points'], 2) !== 0) {
                            $stats['changed']++;

                            if (count($diff) < self::DIFF_LIMIT) {
                                $diff[] = ['id' => $achievement->getKey(), 'from' => $old, 'to' => $new['points']];
                            }
                        }

                        // saveQuietly: one summary audit entry below instead of one activity row per achievement.
                        $achievement->forceFill([...$new, 'weight_version_id' => $version->getKey()])->saveQuietly();
                    }
                });
            });

        $log = activity('weight')
            ->performedOn($version)
            ->event('weight.recomputed')
            ->withProperties([...$stats, 'diff' => $diff, 'diff_truncated' => $stats['changed'] > count($diff)]);

        if ($this->actorId !== null && ($actor = User::query()->find($this->actorId)) !== null) {
            $log->causedBy($actor);
        }

        $log->log('weight.recomputed');

        PublicAchievements::flush();
    }
}
