<?php

declare(strict_types=1);

namespace App\Livewire\Publik\Data;

use App\Domain\Archive\SchoolYear;
use App\Domain\Leaderboard\RankedStudent;
use App\Domain\Leaderboard\Ranker;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Domain\Points\WeightTable;
use App\Domain\Support\Decimal;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ResultType;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\File;
use App\Models\ReportFile;
use App\Models\Student;
use App\Models\WeightVersion;
use App\Support\Leaderboard\PersonalRank;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

/**
 * The only data source of the public pages (D-16, D-31, SECURITY §3m-7). Every method returns plain arrays
 * built from an explicit column allowlist: name + class + verified achievements (lomba, bidang, tingkat,
 * capaian, tahun ajaran, penyelenggara, poin). Never NIS, NISN, phone, email, birth date, user id or
 * student ULID.
 *
 * Consent: a student's name (and the random `public_slug` link) only appears when
 * `students.publish_consent` is true; everyone else is "Siswa kelas XI" (or "Alumni") but still counts in
 * every aggregate. Disabled or deleted accounts count in the aggregates only (no name, no ranking row).
 *
 * Results are cached for 10 minutes under keys prefixed with the version in `publik:version`;
 * call PublicAchievements::flush() after a verification, a consent change or a weight recalculation.
 */
final class PublicAchievements
{
    public const VERSION_KEY = 'publik:version';

    public const TTL_SECONDS = 600;

    public const SLUG_PATTERN = '/^[a-f0-9]{20}$/';

    /** Roman class level for the anonymous label ("Siswa kelas XI"). */
    private const ROMAN = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Result weight rows of "Cara poin dihitung": pairs share one row while their weights are equal. */
    private const RESULT_ROWS = [
        [ResultType::Juara1, ResultType::MedaliEmas],
        [ResultType::Juara2, ResultType::MedaliPerak],
        [ResultType::Juara3, ResultType::MedaliPerunggu],
        [ResultType::Finalis],
        [ResultType::Semifinalis],
        [ResultType::PenghargaanKhusus],
        [ResultType::Peserta],
    ];

    public function __construct(
        private readonly PointFormatter $formatter = new PointFormatter,
        private readonly Ranker $ranker = new Ranker,
        private readonly PointCalculator $calculator = new PointCalculator,
    ) {}

    /** Invalidate every cached public result (new version prefix; old keys expire with their TTL). */
    public static function flush(): void
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        Cache::forever(self::VERSION_KEY, $version + 1);
    }

    // Aggregates ---------------------------------------------------------------------------------

    /**
     * Beranda: totals, split per level, students, lomba of the current school year, per-bidang counts and a
     * school year × bidang matrix for the last three school years (the current one included).
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $current = $this->currentYear();

        return $this->remember('overview', [$current->toString()], function () use ($current): array {
            $rows = $this->rows();
            $years = $this->recentYears($current);

            $perBidang = array_fill_keys(Bidang::values(), 0);
            $matrix = array_fill_keys($years, array_fill_keys(Bidang::values(), 0));
            $students = [];
            $nasional = 0;
            $internasional = 0;
            $since = null;

            foreach ($rows as $row) {
                $perBidang[$row['bidang']]++;
                $students[$row['sid']] = true;
                $nasional += $row['level'] === Level::Nasional->value ? 1 : 0;
                $internasional += $row['level'] === Level::Internasional->value ? 1 : 0;
                $since = $since === null || strcmp($row['tahun'], $since) < 0 ? $row['tahun'] : $since;

                if (isset($matrix[$row['tahun']])) {
                    $matrix[$row['tahun']][$row['bidang']]++;
                }
            }

            $max = max(1, ...array_map(fn (array $counts): int => max($counts), array_values($matrix)));
            $bidang = $this->bidangList($perBidang, count($rows));
            $top = $bidang;
            usort($top, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            $chart = [];
            foreach ($matrix as $tahun => $counts) {
                $chart[] = [
                    'tahun' => $tahun,
                    'total' => array_sum($counts),
                    'current' => $tahun === $current->toString(),
                    'bidang' => array_map(fn (Bidang $b): array => [
                        'key' => $b->cssKey(),
                        'label' => $b->label(),
                        'count' => $counts[$b->value],
                        'pct' => $this->barPercent($counts[$b->value], $max),
                    ], Bidang::cases()),
                ];
            }

            return [
                'total' => count($rows),
                'nasional' => $nasional,
                'internasional' => $internasional,
                'students' => count($students),
                'since' => $since,
                'tahun' => $current->toString(),
                'lomba_tahun' => $this->competitionsInYear($current),
                'bidang' => $bidang,
                'top_bidang' => ($top[0]['count'] ?? 0) > 0 ? $top[0] : null,
                'years' => $chart,
            ];
        });
    }

    /**
     * Latest verified results, one card per report (a team is one card), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 6): array
    {
        $limit = max(1, min($limit, 24));

        return $this->remember('recent', [$limit], function () use ($limit): array {
            $cards = [];

            foreach ($this->rows() as $row) {
                $key = $row['report'];

                if (! isset($cards[$key])) {
                    if (count($cards) >= $limit) {
                        continue;
                    }

                    $cards[$key] = $this->competitionFields($row) + [
                        'level_label' => Level::from($row['level'])->shortLabel(),
                        'result_label' => ResultType::from($row['result'])->label(),
                        'podium' => ResultType::from($row['result'])->isPodium(),
                        'tahun' => $row['tahun'],
                        'people' => [],
                    ];
                }

                $cards[$key]['people'][] = $this->person($row);
            }

            return array_values(array_map(function (array $card): array {
                usort($card['people'], fn (array $a, array $b): int => [$b['named'], $a['name']] <=> [$a['named'], $b['name']]);

                return $card;
            }, $cards));
        });
    }

    /**
     * Prestasi per bidang, level 1: counts per bidang (share of the filtered total) and, per bidang, the
     * last three school years (filtered by tingkat only, so the selected tahun can be highlighted).
     *
     * @return array{total: int, bidang: list<array<string, mixed>>, years: array<string, list<array<string, mixed>>>}
     */
    public function bidangSummary(?string $tahun = null, ?string $tingkat = null): array
    {
        $current = $this->currentYear();

        return $this->remember('bidang-summary', [$tahun, $tingkat, $current->toString()], function () use ($tahun, $tingkat, $current): array {
            $years = $this->recentYears($current);
            $perBidang = array_fill_keys(Bidang::values(), 0);
            $matrix = array_fill_keys(Bidang::values(), array_fill_keys($years, 0));
            $total = 0;

            foreach ($this->rows() as $row) {
                if ($tingkat !== null && $row['level'] !== $tingkat) {
                    continue;
                }

                if (isset($matrix[$row['bidang']][$row['tahun']])) {
                    $matrix[$row['bidang']][$row['tahun']]++;
                }

                if ($tahun !== null && $row['tahun'] !== $tahun) {
                    continue;
                }

                $perBidang[$row['bidang']]++;
                $total++;
            }

            $chart = [];
            foreach ($matrix as $bidang => $counts) {
                $max = max(1, ...array_values($counts));
                $chart[$bidang] = [];

                foreach ($counts as $year => $count) {
                    $chart[$bidang][] = [
                        'tahun' => $year,
                        'count' => $count,
                        'pct' => $this->barPercent($count, $max),
                        'current' => $year === $current->toString(),
                    ];
                }
            }

            return [
                'total' => $total,
                'bidang' => $this->bidangList($perBidang, $total),
                'years' => $chart,
            ];
        });
    }

    /** The bidang with the most verified achievements (declaration order on ties), Sains when empty. */
    public function defaultBidang(): Bidang
    {
        $counts = array_column($this->overview()['bidang'], 'count', 'value');
        $best = Bidang::Sains;

        foreach (Bidang::cases() as $bidang) {
            if (($counts[$bidang->value] ?? 0) > ($counts[$best->value] ?? 0)) {
                $best = $bidang;
            }
        }

        return $best;
    }

    /**
     * Prestasi per bidang, level 2: students with at least one matching achievement, by points desc.
     *
     * @return list<array<string, mixed>>
     */
    public function studentsInBidang(Bidang $bidang, ?string $tahun = null, ?string $tingkat = null): array
    {
        return $this->remember('students-in-bidang', [$bidang->value, $tahun, $tingkat], function () use ($bidang, $tahun, $tingkat): array {
            $students = [];

            foreach ($this->rows() as $row) {
                if ($row['inactive'] || $row['bidang'] !== $bidang->value
                    || ($tahun !== null && $row['tahun'] !== $tahun)
                    || ($tingkat !== null && $row['level'] !== $tingkat)) {
                    continue;
                }

                $sid = $row['sid'];
                $students[$sid] ??= ['person' => $this->person($row), 'count' => 0, 'points' => '0.00', 'best' => null];
                $students[$sid]['count']++;
                $students[$sid]['points'] = bcadd($students[$sid]['points'], $row['points'], 2);

                if ($students[$sid]['best'] === null || $this->isBetter($row, $students[$sid]['best'])) {
                    $students[$sid]['best'] = $row;
                }
            }

            usort($students, fn (array $a, array $b): int => bccomp($b['points'], $a['points'], 2)
                ?: $b['count'] <=> $a['count']
                ?: strcmp($a['person']['name'], $b['person']['name']));

            return array_map(fn (array $s): array => $s['person'] + [
                'count' => $s['count'],
                'points' => $s['points'],
                'points_label' => $this->formatter->format($s['points']),
                'best' => ResultType::from($s['best']['result'])->label(),
                'best_tingkat' => Level::from($s['best']['level'])->shortLabel(),
            ], $students);
        });
    }

    /**
     * Prestasi per bidang, level 3 and /prestasi/siswa/{slug}: consenting students only (null → 404).
     *
     * @return array<string, mixed>|null
     */
    public function studentDetail(string $slug): ?array
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $certificates = PublicSettings::certificatesAllowed();

        return $this->remember('student', [$slug, $certificates], function () use ($slug, $certificates): ?array {
            $rows = array_values(array_filter($this->rows(), fn (array $row): bool => $row['named'] && $row['slug'] === $slug));

            if ($rows === []) {
                return null;
            }

            $sid = $rows[0]['sid'];
            $total = '0.00';

            foreach ($rows as $row) {
                $total = bcadd($total, $row['points'], 2);
            }

            usort($rows, fn (array $a, array $b): int => bccomp($b['points'], $a['points'], 2)
                ?: strcmp($b['verified_at'], $a['verified_at']));

            // Personal rank among active students (D-37, same as siswa Beranda/Portofolio); alumni: rank incl. alumni.
            $rankActive = $rows[0]['alumni'] ? null : app(PersonalRank::class)->rank($sid);

            return $this->person($rows[0]) + [
                'points' => $total,
                'points_label' => $this->formatter->format($total),
                'rank_active' => $rankActive,
                'rank_all' => $this->rankOf($sid, $this->rankedStudents(null, null, true)),
                'count' => count($rows),
                'achievements' => array_map(fn (array $row): array => $this->achievementItem($row, $certificates), $rows),
            ];
        });
    }

    /**
     * Pemenang per lomba (D-31): the competition + its verified results grouped by capaian, best first.
     * Null when the lomba has no verified achievement (→ 404).
     *
     * @return array<string, mixed>|null
     */
    public function winners(string $competitionUlid): ?array
    {
        $competitionUlid = strtolower($competitionUlid);

        if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $competitionUlid) !== 1) {
            return null;
        }

        return $this->remember('winners', [$competitionUlid], function () use ($competitionUlid): ?array {
            $rows = array_values(array_filter($this->rows(), fn (array $row): bool => $row['competition'] === $competitionUlid));

            if ($rows === []) {
                return null;
            }

            $competition = Competition::withTrashed()
                ->select(['id', 'name', 'bidang', 'level', 'organizer_name', 'weight_category', 'final_on'])
                ->find($competitionUlid);

            $level = $competition?->level ?? Level::from($rows[0]['level']);
            $category = $competition?->weight_category ?? WeightCategory::from($rows[0]['kategori']);
            $finalOn = $competition?->final_on?->format('Y-m-d') ?? $rows[0]['final_on'];

            $groups = [];
            foreach ($rows as $row) {
                $result = ResultType::from($row['result']);
                $groups[$result->value] ??= [
                    'result' => $result->value,
                    'label' => $result->label(),
                    'podium' => $result->isPodium(),
                    'tier' => $result->tier(),
                    'order' => array_search($result, ResultType::cases(), true),
                    'points_label' => $this->formatter->format($row['points']),
                    'people' => [],
                ];
                $groups[$result->value]['people'][] = $this->person($row);
            }

            usort($groups, fn (array $a, array $b): int => [$a['tier'], $a['order']] <=> [$b['tier'], $b['order']]);

            return $this->competitionFields($rows[0]) + [
                'level_label' => $level->label(),
                'kategori_label' => $category->label(),
                'final_on' => $finalOn,
                'final_label' => $finalOn !== null ? $this->dateLabel($finalOn) : null,
                'tahun' => $rows[0]['tahun'],
                'count' => count($rows),
                'groups' => array_map(function (array $group): array {
                    usort($group['people'], fn (array $a, array $b): int => [$b['named'], $a['name']] <=> [$a['named'], $b['name']]);
                    unset($group['tier'], $group['order']);

                    return $group;
                }, $groups),
            ];
        });
    }

    /**
     * Cumulative ranking (D-09). mode 'siswa': students via the Ranker, alumni only when $alumni (chip
     * "Alumni"). mode 'kelas': active students grouped by their current class. Filters: bidang (points of
     * that bidang only) and angkatan (= year of the grade-12 start - 2).
     *
     * @return list<array<string, mixed>>
     */
    public function ranking(string $mode = 'siswa', ?Bidang $bidang = null, ?int $angkatan = null, bool $alumni = false): array
    {
        $mode = $mode === 'kelas' ? 'kelas' : 'siswa';
        $alumni = $mode === 'siswa' && $alumni;

        return $this->remember('ranking', [$mode, $bidang?->value, $angkatan, $alumni, $this->currentYear()->toString()], function () use ($mode, $bidang, $angkatan, $alumni): array {
            return $mode === 'kelas'
                ? $this->classRanking($bidang, $angkatan)
                : $this->studentRanking($bidang, $angkatan, $alumni);
        });
    }

    /** @return array<string, string> angkatan => "Angkatan 2025 (kelas XI)", newest first */
    public function angkatanOptions(): array
    {
        $current = $this->currentYear();

        return $this->remember('angkatan-options', [$current->toString()], function () use ($current): array {
            $values = [];

            foreach ($this->rows() as $row) {
                if (! $row['inactive'] && $row['angkatan'] !== null) {
                    $values[$row['angkatan']] = true;
                }
            }

            krsort($values);
            $options = [];

            foreach (array_keys($values) as $angkatan) {
                $level = self::ROMAN[10 + $current->startYear - $angkatan] ?? null;
                $options[(string) $angkatan] = 'Angkatan '.$angkatan.($level !== null ? ' (kelas '.$level.')' : '');
            }

            return $options;
        });
    }

    /** @return array<string, string> '2025/2026' => '2025/2026', newest first (the current year always included) */
    public function schoolYearOptions(): array
    {
        $current = $this->currentYear();

        return $this->remember('school-year-options', [$current->toString()], function () use ($current): array {
            $years = [$current->toString() => true];

            foreach ($this->rows() as $row) {
                $years[$row['tahun']] = true;
            }

            krsort($years);
            $years = array_keys($years);

            return array_combine($years, $years);
        });
    }

    /** @return array<string, string> tingkat value => label, highest first */
    public function levelOptions(): array
    {
        $levels = Level::cases();
        usort($levels, fn (Level $a, Level $b): int => $b->rank() <=> $a->rank());

        $options = [];
        foreach ($levels as $level) {
            $options[$level->value] = $level->label();
        }

        return $options;
    }

    /**
     * "Cara poin dihitung": weights of the active version (defaults before WeightSeeder ran) and the
     * example Juara I · Nasional · Kategori A.
     *
     * @return array<string, mixed>
     */
    public function weightInfo(): array
    {
        return $this->remember('weights', [], function (): array {
            $table = rescue(fn () => WeightVersion::active()?->toWeightTable(), null, false) ?? WeightTable::defaults();
            $weight = fn (Closure $lookup): ?string => rescue($lookup, null, false);

            $levels = Level::cases();
            usort($levels, fn (Level $a, Level $b): int => $b->rank() <=> $a->rank());

            $results = [];
            foreach (self::RESULT_ROWS as $pair) {
                $values = array_map(fn (ResultType $r): ?string => $weight(fn () => $table->result($r)), $pair);

                if (count(array_unique($values)) === 1) {
                    if ($values[0] !== null) {
                        $results[] = ['label' => implode(' / ', array_map(fn (ResultType $r): string => $r->label(), $pair)), 'value' => $this->formatter->formatWeight($values[0])];
                    }

                    continue;
                }

                foreach ($pair as $i => $result) {
                    if ($values[$i] !== null) {
                        $results[] = ['label' => $result->label(), 'value' => $this->formatter->formatWeight($values[$i])];
                    }
                }
            }

            $rows = fn (array $cases, Closure $lookup, bool $isWeight): array => array_values(array_filter(array_map(function ($case) use ($lookup, $weight, $isWeight): ?array {
                $value = $weight(fn () => $lookup($case));

                return $value === null ? null : [
                    'label' => $case->label(),
                    'value' => $isWeight ? $this->formatter->formatWeight($value) : $this->formatter->format($value, 0),
                ];
            }, $cases)));

            $example = [
                'tingkat' => $weight(fn () => $table->level(Level::Nasional)) ?? Level::Nasional->defaultWeight(),
                'capaian' => $weight(fn () => $table->result(ResultType::Juara1)) ?? ResultType::Juara1->defaultWeight(),
                'penyelenggara' => $weight(fn () => $table->organizer(WeightCategory::A)) ?? WeightCategory::A->defaultWeight(),
            ];
            $example['total'] = $this->calculator->compute($example['tingkat'], $example['capaian'], $example['penyelenggara']);

            return [
                'example' => $example,
                'results' => $results,
                'levels' => $rows($levels, fn (Level $l): string => $table->level($l), false),
                'organizers' => $rows(WeightCategory::cases(), fn (WeightCategory $c): string => $table->organizer($c), true),
            ];
        });
    }

    // Internals ----------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function studentRanking(?Bidang $bidang, ?int $angkatan, bool $alumni): array
    {
        $meta = [];
        $bidangPoints = [];

        foreach ($this->filteredRows(null, $angkatan, $alumni) as $row) {
            $meta[$row['sid']] ??= $this->person($row);
            $bidangPoints[$row['sid']][$row['bidang']] = bcadd($bidangPoints[$row['sid']][$row['bidang']] ?? '0.00', $row['points'], 2);
        }

        return array_map(function ($ranked) use ($meta, $bidangPoints): array {
            $sid = (string) $ranked->studentId;
            $main = null;

            foreach ($bidangPoints[$sid] ?? [] as $value => $points) {
                $main = $main === null || bccomp($points, $bidangPoints[$sid][$main], 2) > 0 ? $value : $main;
            }

            return $meta[$sid] + [
                'rank' => $ranked->rank,
                'position' => $ranked->position,
                'count' => $ranked->achievements,
                'points' => $ranked->points,
                'points_label' => $this->formatter->format($ranked->points),
                'main_bidang' => $main !== null ? Bidang::from($main)->label() : null,
            ];
        }, $this->rankedStudents($bidang, $angkatan, $alumni));
    }

    /** @return list<array<string, mixed>> */
    private function classRanking(?Bidang $bidang, ?int $angkatan): array
    {
        $current = $this->currentYear();
        $classes = [];

        foreach ($this->rows() as $row) {
            $classAngkatan = isset(self::ROMAN[$row['level_num']]) ? $current->startYear - ($row['level_num'] - 10) : null;

            if ($row['inactive'] || $row['alumni']
                || ($bidang !== null && $row['bidang'] !== $bidang->value)
                || ($angkatan !== null && $classAngkatan !== $angkatan)) {
                continue;
            }

            $name = $row['kelas_room'];
            $classes[$name] ??= ['kelas' => $name, 'level' => $row['level_num'], 'angkatan' => $classAngkatan, 'students' => [], 'count' => 0, 'points' => '0.00'];
            $classes[$name]['students'][$row['sid']] = true;
            $classes[$name]['count']++;
            $classes[$name]['points'] = bcadd($classes[$name]['points'], $row['points'], 2);
        }

        usort($classes, fn (array $a, array $b): int => bccomp($b['points'], $a['points'], 2)
            ?: $a['level'] <=> $b['level']
            ?: strnatcmp($a['kelas'], $b['kelas']));

        $ranked = [];
        $previous = null;

        foreach (array_values($classes) as $index => $class) {
            $rank = $previous !== null && bccomp($previous['points'], $class['points'], 2) === 0 ? $previous['rank'] : $index + 1;
            $ranked[] = [
                'rank' => $rank,
                'position' => $index + 1,
                'kelas' => $class['kelas'],
                'angkatan' => $class['angkatan'],
                'students' => count($class['students']),
                'count' => $class['count'],
                'points' => $class['points'],
                'points_label' => $this->formatter->format($class['points']),
            ];
            $previous = ['points' => $class['points'], 'rank' => $rank];
        }

        return $ranked;
    }

    /** @return list<RankedStudent> */
    private function rankedStudents(?Bidang $bidang, ?int $angkatan, bool $alumni): array
    {
        $input = [];

        foreach ($this->filteredRows($bidang, $angkatan, $alumni) as $row) {
            $input[] = [
                'student_id' => $row['sid'],
                'name' => $row['name'],
                'points' => $row['points'],
                'results' => [$row['result']],
                'alumni' => $row['alumni'],
            ];
        }

        return $this->ranker->rank($input);
    }

    /** @return list<array<string, mixed>> rows of rankable students (no disabled/deleted accounts) */
    private function filteredRows(?Bidang $bidang, ?int $angkatan, bool $alumni): array
    {
        return array_values(array_filter($this->rows(), fn (array $row): bool => ! $row['inactive']
            && ($alumni || ! $row['alumni'])
            && ($bidang === null || $row['bidang'] === $bidang->value)
            && ($angkatan === null || $row['angkatan'] === $angkatan)));
    }

    /** @param list<RankedStudent> $ranked */
    private function rankOf(string $sid, array $ranked): ?int
    {
        foreach ($ranked as $student) {
            if ((string) $student->studentId === $sid) {
                return $student->rank;
            }
        }

        return null;
    }

    /**
     * One internal row per verified achievement (students.*, users.*, competitions.* via joins). Internal
     * keys `sid`, `report` and `kelas_room` never leave this class; views get person()/achievementItem().
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->remember('rows', [], function (): array {
            $this->assignMissingSlugs();
            $certificates = $this->singleCertificates();
            $current = $this->currentYear();
            $rows = [];

            $achievements = Achievement::query()
                ->join('students', 'students.id', '=', 'achievements.student_id')
                ->join('users', 'users.id', '=', 'students.user_id')
                ->join('classrooms', 'classrooms.id', '=', 'students.classroom_id')
                ->join('competitions', 'competitions.id', '=', 'achievements.competition_id')
                ->select([
                    'achievements.id', 'achievements.report_id', 'achievements.student_id', 'achievements.competition_id',
                    'achievements.result', 'achievements.level', 'achievements.weight_category',
                    'achievements.w_level', 'achievements.w_result', 'achievements.w_organizer', 'achievements.points',
                    'achievements.verified_at',
                    'students.publish_consent as s_consent', 'students.public_slug as s_slug',
                    'students.grade12_start_date as s_grade12', 'students.deleted_at as s_deleted',
                    'users.name as u_name', 'users.status as u_status', 'users.deleted_at as u_deleted',
                    'classrooms.name as c_name', 'classrooms.level as c_level',
                    'competitions.name as k_name', 'competitions.bidang as k_bidang',
                    'competitions.organizer_name as k_organizer', 'competitions.final_on as k_final_on',
                ])
                ->orderByDesc('achievements.verified_at')
                ->orderBy('achievements.id')
                ->get();

            foreach ($achievements as $a) {
                $row = $this->mapRow($a, $current, $certificates);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        });
    }

    /**
     * @param  array<string, string>  $certificates  report id => certificate file id
     * @return array<string, mixed>|null
     */
    private function mapRow(Achievement $a, SchoolYear $current, array $certificates): ?array
    {
        $bidang = Bidang::tryFrom((string) $a->getAttribute('k_bidang'));

        if ($bidang === null) {
            return null;
        }

        $status = UserStatus::tryFrom((string) $a->getAttribute('u_status'));
        $inactive = $status === UserStatus::Disabled
            || $a->getAttribute('s_deleted') !== null
            || $a->getAttribute('u_deleted') !== null;
        $alumni = ! $inactive && $status === UserStatus::Archived;
        $named = (bool) $a->getAttribute('s_consent') && ! $inactive;
        $levelNum = (int) $a->getAttribute('c_level');
        $roman = self::ROMAN[$levelNum] ?? null;

        $grade12 = $a->getAttribute('s_grade12') !== null ? (int) substr((string) $a->getAttribute('s_grade12'), 0, 4) : null;
        $angkatan = $grade12 !== null ? $grade12 - 2 : ($roman !== null ? $current->startYear - ($levelNum - 10) : null);

        $finalOn = $a->getAttribute('k_final_on') !== null ? substr((string) $a->getAttribute('k_final_on'), 0, 10) : null;
        $tahun = $this->schoolYearOf($finalOn !== null ? CarbonImmutable::parse($finalOn) : $a->verified_at);

        if ($tahun === null) {
            return null;
        }

        $label = $named
            ? (string) $a->getAttribute('u_name')
            : ($alumni ? 'Alumni' : ($roman !== null ? 'Siswa kelas '.$roman : 'Siswa'));

        return [
            'sid' => (string) $a->student_id,
            'report' => (string) $a->report_id,
            'named' => $named,
            'name' => $label,
            'slug' => $named && is_string($a->getAttribute('s_slug')) && $a->getAttribute('s_slug') !== '' ? $a->getAttribute('s_slug') : null,
            'kelas' => $named ? (string) $a->getAttribute('c_name') : null,
            'kelas_room' => (string) $a->getAttribute('c_name'),
            'level_num' => $levelNum,
            'alumni' => $alumni,
            'inactive' => $inactive,
            'lulus' => $alumni && $grade12 !== null ? $grade12 + 1 : null,
            'angkatan' => $angkatan,
            'competition' => (string) $a->competition_id,
            'lomba' => (string) $a->getAttribute('k_name'),
            'organizer' => (string) ($a->getAttribute('k_organizer') ?? ''),
            'bidang' => $bidang->value,
            'level' => $a->level->value,
            'kategori' => $a->weight_category->value,
            'result' => $a->result->value,
            'w_level' => Decimal::normalize((string) $a->w_level),
            'w_result' => Decimal::normalize((string) $a->w_result),
            'w_organizer' => Decimal::normalize((string) $a->w_organizer),
            'points' => Decimal::roundHalfUp((string) $a->points, 2),
            'tahun' => $tahun,
            'final_on' => $finalOn,
            'verified_at' => $a->verified_at->format('Y-m-d H:i:s'),
            'certificate' => $certificates[(string) $a->report_id] ?? null,
        ];
    }

    /**
     * Public identity of a row: name (or the anonymous label), class, alumni flag and the slug link.
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, named: bool, slug: string|null, kelas: string|null, alumni: bool, lulus: int|null}
     */
    private function person(array $row): array
    {
        return [
            'name' => $row['name'],
            'named' => $row['named'],
            'slug' => $row['slug'],
            'kelas' => $row['kelas'],
            'alumni' => $row['alumni'],
            'lulus' => $row['named'] ? $row['lulus'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function competitionFields(array $row): array
    {
        $bidang = Bidang::from($row['bidang']);

        return [
            'competition' => $row['competition'],
            'lomba' => $row['lomba'],
            'organizer' => $row['organizer'],
            'bidang' => $bidang->value,
            'bidang_key' => $bidang->cssKey(),
            'bidang_label' => $bidang->label(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function achievementItem(array $row, bool $certificates): array
    {
        $level = Level::from($row['level']);
        $result = ResultType::from($row['result']);
        $wLevel = $this->formatter->format($row['w_level'], 2);
        $wResult = $this->formatter->formatWeight($row['w_result']);
        $wOrganizer = $this->formatter->formatWeight($row['w_organizer']);
        $points = $this->formatter->format($row['points']);

        return $this->competitionFields($row) + [
            'level_label' => $level->shortLabel(),
            'result_label' => $result->label(),
            'podium' => $result->isPodium(),
            'kategori_label' => WeightCategory::from($row['kategori'])->label(),
            'tahun' => $row['tahun'],
            'w_level' => $wLevel,
            'w_result' => $wResult,
            'w_organizer' => $wOrganizer,
            'points' => $row['points'],
            'points_label' => $points,
            'rumus' => $wLevel.' × '.$wResult.' × '.$wOrganizer.' = '.$points,
            'verified_on' => substr($row['verified_at'], 0, 10),
            'verified_label' => $this->dateLabel($row['verified_at']),
            'certificate' => $certificates ? $this->publicCertificate($row['certificate']) : null,
        ];
    }

    /**
     * The certificate file id only when a guest may open it through `files.public` (FilePolicy::viewPublic:
     * verified report, Admin switch on, publish consent of every team member); otherwise null (badge only).
     */
    private function publicCertificate(?string $fileId): ?string
    {
        if ($fileId === null) {
            return null;
        }

        $file = File::query()->find($fileId);

        return $file !== null && Gate::forUser(null)->allows('viewPublic', $file) ? $fileId : null;
    }

    /**
     * @param  array<string, int>  $counts  bidang value => count
     * @return list<array{value: string, key: string, label: string, count: int, pct: int}>
     */
    private function bidangList(array $counts, int $total): array
    {
        return array_map(fn (Bidang $b): array => [
            'value' => $b->value,
            'key' => $b->cssKey(),
            'label' => $b->label(),
            'count' => $counts[$b->value] ?? 0,
            'pct' => $total > 0 ? (int) round(($counts[$b->value] ?? 0) * 100 / $total) : 0,
        ], Bidang::cases());
    }

    /**
     * Better "best capaian": higher result tier, then higher tingkat, then more points.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $best
     */
    private function isBetter(array $row, array $best): bool
    {
        $rowTier = ResultType::from($row['result'])->tier();
        $bestTier = ResultType::from($best['result'])->tier();

        if ($rowTier !== $bestTier) {
            return $rowTier < $bestTier;
        }

        $rowLevel = Level::from($row['level'])->rank();
        $bestLevel = Level::from($best['level'])->rank();

        if ($rowLevel !== $bestLevel) {
            return $rowLevel > $bestLevel;
        }

        return bccomp($row['points'], $best['points'], 2) > 0;
    }

    /** Bar height in % of the tallest bar; a non-zero value stays visible (≥ 4 %). */
    private function barPercent(int $count, int $max): int
    {
        if ($count <= 0 || $max <= 0) {
            return 0;
        }

        return max(4, min(100, (int) round($count * 100 / $max)));
    }

    /** @return list<string> the last three school years, oldest first, ending with $current */
    private function recentYears(SchoolYear $current): array
    {
        return [
            (new SchoolYear($current->startYear - 2))->toString(),
            (new SchoolYear($current->startYear - 1))->toString(),
            $current->toString(),
        ];
    }

    /**
     * Distinct lomba of a school year: final date inside the year and either a verified achievement or a
     * registration that is still alive (not cancelled, not rejected by the principal).
     */
    private function competitionsInYear(SchoolYear $year): int
    {
        return Competition::query()
            ->whereBetween('final_on', [sprintf('%04d-07-01', $year->startYear), sprintf('%04d-06-30', $year->endYear())])
            ->where(fn ($query) => $query
                ->whereHas('achievements')
                ->orWhereHas('registrations', fn ($registrations) => $registrations->whereNotIn('status', [
                    RegistrationStatus::Dibatalkan->value,
                    RegistrationStatus::Ditolak->value,
                ])))
            ->count();
    }

    /** Give consenting students with achievements a random URL key (SECURITY §3m-7: never NIS or an id). */
    private function assignMissingSlugs(): void
    {
        Student::query()
            ->select(['id'])
            ->where('publish_consent', true)
            ->whereNull('public_slug')
            ->whereHas('achievements')
            ->get()
            ->each(fn (Student $student) => $student->forceFill(['public_slug' => bin2hex(random_bytes(10))])->saveQuietly());
    }

    /**
     * Reports with exactly one certificate file (a team report with one file per member is ambiguous and
     * never linked). Only used when Admin enabled public certificates.
     *
     * @return array<string, string> report id => file id
     */
    private function singleCertificates(): array
    {
        $files = [];

        foreach (ReportFile::query()->select(['report_id', 'file_id'])->where('kind', ReportFileKind::Sertifikat)->get() as $file) {
            $files[(string) $file->report_id][] = (string) $file->file_id;
        }

        return array_map(fn (array $ids): string => $ids[0], array_filter($files, fn (array $ids): bool => count($ids) === 1));
    }

    private function schoolYearOf(DateTimeInterface $date): ?string
    {
        try {
            return SchoolYear::containing($date)->toString();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function currentYear(): SchoolYear
    {
        return SchoolYear::containing(now());
    }

    private function dateLabel(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->locale('id')->translatedFormat('j M Y');
        } catch (Throwable) {
            return $date;
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function remember(string $name, array $args, Closure $callback): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);
        $key = sprintf('publik:v%d:%s:%s', $version, $name, md5((string) json_encode($args)));

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }
}
