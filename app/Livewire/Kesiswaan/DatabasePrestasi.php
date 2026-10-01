<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Exports\AchievementFilters;
use App\Actions\Exports\ExportAchievements;
use App\Domain\Archive\SchoolYear;
use App\Domain\Points\PointFormatter;
use App\Domain\Support\Decimal;
use App\Enums\Bidang;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\RegistrationType;
use App\Enums\ReportFileKind;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\ReportFile;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesiswaan · Database prestasi (UI-MAP §4a `kesiswaan.prestasi.index`, backlog #4, owner B4): every
 * verified achievement with filters (tahun ajaran, siswa, kelas, angkatan, bidang, tingkat, jenis
 * penyelenggara, status ajang, pembimbing, individu/tim, capaian), totals, a detail drawer and an audited
 * Excel export (ExportAchievements, `achievement.export`, password from the last 15 minutes). NIS is masked
 * on screen. Filters live in the URL; the same AchievementFilters object drives list, totals and export.
 */
#[Title('Database prestasi')]
#[Layout('layouts::app', ['subtitle' => 'Semua prestasi terverifikasi, bisa disaring dan diunduh.'])]
final class DatabasePrestasi extends Component
{
    use RequiresRecentPassword, WithKesiswaanBadges, WithPagination;

    public const PER_PAGE = 25;

    public const DRAWER = 'prestasi-detail';

    /** Filters shown under "Filter lainnya" (the first row holds tahun, bidang, tingkat and the search). */
    public const MORE_FILTERS = ['kelas', 'angkatan', 'jenis', 'ajang', 'pembimbing', 'tipe', 'capaian'];

    #[Url(except: '')]
    public string $tahun = '';

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $kelas = '';

    #[Url(except: '')]
    public string $angkatan = '';

    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $tingkat = '';

    #[Url(except: '')]
    public string $jenis = '';

    #[Url(except: '')]
    public string $ajang = '';

    #[Url(except: '')]
    public string $pembimbing = '';

    #[Url(except: '')]
    public string $tipe = '';

    #[Url(except: '')]
    public string $capaian = '';

    #[Locked]
    public ?string $detailId = null;

    public function mount(): void
    {
        Gate::authorize('viewDatabase', Achievement::class);
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, AchievementFilters::KEYS, true)) {
            $this->normalize();
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        foreach (AchievementFilters::KEYS as $key) {
            $this->{$key} = '';
        }

        $this->resetPage();
    }

    public function showDetail(string $id): void
    {
        Gate::authorize('viewDatabase', Achievement::class);

        $achievement = Achievement::query()->find($id);
        abort_if($achievement === null, 404);

        $this->detailId = (string) $achievement->getKey();
        $this->dispatch('sth-modal-open', id: self::DRAWER);
    }

    /** Excel download of the filtered list (audited inside ExportAchievements). */
    public function export(ExportAchievements $export): ?BinaryFileResponse
    {
        Gate::authorize('export', Achievement::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return null;
        }

        try {
            $file = $export->handle($this->actor(), $this->filters());
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: (string) collect($e->errors())->flatten()->first(), tone: 'error');

            return null;
        }

        return response()->download($file->path, $file->filename)->deleteFileAfterSend();
    }

    public function render(PointFormatter $formatter): View
    {
        Gate::authorize('viewDatabase', Achievement::class);
        $this->normalize();

        $filters = $this->filters();
        $base = $filters->apply(Achievement::query());

        $page = (clone $base)
            ->with([
                'student:id,user_id,classroom_id,nis',
                'student.user:id,name',
                'student.classroom:id,name',
                'competition:id,name,organizer_name,bidang,final_on',
                'report:id,registration_id',
                'report.registration:id,type',
            ])
            ->orderByDesc('achievements.verified_at')
            ->orderBy('achievements.id')
            ->paginate(self::PER_PAGE);

        return view('livewire.kesiswaan.database-prestasi', [
            'rows' => collect($page->items())->map(fn (Achievement $a): array => $this->row($a, $formatter))->all(),
            'page' => $page,
            'totals' => $this->totals($base, $formatter),
            'options' => $this->options(),
            'active' => $filters->describe(),
            'moreActive' => count(array_filter(self::MORE_FILTERS, fn (string $key): bool => $this->{$key} !== '')),
            'canExport' => Gate::allows('export', Achievement::class),
            'detail' => $this->detailId !== null ? $this->detail($this->detailId, $formatter) : null,
        ])->layoutData(['badges' => $this->kesiswaanBadges()]);
    }

    protected function returnUrl(): string
    {
        return route('kesiswaan.prestasi.index', $this->filters()->toArray());
    }

    private function filters(): AchievementFilters
    {
        $input = [];

        foreach (AchievementFilters::KEYS as $key) {
            $input[$key] = $this->{$key};
        }

        return AchievementFilters::fromInput($input);
    }

    /**
     * @param  Builder<Achievement>  $base
     * @return array{count: string, students: string, points: string, podium: string}
     */
    private function totals(Builder $base, PointFormatter $formatter): array
    {
        $points = (clone $base)->pluck('achievements.points')
            ->reduce(fn (string $sum, mixed $value): string => bcadd($sum, Decimal::roundHalfUp((string) $value, 2), 2), '0.00');

        $podium = array_values(array_filter(ResultType::cases(), fn (ResultType $r): bool => $r->isPodium()));

        return [
            'count' => number_format((clone $base)->count(), 0, ',', '.'),
            'students' => number_format((clone $base)->distinct()->count('achievements.student_id'), 0, ',', '.'),
            'points' => $formatter->format($points),
            'podium' => number_format((clone $base)->whereIn('achievements.result', $podium)->count(), 0, ',', '.'),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Achievement $a, PointFormatter $formatter): array
    {
        $competition = $a->competition;

        return [
            'id' => (string) $a->getKey(),
            'name' => $a->student?->user?->name ?? 'Siswa',
            'kelas' => $a->student?->classroom?->name ?? '–',
            'nis' => self::maskNis($a->student?->nis),
            'lomba' => $competition?->name ?? 'Lomba',
            'organizer' => $competition?->organizer_name,
            'bidang' => $competition?->bidang,
            'tingkat' => $a->level->shortLabel(),
            'capaian' => $a->result->label(),
            'podium' => $a->result->isPodium(),
            'tipe' => $a->report?->registration?->type?->label(),
            'tahun' => self::schoolYear($competition?->final_on ?? $a->verified_at),
            'points' => $formatter->format((string) $a->points),
        ];
    }

    /** @return array<string, mixed>|null */
    private function detail(string $id, PointFormatter $formatter): ?array
    {
        $a = Achievement::query()
            ->with([
                'student.user',
                'student.classroom',
                'competition',
                'weightVersion',
                'report.verifier',
                'report.files.file',
                'report.registration.mentor',
                'report.registration.members' => fn ($m) => $m->whereNull('declined_at')->orderByRaw("CASE WHEN role = 'ketua' THEN 0 ELSE 1 END"),
                'report.registration.members.student.user',
                'report.registration.members.student.classroom',
            ])
            ->find($id);

        if ($a === null) {
            return null;
        }

        $student = $a->student;
        $user = $student?->user;
        $competition = $a->competition;
        $registration = $a->report?->registration;

        return [
            'name' => $user?->name ?? 'Siswa',
            'student' => [
                ['label' => 'Kelas', 'value' => $student?->classroom?->name ?? '–'],
                ['label' => 'NIS', 'value' => self::maskNis($student?->nis) ?: '–'],
                ['label' => 'Angkatan', 'value' => $student?->grade12_start_date !== null ? (string) ($student->grade12_start_date->year - 2) : '–'],
                ['label' => 'Status akun', 'value' => match (true) {
                    $user === null || $user->trashed() || ($student?->trashed() ?? false) => 'Dihapus',
                    $user->status === UserStatus::Archived => 'Alumni',
                    default => $user->status?->label() ?? '–',
                }],
                ['label' => 'Boleh dipublikasikan', 'value' => $student?->publish_consent ? 'Ya' : 'Tidak'],
            ],
            'lomba' => $competition?->name ?? 'Lomba',
            'bidang' => $competition?->bidang,
            'competition' => array_values(array_filter([
                ['label' => 'Penyelenggara', 'value' => $competition?->organizer_name],
                ['label' => 'Jenis penyelenggara', 'value' => $competition?->organizer_type?->label()],
                ['label' => 'Kategori', 'value' => $a->weight_category->label()],
                ['label' => 'Status ajang', 'value' => $competition?->event_status?->label()],
                ['label' => 'Tingkat', 'value' => $a->level->label()],
                ['label' => 'Tanggal final', 'value' => self::longDate($competition?->final_on)],
                ['label' => 'Tahun ajaran', 'value' => self::schoolYear($competition?->final_on ?? $a->verified_at)],
            ], fn (array $fact): bool => is_string($fact['value']) && $fact['value'] !== '')),
            'capaian' => $a->result->label(),
            'podium' => $a->result->isPodium(),
            'rumus' => [
                'tingkat' => Decimal::normalize((string) $a->w_level),
                'capaian' => Decimal::normalize((string) $a->w_result),
                'penyelenggara' => Decimal::normalize((string) $a->w_organizer),
                'total' => Decimal::roundHalfUp((string) $a->points, 2),
            ],
            'pointsLabel' => $formatter->format((string) $a->points),
            'verified' => self::longDate($a->verified_at),
            'verifier' => $a->report?->verifier?->name,
            'weightVersion' => $a->weightVersion?->effective_at !== null ? self::longDate($a->weightVersion->effective_at) : null,
            'tipe' => $registration?->type?->label(),
            'mentor' => $registration?->mentor?->name,
            'members' => $registration === null ? [] : $registration->members->map(fn (RegistrationMember $m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'meta' => trim(($m->student?->classroom?->name ?? '').' · '.$m->role?->label(), ' ·'),
            ])->all(),
            'files' => $a->report === null ? [] : $a->report->files
                ->filter(fn (ReportFile $rf): bool => $rf->kind === ReportFileKind::Sertifikat && $rf->file !== null && Gate::allows('view', $rf->file))
                ->map(fn (ReportFile $rf): array => [
                    'name' => (string) $rf->file?->original_name,
                    'href' => route('files.show', $rf->file_id),
                ])->values()->all(),
        ];
    }

    /** @return array<string, array<string, string>> select options for every filter */
    private function options(): array
    {
        $years = [SchoolYear::containing(now())->toString() => true];

        foreach (Competition::withTrashed()->whereHas('achievements')->pluck('final_on') as $finalOn) {
            if ($finalOn !== null && ($year = self::schoolYear($finalOn)) !== '') {
                $years[$year] = true;
            }
        }

        krsort($years);

        $angkatan = Student::withTrashed()->whereHas('achievements')->whereNotNull('grade12_start_date')
            ->pluck('grade12_start_date')
            ->map(fn ($date): int => (int) CarbonImmutable::parse($date)->year - 2)
            ->unique()->sortDesc()
            ->mapWithKeys(fn (int $year): array => [(string) $year => 'Angkatan '.$year])
            ->all();

        $mentors = User::withTrashed()
            ->where(fn (Builder $q) => $q
                ->whereHas('roles', fn (Builder $r) => $r->where('name', RoleName::Guru->value))
                ->orWhereIn('id', Registration::withTrashed()->whereNotNull('mentor_id')->select('mentor_id')))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(string) $id => $name])
            ->all();

        return [
            'tahun' => array_combine(array_keys($years), array_keys($years)),
            'kelas' => Classroom::query()->ordered()->pluck('name', 'id')->all(),
            'angkatan' => $angkatan,
            'bidang' => Bidang::options(),
            'tingkat' => Level::options(),
            'jenis' => OrganizerType::options(),
            'ajang' => EventStatus::options(),
            'pembimbing' => $mentors,
            'tipe' => RegistrationType::options(),
            'capaian' => ResultType::options(),
        ];
    }

    /** Drops values the filter object would ignore, so the URL and the selects stay in sync. */
    private function normalize(): void
    {
        $clean = $this->filters()->toArray();

        foreach (AchievementFilters::KEYS as $key) {
            $this->{$key} = $clean[$key] ?? '';
        }
    }

    private static function maskNis(?string $nis): string
    {
        if ($nis === null || $nis === '') {
            return '';
        }

        return mb_strlen($nis) <= 4 ? '••••' : mb_substr($nis, 0, 4).'•••'.mb_substr($nis, -2);
    }

    private static function schoolYear(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        try {
            return SchoolYear::containing($date)->toString();
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    private static function longDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M Y');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
