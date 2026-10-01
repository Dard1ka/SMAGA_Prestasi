<?php

declare(strict_types=1);

namespace App\Livewire\Sekolah;

use App\Domain\Archive\SchoolYear;
use App\Domain\Deadlines\ReportDeadline;
use App\Domain\Points\PointFormatter;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\Level;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard sekolah (mockup Dashboard-Sekolah, UI-MAP #20): Kepala Sekolah at `kepsek.beranda`, also meant
 * for Admin at `admin.beranda` (W2-D mounts it). Aggregates only, from verified achievements
 * (`dashboard.view`). Charts are plain HTML bars sized with `h-pct-*` / `w-pct-*` (no inline styles), each
 * with an equivalent table for screen readers. Numbers are computed here; the view only formats them.
 */
#[Title('Dashboard prestasi')]
class Dashboard extends Component
{
    /** Chart order validated for colour-blind neighbours (mockup-v2-notes/G.md). */
    public const CHART_ORDER = [Bidang::Sains, Bidang::SeniBudaya, Bidang::Riset, Bidang::Olahraga];

    public const YEARS_IN_CHART = 4;

    #[Url(as: 'tahun', except: '')]
    public string $tahun = '';

    public function mount(): void
    {
        Gate::authorize(Permission::DashboardView->value);
        $this->tahun = SchoolYear::isValid($this->tahun) ? $this->tahun : '';
    }

    public function updatedTahun(): void
    {
        $this->tahun = SchoolYear::isValid($this->tahun) ? $this->tahun : '';
    }

    public function render(PointFormatter $points): View
    {
        Gate::authorize(Permission::DashboardView->value);

        $today = CarbonImmutable::today();
        $currentYear = SchoolYear::containing($today)->toString();
        $all = $this->achievementRows();
        $selected = $this->tahun === '' ? $all : $all->where('year', $this->tahun)->values();

        $deadlineDays = app(ReportDeadline::class)->days();
        $waiting = Registration::query()->awaitingPrincipal()->count();
        $overdue = $this->overdueReports($today, $deadlineDays);
        $canApprove = Gate::allows('viewApprovalQueue', Registration::class) && Route::has('kepsek.persetujuan.index');

        return view('livewire.sekolah.dashboard', [
            'demo' => app()->environment('local'),
            'yearOptions' => $this->yearOptions($all, $currentYear),
            'currentYear' => $currentYear,
            'kpi' => [
                'prestasi' => $selected->count(),
                'prestasiNote' => $this->tahun === ''
                    ? $all->where('year', $currentYear)->count().' di tahun ajaran ini'
                    : 'Tahun ajaran '.$this->tahun,
                'siswa' => $selected->pluck('student_id')->unique()->count(),
                'menunggu' => $waiting,
                'lewat' => $overdue,
                'deadlineDays' => $deadlineDays,
            ],
            'approvalUrl' => $canApprove ? route('kepsek.persetujuan.index') : null,
            'perYear' => $this->perYear($all, $currentYear),
            'perBidang' => $this->perBidang($selected),
            'perLevel' => $this->perLevel($selected),
            'top' => $this->topStudents($all, $points),
            'attention' => $this->attention($today, $waiting, $overdue, $deadlineDays, $canApprove),
            'prestasiRoute' => Route::has('public.prestasi') ? 'public.prestasi' : null,
            'peringkatUrl' => Route::has('public.peringkat') ? route('public.peringkat') : null,
            'laporanUrl' => Route::has('kepsek.laporan') ? route('kepsek.laporan') : null,
        ])->layoutData([
            'subtitle' => 'Prestasi terverifikasi siswa SMA Negeri 3 Semarang.',
            'badges' => ['kepsek.persetujuan' => $waiting],
        ]);
    }

    /**
     * One row per verified achievement (portable SQL; grouped in PHP so SQLite and MySQL agree).
     *
     * @return Collection<int, array{student_id: string, points: string, level: ?Level, bidang: ?Bidang, year: string}>
     */
    private function achievementRows(): Collection
    {
        return DB::table('achievements')
            ->join('competitions', 'competitions.id', '=', 'achievements.competition_id')
            ->whereNotNull('achievements.verified_at')
            ->get(['achievements.student_id', 'achievements.points', 'achievements.level', 'competitions.bidang', 'competitions.final_on'])
            ->map(fn (object $row): array => [
                'student_id' => (string) $row->student_id,
                'points' => (string) ($row->points ?? '0'),
                'level' => Level::tryFrom((string) $row->level),
                'bidang' => Bidang::tryFrom((string) $row->bidang),
                'year' => $row->final_on !== null
                    ? SchoolYear::containing(CarbonImmutable::parse((string) $row->final_on))->toString()
                    : '',
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array<string, string>
     */
    private function yearOptions(Collection $all, string $currentYear): array
    {
        $years = $all->pluck('year')->filter()->push($currentYear)->unique()->sortDesc()->values();

        return ['' => 'Semua tahun'] + $years->mapWithKeys(fn (string $year): array => [$year => $year])->all();
    }

    /**
     * Grouped bars: last N school years × bidang.
     *
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array{years: list<array{year: string, total: int, running: bool, bars: list<array{bidang: Bidang, value: int, pct: int}>}>, max: int, best: ?array{year: string, total: int}, ticks: list<int>}
     */
    private function perYear(Collection $all, string $currentYear): array
    {
        $years = $all->pluck('year')->filter()->unique()->sort()->values()->take(-self::YEARS_IN_CHART)->values();
        $grouped = $all->groupBy('year');
        $max = 0;

        foreach ($years as $year) {
            foreach (self::CHART_ORDER as $bidang) {
                $max = max($max, $grouped->get($year, collect())->where('bidang', $bidang)->count());
            }
        }

        $scaleMax = self::niceMax($max);
        $result = [];

        foreach ($years as $year) {
            $rows = $grouped->get($year, collect());
            $bars = [];

            foreach (self::CHART_ORDER as $bidang) {
                $value = $rows->where('bidang', $bidang)->count();
                $bars[] = ['bidang' => $bidang, 'value' => $value, 'pct' => self::pct($value, $scaleMax)];
            }

            $result[] = ['year' => $year, 'total' => $rows->count(), 'running' => $year === $currentYear, 'bars' => $bars];
        }

        $best = collect($result)->sortByDesc('total')->first();

        return [
            'years' => $result,
            'max' => $scaleMax,
            'best' => $best !== null && $best['total'] > 0 ? ['year' => $best['year'], 'total' => $best['total']] : null,
            'ticks' => $scaleMax > 0 ? [0, intdiv($scaleMax, 3), intdiv($scaleMax * 2, 3), $scaleMax] : [0],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{bidang: Bidang, value: int, pct: int}>
     */
    private function perBidang(Collection $rows): array
    {
        $counts = [];

        foreach (Bidang::cases() as $bidang) {
            $counts[] = ['bidang' => $bidang, 'value' => $rows->where('bidang', $bidang)->count()];
        }

        usort($counts, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $max = max(1, ...array_column($counts, 'value'));

        return array_map(fn (array $row): array => $row + ['pct' => self::pct($row['value'], $max)], $counts);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: list<array{level: Level, value: int, pct: int}>, nationalUp: int, total: int}
     */
    private function perLevel(Collection $rows): array
    {
        $levels = [];

        foreach ([Level::Sekolah, Level::Kota, Level::Provinsi, Level::Nasional, Level::Internasional] as $level) {
            $value = $rows->where('level', $level)->count();

            if ($value > 0 || $level !== Level::Sekolah) {
                $levels[] = ['level' => $level, 'value' => $value];
            }
        }

        $max = max(1, ...array_column($levels, 'value'));

        return [
            'rows' => array_map(fn (array $row): array => $row + ['pct' => self::pct($row['value'], $max)], $levels),
            'nationalUp' => $rows->whereIn('level', [Level::Nasional, Level::Internasional])->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Five highest cumulative totals over all years (archived students included, D-09).
     *
     * @param  Collection<int, array<string, mixed>>  $all
     * @return list<array{rank: int, name: string, meta: string, points: string, alumni: bool}>
     */
    private function topStudents(Collection $all, PointFormatter $formatter): array
    {
        $totals = [];
        $byBidang = [];

        foreach ($all as $row) {
            $totals[$row['student_id']] = bcadd($totals[$row['student_id']] ?? '0', $row['points'], 2);

            if ($row['bidang'] !== null) {
                $key = $row['bidang']->value;
                $byBidang[$row['student_id']][$key] = bcadd($byBidang[$row['student_id']][$key] ?? '0', $row['points'], 2);
            }
        }

        uasort($totals, fn (string $a, string $b): int => bccomp($b, $a, 2));
        $ids = array_slice(array_keys($totals), 0, 5);

        if ($ids === []) {
            return [];
        }

        $students = Student::withTrashed()->with(['user', 'classroom'])->whereKey($ids)->get()->keyBy('id');
        $result = [];

        foreach ($ids as $index => $id) {
            $student = $students->get($id);
            $bidangTotals = $byBidang[$id] ?? [];
            uasort($bidangTotals, fn (string $a, string $b): int => bccomp($b, $a, 2));
            $main = Bidang::tryFrom((string) array_key_first($bidangTotals));

            $result[] = [
                'rank' => $index + 1,
                'name' => (string) ($student?->user?->name ?? 'Siswa'),
                'meta' => trim(($student?->classroom?->name ?? '').($main !== null ? ' · '.$main->label() : ''), ' ·'),
                'points' => $formatter->format($totals[$id]),
                'alumni' => (bool) $student?->user?->isArchived(),
            ];
        }

        return $result;
    }

    private function overdueReports(CarbonImmutable $today, int $deadlineDays): int
    {
        return Registration::query()
            ->status(RegistrationStatus::MenungguLaporan)
            ->whereHas('competition', fn ($q) => $q->withTrashed()->whereDate('final_on', '<', $today->subDays($deadlineDays)))
            ->count();
    }

    /** @return list<array{title: string, note: string, tone: string, url: ?string}> */
    private function attention(CarbonImmutable $today, int $waiting, int $overdue, int $deadlineDays, bool $canApprove): array
    {
        $items = [];

        if ($waiting > 0) {
            $nearest = Competition::withTrashed()
                ->whereHas('registrations', fn ($q) => $q->awaitingPrincipal())
                ->whereNotNull('starts_on')
                ->orderBy('starts_on')
                ->first();

            $items[] = [
                'title' => $waiting.' pendaftaran menunggu persetujuan',
                'note' => $nearest !== null ? 'Terdekat: '.$nearest->name.', '.$nearest->starts_on?->translatedFormat('j M') : 'Tinjau pendaftaran.',
                'tone' => 'orange',
                'url' => $canApprove ? route('kepsek.persetujuan.index') : null,
            ];
        }

        if ($overdue > 0) {
            $items[] = [
                'title' => $overdue.' laporan lewat batas '.$deadlineDays.' hari',
                'note' => 'Siswa belum melaporkan hasil.',
                'tone' => 'red',
                'url' => null,
            ];
        }

        $staleProposals = Competition::query()
            ->where('status', CompetitionStatus::MenungguVerifikasi)
            ->where('created_at', '<', $today->subDays(7))
            ->count();

        if ($staleProposals > 0) {
            $items[] = [
                'title' => $staleProposals.' usulan lomba belum diperiksa',
                'note' => 'Di Kesiswaan lebih dari 7 hari.',
                'tone' => 'gray',
                'url' => null,
            ];
        }

        return $items;
    }

    /** Round the axis maximum up to a multiple of 3 (three even gridlines), at least 3. */
    private static function niceMax(int $max): int
    {
        return max(3, (int) (ceil($max / 3) * 3));
    }

    private static function pct(int $value, int $max): int
    {
        if ($max <= 0 || $value <= 0) {
            return 0;
        }

        return max(2, min(100, (int) round($value / $max * 100)));
    }
}
