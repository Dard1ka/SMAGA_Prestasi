<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Domain\Points\PointFormatter;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\TalentStatus;
use App\Models\Achievement;
use App\Models\Registration;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\User;
use App\Support\Leaderboard\PersonalRank;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portofolio (UI-MAP #14, board Portofolio): profile header (points, rank among active students via
 * App\Support\Leaderboard\PersonalRank, counts, archive date), verified achievements with the weight snapshot
 * (rumus), own certificates through files.show, talent summary, and "Download portofolio (PDF)" (dompdf,
 * remote/JS/PHP off). Only the signed-in student's own data (AchievementPolicy).
 */
#[Title('Portofolio')]
#[Layout('layouts::app', ['subtitle' => 'Prestasi terverifikasi dan profil talentamu.'])]
final class Portofolio extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', Achievement::class);
        abort_if($this->student() === null, 403);
    }

    public function download(): StreamedResponse
    {
        Gate::authorize('viewAny', Achievement::class);

        $data = $this->data();
        $data['generatedOn'] = CarbonImmutable::now()->locale('id')->translatedFormat('j F Y');

        $pdf = Pdf::loadView('livewire.siswa.portofolio.pdf', $data)
            ->setPaper('a4')
            ->setOption([
                'isRemoteEnabled' => false,
                'isJavascriptEnabled' => false,
                'isPhpEnabled' => false,
                'chroot' => resource_path('views'),
                'allowedProtocols' => ['file://' => ['rules' => []]],
                'defaultFont' => 'DejaVu Sans',
            ]);

        $filename = 'portofolio-'.(Str::slug($data['name']) ?: 'siswa').'.pdf';

        return response()->streamDownload(fn () => print ($pdf->output()), $filename, ['Content-Type' => 'application/pdf']);
    }

    public function render(): View
    {
        return view('livewire.siswa.portofolio', $this->data());
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $student = $this->student();
        $student->loadMissing(['user', 'classroom']);
        $formatter = app(PointFormatter::class);

        $achievements = Achievement::query()
            ->where('student_id', $student->getKey())
            ->with(['competition', 'report.files'])
            ->orderByDesc('points')
            ->orderByDesc('verified_at')
            ->get()
            ->filter(fn (Achievement $a): bool => Gate::allows('view', $a))
            ->values();

        $total = $achievements->reduce(fn (string $sum, Achievement $a): string => bcadd($sum, (string) $a->points, 2), '0.00');

        $registrations = Registration::query()
            ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNull('declined_at'))
            ->whereNotIn('status', [RegistrationStatus::Ditolak, RegistrationStatus::Dibatalkan])
            ->with('competition')
            ->orderByDesc('created_at')
            ->get();

        $talents = TalentApplication::query()
            ->where('student_id', $student->getKey())
            ->with(['talentType', 'talentBranch', 'mentor.staffProfile'])
            ->orderBy('created_at')
            ->get();

        return [
            'name' => $student->user->name,
            'classroom' => $student->classroom?->name,
            'angkatan' => $student->grade12_start_date !== null ? $student->grade12_start_date->year - 2 : null,
            'activeUntil' => $student->archive_at?->locale('id')->translatedFormat('F Y'),
            'bidangMinat' => $talents
                ->reject(fn (TalentApplication $t): bool => $t->status === TalentStatus::TidakLolos)
                ->map(fn (TalentApplication $t) => $t->talentType?->bidang)
                ->filter()
                ->unique(fn ($b) => $b->value)
                ->values()
                ->all(),
            'totalPoints' => $formatter->format($total),
            'rank' => $this->rank($student),
            'achievementCount' => $achievements->count(),
            'registrationCount' => $registrations->count(),
            'achievements' => $achievements->map(fn (Achievement $a): array => $this->achievementRow($a, $formatter))->all(),
            'history' => $registrations->map(fn (Registration $r): array => [
                'id' => (string) $r->getKey(),
                'title' => $r->competition->name,
                'bidang' => $r->competition->bidang,
                'date' => $this->dateRange($r->competition->starts_on, $r->competition->final_on),
                'status' => $r->status,
                'href' => route('siswa.lomba-saya.show', $r),
            ])->all(),
            'talents' => $talents->map(fn (TalentApplication $t): array => [
                'title' => $t->talentType?->code->label().($t->talentBranch ? ' · '.$t->talentBranch->name : ''),
                'bidang' => $t->talentType?->bidang,
                'status' => $t->status,
                'info' => $this->talentInfo($t),
            ])->all(),
            'talentUrl' => route('siswa.talenta'),
        ];
    }

    /** @return array<string, mixed> */
    private function achievementRow(Achievement $achievement, PointFormatter $formatter): array
    {
        $competition = $achievement->competition;
        $certificate = $achievement->report?->files->firstWhere('kind', ReportFileKind::Sertifikat);

        return [
            'id' => (string) $achievement->getKey(),
            'title' => $competition->name,
            'bidang' => $competition->bidang,
            'level' => $achievement->level->shortLabel(),
            'result' => $achievement->result->label(),
            'organizer' => collect([$competition->organizer_name, $achievement->weight_category->label()])->filter()->implode(' · '),
            'date' => $this->dateRange($competition->starts_on, $competition->final_on),
            'points' => $formatter->format((string) $achievement->points),
            'formula' => $formatter->format((string) $achievement->w_level, 0).' × '
                .$formatter->formatWeight((string) $achievement->w_result).' × '
                .$formatter->formatWeight((string) $achievement->w_organizer),
            'certificateUrl' => $certificate !== null && Route::has('files.show') ? route('files.show', $certificate->file_id) : null,
        ];
    }

    /** Rank among active students (D-37, DESIGN-SPEC §5: "#14 dari siswa aktif"); null without points. */
    private function rank(Student $student): ?int
    {
        return app(PersonalRank::class)->rank($student);
    }

    private function talentInfo(TalentApplication $application): string
    {
        return match ($application->status) {
            TalentStatus::Terdaftar => 'Menunggu pemetaan Kesiswaan',
            TalentStatus::Pembinaan => $application->mentor !== null
                ? 'Pembina '.trim(($application->mentor->staffProfile?->honorific ? $application->mentor->staffProfile->honorific.' ' : '').$application->mentor->name)
                : 'Peserta pembinaan',
            TalentStatus::TidakLolos => 'Belum lolos tahun ini',
            default => 'Tahap '.$application->status->step().' dari 4',
        };
    }

    private function dateRange(?\DateTimeInterface $start, ?\DateTimeInterface $end): string
    {
        if ($start === null && $end === null) {
            return '';
        }

        $start = CarbonImmutable::instance($start ?? $end)->locale('id');
        $end = CarbonImmutable::instance($end ?? $start)->locale('id');

        return match (true) {
            $start->isSameDay($end) => $start->translatedFormat('j M Y'),
            $start->isSameMonth($end) => $start->day.'–'.$end->translatedFormat('j M Y'),
            default => $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y'),
        };
    }

    private function student(): ?Student
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->student;
    }
}
