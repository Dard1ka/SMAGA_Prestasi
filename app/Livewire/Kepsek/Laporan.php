<?php

declare(strict_types=1);

namespace App\Livewire\Kepsek;

use App\Actions\Reports\ExportPeriod;
use App\Actions\Reports\ExportPrestasiData;
use App\Actions\Reports\ExportPrestasiReport;
use App\Domain\Archive\SchoolYear;
use App\Domain\Points\PointFormatter;
use App\Enums\Bidang;
use App\Enums\RoleName;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Achievement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kepala Sekolah · Laporan (UI-MAP §4b `kepsek.laporan`, backlog #6, owner B5): period report — counts per
 * bidang, tingkat, capaian, kelas and guru pembimbing plus the first rows of the detail list — and "Download
 * PDF/Excel" through App\Actions\Reports\ExportPrestasiReport (watermarked with the exporter's name + time,
 * audited, > 500 rows alert the Admins). Exports need a password confirmation from the last 15 minutes
 * (SECURITY §3m-9 "export"). Period = a school year, a date range (`?tahun=rentang&dari=&sampai=`) or all years.
 * Also reachable by Admin (the shared dashboard links "Download laporan" here).
 */
#[Title('Laporan prestasi')]
#[Layout('layouts::app', ['subtitle' => 'Rekap prestasi per periode. File yang di-download diberi tanda nama akun ini.'])]
final class Laporan extends Component
{
    use RequiresRecentPassword;

    public const PREVIEW_ROWS = 15;

    /** School year "YYYY/YYYY", "semua" or "rentang" (then dari/sampai). Default: the current school year. */
    #[Url(except: '')]
    public string $tahun = '';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $bidang = '';

    public function mount(): void
    {
        Gate::authorize('viewSchoolReport', Achievement::class);
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    public function export(string $format, ExportPrestasiReport $export): ?BinaryFileResponse
    {
        Gate::authorize('exportSchoolReport', Achievement::class);
        abort_unless(in_array($format, ExportPrestasiReport::FORMATS, true), 404);

        // $format is one of FORMATS here, so the call stays a literal.
        if (! $this->passwordRecentlyConfirmed("export('".$format."')")) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();
        $result = $export->handle($user, $format, $this->period(), Bidang::tryFrom($this->bidang));

        return response()->download($result['path'], $result['filename'], ['Content-Type' => $result['mime']])->deleteFileAfterSend();
    }

    public function render(ExportPrestasiData $data, PointFormatter $formatter): View
    {
        Gate::authorize('viewSchoolReport', Achievement::class);

        $period = $this->period();
        $rows = $data->rows($period, Bidang::tryFrom($this->bidang));
        $summary = $data->summary($rows);

        return view('livewire.kepsek.laporan', [
            'period' => $period,
            'summary' => $summary,
            'pointsTotal' => $formatter->format($summary['totals']['points']),
            'preview' => $rows->take(self::PREVIEW_ROWS)->map(fn (array $r): array => [
                'name' => $r['name'],
                'kelas' => $r['kelas'] !== '' ? $r['kelas'] : '–',
                'status' => $r['status'],
                'lomba' => $r['lomba'],
                'bidang' => $r['bidang'],
                'tingkat' => $r['level']->shortLabel(),
                'capaian' => $r['result']->label(),
                'points' => $formatter->format($r['points']),
                'date' => CarbonImmutable::parse($r['date'])->translatedFormat('j M Y'),
            ])->all(),
            'rowCount' => $rows->count(),
            'fmt' => fn (string $points): string => $formatter->format($points),
            'yearOptions' => collect($data->schoolYears())->mapWithKeys(fn (string $y) => [$y => 'Tahun ajaran '.$y])->all()
                + ['semua' => 'Semua tahun', 'rentang' => 'Rentang tanggal'],
            'bidangOptions' => collect(Bidang::cases())->mapWithKeys(fn (Bidang $b) => [$b->value => $b->label()])->all(),
            'canExport' => Gate::allows('exportSchoolReport', Achievement::class),
            // Only the Kepala Sekolah has "Surat atas nama saya" (Admin reaches this page from the shared dashboard).
            'suratUrl' => Route::has('kepsek.surat.index') && auth()->user()?->hasRole(RoleName::KepalaSekolah->value) ? route('kepsek.surat.index') : null,
        ]);
    }

    private function period(): ExportPeriod
    {
        if ($this->tahun === 'semua') {
            return ExportPeriod::all();
        }

        if ($this->tahun === 'rentang' && $this->dari !== '' && $this->sampai !== '') {
            return ExportPeriod::range(CarbonImmutable::parse($this->dari), CarbonImmutable::parse($this->sampai));
        }

        return ExportPeriod::schoolYear(SchoolYear::isValid($this->tahun)
            ? SchoolYear::fromString($this->tahun)
            : SchoolYear::containing(CarbonImmutable::now(config('app.timezone'))));
    }

    private function normalize(): void
    {
        $current = SchoolYear::containing(CarbonImmutable::now(config('app.timezone')))->toString();

        if (! in_array($this->tahun, ['semua', 'rentang'], true) && ! SchoolYear::isValid($this->tahun)) {
            $this->tahun = $current;
        }

        foreach (['dari', 'sampai'] as $field) {
            $this->{$field} = self::validDate($this->{$field}) ? $this->{$field} : '';
        }

        if ($this->tahun !== 'rentang') {
            $this->dari = '';
            $this->sampai = '';
        }

        $this->bidang = Bidang::tryFrom($this->bidang)?->value ?? '';
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return false;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value;
    }

    protected function returnUrl(): string
    {
        return route('kepsek.laporan', array_filter(['tahun' => $this->tahun, 'dari' => $this->dari, 'sampai' => $this->sampai, 'bidang' => $this->bidang]));
    }
}
