<?php

declare(strict_types=1);

namespace App\Livewire\Kepsek;

use App\Actions\Reports\ExportPeriod;
use App\Actions\Reports\ExportPrestasiData;
use App\Domain\Archive\SchoolYear;
use App\Domain\Points\PointFormatter;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Models\Achievement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kepala Sekolah · Prestasi per bidang (UI-MAP §4b `kepsek.prestasi`, backlog #6, owner B5): the internal
 * variant of the public drill-down (board Prestasi-Bidang) in the staff layout — level 1 bidang, level 2
 * students of the bidang, level 3 one student's verified achievements. Internal, so every student is named
 * (no consent gate, unlike PublicAchievements) and alumni/nonaktif get a chip; still no NIS/NISN anywhere.
 * Data: App\Actions\Reports\ExportPrestasiData. `viewSchoolReport` (achievement.view_all + dashboard.view).
 */
#[Title('Prestasi per bidang')]
#[Layout('layouts::app', ['subtitle' => 'Semua prestasi terverifikasi, per bidang dan siswa.'])]
final class PrestasiBidang extends Component
{
    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $tahun = '';

    #[Url(except: '')]
    public string $tingkat = '';

    /** Student ULID of level 3 (internal page, ULIDs only in URLs). */
    #[Url(except: '')]
    public string $siswa = '';

    public function mount(): void
    {
        Gate::authorize('viewSchoolReport', Achievement::class);
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    public function pilihBidang(string $bidang): void
    {
        if ($bidang !== $this->bidang) {
            $this->siswa = '';
        }

        $this->bidang = $bidang;
        $this->normalize();
    }

    public function pilihSiswa(string $studentId): void
    {
        $this->siswa = $studentId;
        $this->normalize();
    }

    public function tutupSiswa(): void
    {
        $this->siswa = '';
    }

    public function render(ExportPrestasiData $data, PointFormatter $formatter): View
    {
        Gate::authorize('viewSchoolReport', Achievement::class);

        $period = $this->tahun !== '' ? ExportPeriod::schoolYear(SchoolYear::fromString($this->tahun)) : ExportPeriod::all();
        $rows = $data->rows($period, null, Level::tryFrom($this->tingkat));
        $bidang = Bidang::tryFrom($this->bidang) ?? Bidang::Sains;
        $total = $rows->count();

        $tiles = collect(Bidang::cases())->map(function (Bidang $b) use ($rows, $total): array {
            $count = $rows->where('bidang', $b)->count();

            return [
                'value' => $b->value,
                'key' => $b->cssKey(),
                'label' => $b->label(),
                'count' => $count,
                'pct' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        })->all();

        $students = $this->students($rows->where('bidang', $bidang), $formatter);
        $detail = $this->siswa !== '' ? $this->detail($rows->where('student_id', $this->siswa), $formatter) : null;

        return view('livewire.kepsek.prestasi-bidang', [
            'total' => $total,
            'tiles' => $tiles,
            'selected' => $bidang,
            'students' => $students,
            'detail' => $detail,
            'yearOptions' => collect($data->schoolYears())->mapWithKeys(fn (string $y) => [$y => $y])->all(),
            'levelOptions' => collect([Level::Internasional, Level::Nasional, Level::Provinsi, Level::Kota, Level::Sekolah])
                ->mapWithKeys(fn (Level $l) => [$l->value => $l->label()])->all(),
            'laporanUrl' => Route::has('kepsek.laporan') ? route('kepsek.laporan', array_filter(['tahun' => $this->tahun, 'bidang' => $bidang->value])) : null,
        ]);
    }

    /**
     * Level 2: students with at least one achievement in the bidang, by points.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function students(Collection $rows, PointFormatter $formatter): array
    {
        return $rows->groupBy('student_id')
            ->map(function (Collection $g, string $id) use ($formatter): array {
                $points = $g->reduce(fn (string $s, array $r): string => bcadd($s, $r['points'], 2), '0');
                // Best result = most points (tingkat × capaian × penyelenggara), then the better capaian (tier 1 = juara 1).
                $best = $g->sort(fn (array $a, array $b): int => bccomp($b['points'], $a['points'], 2) ?: $a['result']->tier() <=> $b['result']->tier())->first();

                return [
                    'id' => $id,
                    'name' => $g->first()['name'],
                    'kelas' => $g->first()['kelas'],
                    'status' => $g->first()['status'],
                    'count' => $g->count(),
                    'points' => $points,
                    'pointsLabel' => $formatter->format($points),
                    'best' => $best['result']->label().' · '.$best['level']->shortLabel(),
                ];
            })
            ->sort(fn (array $a, array $b): int => bccomp($b['points'], $a['points'], 2) ?: strcmp($a['name'], $b['name']))
            ->values()
            ->all();
    }

    /**
     * Level 3: one student's achievements in the filtered period (all bidang).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function detail(Collection $rows, PointFormatter $formatter): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();
        $points = $rows->reduce(fn (string $s, array $r): string => bcadd($s, $r['points'], 2), '0');

        return [
            'name' => $first['name'],
            'kelas' => $first['kelas'],
            'status' => $first['status'],
            'count' => $rows->count(),
            'pointsLabel' => $formatter->format($points),
            'items' => $rows->sortByDesc('date')->map(fn (array $r): array => [
                'lomba' => $r['lomba'],
                'organizer' => $r['organizer'],
                'bidang' => $r['bidang'],
                'tingkat' => $r['level']->label(),
                'capaian' => $r['result']->label(),
                'podium' => $r['result']->isPodium(),
                'points' => $formatter->format($r['points']),
                'date' => CarbonImmutable::parse($r['date'])->translatedFormat('j M Y'),
                'mentor' => $r['mentor'],
            ])->values()->all(),
        ];
    }

    private function normalize(): void
    {
        $this->bidang = Bidang::tryFrom($this->bidang)?->value ?? '';
        $this->tahun = SchoolYear::isValid($this->tahun) ? $this->tahun : '';
        $this->tingkat = Level::tryFrom($this->tingkat)?->value ?? '';
        $this->siswa = preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $this->siswa) === 1 ? strtolower($this->siswa) : '';
    }
}
