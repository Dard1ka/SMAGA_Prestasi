<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Archive\SchoolYear;
use App\Domain\Support\Decimal;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal achievement data for the Kepala Sekolah screens and the period report (backlog #6, owner B5).
 * Internal = every verified achievement with the student's real name (also of disabled accounts, unlike
 * Publik\Data\PublicAchievements) — but never NIS, NISN, phone, email or birth date.
 *
 * rows() returns one plain array per verified achievement (portable SQL, grouped in PHP like Sekolah\Dashboard);
 * summary() aggregates them per bidang, tingkat, kelas, guru pembimbing and capaian.
 */
final class ExportPrestasiData
{
    /**
     * @return Collection<int, array{id: string, student_id: string, name: string, kelas: string, kelas_level: int, status: string,
     *     competition_id: string, lomba: string, organizer: string, bidang: Bidang, level: Level, result: ResultType, points: string,
     *     date: string, year: string, mentor: string}>
     */
    public function rows(?ExportPeriod $period = null, ?Bidang $bidang = null, ?Level $level = null): Collection
    {
        return $this->query($period, $bidang, $level)
            ->leftJoin('classrooms', 'classrooms.id', '=', 'students.classroom_id')
            ->leftJoin('reports', 'reports.id', '=', 'achievements.report_id')
            ->leftJoin('registrations', 'registrations.id', '=', 'reports.registration_id')
            ->leftJoin('users as mentors', 'mentors.id', '=', 'registrations.mentor_id')
            ->orderByDesc('achievements.verified_at')
            ->orderBy('achievements.id')
            ->get([
                'achievements.id', 'achievements.student_id', 'achievements.result', 'achievements.level', 'achievements.points',
                'achievements.verified_at', 'achievements.competition_id',
                'users.name as u_name', 'users.status as u_status', 'users.deleted_at as u_deleted', 'students.deleted_at as s_deleted',
                'classrooms.name as c_name', 'classrooms.level as c_level',
                'competitions.name as k_name', 'competitions.bidang as k_bidang', 'competitions.organizer_name as k_organizer',
                'competitions.final_on as k_final_on', 'mentors.name as m_name',
            ])
            ->map(fn (object $r): ?array => $this->mapRow($r))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        $points = $rows->reduce(fn (string $sum, array $r): string => bcadd($sum, $r['points'], 2), '0');

        return [
            'totals' => [
                'prestasi' => $rows->count(),
                'siswa' => $rows->pluck('student_id')->unique()->count(),
                'lomba' => $rows->pluck('competition_id')->unique()->count(),
                'points' => $points,
                'nasional' => $rows->filter(fn (array $r) => in_array($r['level'], [Level::Nasional, Level::Internasional], true))->count(),
            ],
            'bidang' => collect(Bidang::cases())->map(fn (Bidang $b): array => $this->group($b->label(), $rows->where('bidang', $b)) + ['bidang' => $b])->all(),
            'tingkat' => collect([Level::Internasional, Level::Nasional, Level::Provinsi, Level::Kota, Level::Sekolah])
                ->map(fn (Level $l): array => $this->group($l->label(), $rows->where('level', $l)))->all(),
            'capaian' => collect(ResultType::cases())
                ->map(fn (ResultType $t): array => $this->group($t->label(), $rows->where('result', $t)))
                ->filter(fn (array $g): bool => $g['prestasi'] > 0)->values()->all(),
            'kelas' => $rows->groupBy(fn (array $r): string => $r['kelas'] !== '' ? $r['kelas'] : '-')
                ->map(fn (Collection $g, string $kelas): array => $this->group($kelas, $g) + ['sort' => sprintf('%02d-%s', $g->first()['kelas_level'], self::naturalKey($kelas))])
                ->sortBy('sort')->values()->all(),
            'pembimbing' => $rows->groupBy(fn (array $r): string => $r['mentor'] !== '' ? $r['mentor'] : 'Tanpa pembimbing')
                ->map(fn (Collection $g, string $mentor): array => $this->group($mentor, $g))
                ->sortByDesc('prestasi')->values()->all(),
        ];
    }

    /** Number of rows() of the period, counted by the database (QA10: the PDF cap asks before it loads them). */
    public function count(?ExportPeriod $period = null, ?Bidang $bidang = null, ?Level $level = null): int
    {
        return $this->query($period, $bidang, $level)->count();
    }

    /**
     * School years that have verified achievements, plus the current one (newest first). One small query over the
     * distinct months of the period dates (QA10): before, this loaded every achievement row of all years on each
     * redraw of Kepala Sekolah › Laporan, next to the rows of the shown period.
     *
     * @return list<string>
     */
    public function schoolYears(): array
    {
        $current = SchoolYear::containing(CarbonImmutable::now(config('app.timezone')))->toString();

        // Same date as mapRow(): the final date of the lomba, else the verification date. "YYYY-MM" decides the year.
        $months = $this->query()
            ->selectRaw('substr(coalesce(competitions.final_on, achievements.verified_at), 1, 7) as ym')
            ->distinct()
            ->pluck('ym');

        return $months
            ->filter(fn (mixed $ym): bool => is_string($ym) && preg_match('/^\d{4}-\d{2}$/', $ym) === 1)
            ->map(fn (string $ym): string => SchoolYear::containing(CarbonImmutable::parse($ym.'-01'))->toString())
            ->push($current)->unique()->sortDesc()->values()->all();
    }

    /** Verified achievements of the period (and bidang / tingkat) with their student, account and lomba joined. */
    private function query(?ExportPeriod $period = null, ?Bidang $bidang = null, ?Level $level = null): Builder
    {
        $from = $period?->from?->toDateString();
        $to = $period?->to?->toDateString();

        return DB::table('achievements')
            ->join('students', 'students.id', '=', 'achievements.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->join('competitions', 'competitions.id', '=', 'achievements.competition_id')
            ->whereNotNull('achievements.verified_at')
            ->when($bidang !== null, fn ($q) => $q->where('competitions.bidang', $bidang->value))
            ->when($level !== null, fn ($q) => $q->where('achievements.level', $level->value))
            ->when($from !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($a) => $a->whereNotNull('competitions.final_on')->whereDate('competitions.final_on', '>=', $from)->whereDate('competitions.final_on', '<=', $to))
                ->orWhere(fn ($b) => $b->whereNull('competitions.final_on')->whereDate('achievements.verified_at', '>=', $from)->whereDate('achievements.verified_at', '<=', $to))));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{label: string, prestasi: int, siswa: int, points: string}
     */
    private function group(string $label, Collection $rows): array
    {
        return [
            'label' => $label,
            'prestasi' => $rows->count(),
            'siswa' => $rows->pluck('student_id')->unique()->count(),
            'points' => $rows->reduce(fn (string $sum, array $r): string => bcadd($sum, $r['points'], 2), '0'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function mapRow(object $r): ?array
    {
        $bidang = Bidang::tryFrom((string) $r->k_bidang);
        $level = Level::tryFrom((string) $r->level);
        $result = ResultType::tryFrom((string) $r->result);

        if ($bidang === null || $level === null || $result === null) {
            return null;
        }

        $date = $r->k_final_on !== null ? substr((string) $r->k_final_on, 0, 10) : substr((string) $r->verified_at, 0, 10);
        $status = UserStatus::tryFrom((string) $r->u_status);

        return [
            'id' => (string) $r->id,
            'student_id' => (string) $r->student_id,
            'name' => (string) $r->u_name,
            'kelas' => (string) ($r->c_name ?? ''),
            'kelas_level' => (int) ($r->c_level ?? 0),
            'status' => match (true) {
                $r->u_deleted !== null || $r->s_deleted !== null || $status === UserStatus::Disabled => 'nonaktif',
                $status === UserStatus::Archived => 'alumni',
                default => 'aktif',
            },
            'competition_id' => (string) $r->competition_id,
            'lomba' => (string) $r->k_name,
            'organizer' => (string) ($r->k_organizer ?? ''),
            'bidang' => $bidang,
            'level' => $level,
            'result' => $result,
            'points' => Decimal::roundHalfUp((string) $r->points, 2),
            'date' => $date,
            'year' => SchoolYear::containing(CarbonImmutable::parse($date))->toString(),
            'mentor' => (string) ($r->m_name ?? ''),
        ];
    }

    /** Digits padded ("XI-4" → "XI-0004") so a numbered name sorts naturally; lettered classes (D-165) keep their order. */
    private static function naturalKey(string $value): string
    {
        return (string) preg_replace_callback('/\d+/', fn (array $m): string => str_pad($m[0], 4, '0', STR_PAD_LEFT), $value);
    }
}
