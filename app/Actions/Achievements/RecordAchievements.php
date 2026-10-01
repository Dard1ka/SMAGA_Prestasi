<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Domain\Points\PointCalculator;
use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Enums\ResultType;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Models\WeightVersion;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates the prestasi rows of a verified report: one Achievement per confirmed member, each with the
 * FULL points (D-08), the weights snapshotted from $version, and one Humas publication row (`siap`) per
 * achievement. Every member gets a row; Humas screens filter by publish_consent (D-16).
 * Called by VerifyReport inside its transaction (the report and registration are locked there).
 */
final class RecordAchievements
{
    public function __construct(private readonly PointCalculator $calculator) {}

    /**
     * @return EloquentCollection<int, Achievement>
     */
    public function handle(
        User $actor,
        Report $report,
        Registration $registration,
        Competition $competition,
        ResultType $result,
        WeightVersion $version,
        DateTimeInterface $verifiedAt,
    ): EloquentCollection {
        Gate::forUser($actor)->authorize(Permission::ReportVerify->value);

        if (DB::transactionLevel() === 0) {
            throw new LogicException('RecordAchievements must run inside a database transaction.');
        }

        if ($competition->level === null || $competition->weight_category === null) {
            throw ValidationException::withMessages(['final_result' => 'Tingkat dan kategori lomba belum diisi di katalog.']);
        }

        $studentIds = $registration->members()
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->orderBy('created_at')
            ->pluck('student_id');

        if ($studentIds->isEmpty()) {
            throw ValidationException::withMessages(['final_result' => 'Tidak ada anggota terkonfirmasi di pendaftaran ini.']);
        }

        if (Achievement::query()->where('report_id', $report->getKey())->exists()) {
            throw ValidationException::withMessages(['final_result' => 'Prestasi untuk laporan ini sudah tercatat.']);
        }

        $breakdown = $this->calculator->breakdown(
            $version->toWeightTable(),
            $competition->level,
            $result,
            $competition->weight_category,
        );

        return EloquentCollection::make($studentIds->map(function (string $studentId) use ($actor, $report, $competition, $result, $version, $verifiedAt, $breakdown): Achievement {
            $achievement = new Achievement([
                'report_id' => $report->getKey(),
                'student_id' => $studentId,
                'competition_id' => $competition->getKey(),
            ]);

            $achievement->forceFill([
                'result' => $result,
                'level' => $competition->level,
                'weight_category' => $competition->weight_category,
                ...$breakdown->toArray(),
                'weight_version_id' => $version->getKey(),
                'verified_at' => $verifiedAt,
            ])->save();

            $publication = new Publication(['achievement_id' => $achievement->getKey()]);
            $publication->forceFill([
                'status' => PublicationStatus::Siap,
                'created_by' => $actor->getKey(),
            ])->save();

            return $achievement;
        })->values()->all());
    }
}
