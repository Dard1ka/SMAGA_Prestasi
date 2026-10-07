<?php

declare(strict_types=1);

namespace App\Livewire\Guru;

use App\Domain\Points\PointFormatter;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Http\Controllers\FileController;
use App\Livewire\Guru\Concerns\PresentsBinaan;
use App\Livewire\Siswa\LombaSaya\Concerns\ReportHelpers;
use App\Models\Achievement;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\ReportFile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Guru · detail of one binaan registration (`guru.binaan.show`, backlog #3, owner B4): full Jejak Prestasi
 * tracker, team, surat tugas (only after TU sent them, LetterPolicy), laporan hasil + files (ReportPolicy /
 * FilePolicy allow the guru pembimbing) and the verified points. Only the registration's own guru
 * pembimbing (RegistrationPolicy::viewAsMentor); anyone else gets 404. Read-only.
 */
#[Title('Detail binaan')]
#[Layout('layouts::app', ['subtitle' => 'Jejak prestasi siswa binaan.'])]
final class BinaanDetail extends Component
{
    use PresentsBinaan, ReportHelpers;

    #[Locked]
    public string $registrationId = '';

    public function mount(Registration $registration): void
    {
        abort_unless(Gate::allows('viewAsMentor', $registration), 404);

        $this->registrationId = (string) $registration->getKey();
    }

    public function render(PointFormatter $formatter): View
    {
        $registration = Registration::query()
            ->with([
                'competition',
                'report.files.file',
                'members' => fn ($m) => $m->orderByRaw("CASE WHEN role = 'ketua' THEN 0 ELSE 1 END")->orderBy('created_at'),
                'members.student:id,user_id,classroom_id',
                'members.student.user:id,name',
                'members.student.classroom:id,name',
                'letters.file',
            ])
            ->find($this->registrationId);

        abort_unless($registration !== null && Gate::allows('viewAsMentor', $registration), 404);

        $competition = $registration->competition;
        $report = $registration->report;
        [$chip, $tone] = $this->binaanChip($registration);
        $deadline = $competition !== null && $registration->status === RegistrationStatus::MenungguLaporan
            ? $this->deadlineInfo($competition)
            : null;
        $studentLetter = $registration->letters->firstWhere('type', LetterType::Siswa);
        $active = $registration->members->whereNull('declined_at');

        return view('livewire.guru.binaan-detail', [
            'title' => $competition?->name ?? 'Lomba',
            'backUrl' => route('guru.beranda'),
            'competition' => $competition,
            'status' => $registration->status,
            'chip' => $chip,
            'tone' => $tone,
            'deadline' => $deadline,
            // A report sent back for a revision also waits in menunggu_laporan: it was sent, not missing (QA5).
            'revising' => $report?->status === ReportStatus::PerluRevisi,
            'facts' => array_values(array_filter([
                ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => $competition?->level?->label() ?? '–'],
                ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => self::dateRange($competition?->starts_on, $competition?->final_on) ?: '–'],
                ['icon' => 'pin', 'label' => 'Tempat', 'value' => $competition?->location ?: '–'],
                ['icon' => 'users', 'label' => 'Peserta', 'value' => $this->binaanType($registration, $active->count())],
            ])),
            'organizer' => collect([$competition?->organizer_name, $competition?->weight_category?->label()])->filter()->implode(' · '),
            'trackerDates' => $this->trackerDates($registration, $studentLetter, $deadline),
            'members' => $registration->members->map(fn (RegistrationMember $m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'meta' => trim(($m->student?->classroom?->name ?? '').' · '.$m->role?->label(), ' ·'),
                'state' => match (true) {
                    $m->declined_at !== null => ['Menolak', 'red'],
                    $m->confirmed_at !== null => ['Terkonfirmasi', 'green'],
                    default => ['Menunggu konfirmasi', 'gray'],
                },
            ])->all(),
            'letters' => $this->letterRows($registration),
            'report' => $report === null ? null : [
                'status' => $report->status->label(),
                'tone' => $report->status->tone(),
                'claimed' => $report->claimed_result?->label(),
                'final' => $report->final_result?->label(),
                'submitted' => self::longDate($report->submitted_at),
                'late' => (bool) $report->late,
                'note' => in_array($report->status, [ReportStatus::PerluRevisi, ReportStatus::Ditolak], true) ? $report->note : null,
                'files' => $report->files
                    ->filter(fn (ReportFile $rf): bool => $rf->file !== null && Gate::allows('view', $rf->file))
                    ->map(fn (ReportFile $rf): array => [
                        'kind' => $rf->kind->label(),
                        'name' => (string) $rf->file?->original_name,
                        'href' => route('files.show', $rf->file_id),
                        // Preview popup (data-sth-preview); the link itself stays the download.
                        'preview' => $rf->file !== null ? FileController::previewUrl($rf->file) : null,
                        'previewType' => $rf->file !== null ? FileController::previewType($rf->file) : null,
                    ])->values()->all(),
            ],
            'achievements' => $report === null ? [] : Achievement::query()
                ->where('report_id', $report->getKey())
                ->with('student:id,user_id', 'student.user:id,name')
                ->orderByDesc('points')
                ->get()
                ->map(fn (Achievement $a): array => [
                    'name' => $a->student?->user?->name ?? 'Siswa',
                    'result' => $a->result->label(),
                    'points' => $formatter->format((string) $a->points),
                    'rumus' => $formatter->format((string) $a->w_level, 2).' × '.$formatter->formatWeight((string) $a->w_result)
                        .' × '.$formatter->formatWeight((string) $a->w_organizer),
                ])->all(),
        ]);
    }

    /**
     * Tracker dates per phase (same texts as the student's Lomba saya).
     *
     * @param  array{due: CarbonImmutable}|null  $deadline
     * @return array<int, string>
     */
    private function trackerDates(Registration $registration, ?Letter $studentLetter, ?array $deadline): array
    {
        $competition = $registration->competition;
        $report = $registration->report;

        return array_filter([
            1 => self::shortDate($registration->created_at),
            2 => self::shortDate($registration->principal_decided_at),
            3 => self::shortDate($studentLetter?->issued_on),
            4 => self::dateRange($competition?->starts_on, $competition?->final_on, false),
            5 => $report?->submitted_at !== null
                ? self::shortDate($report->submitted_at)
                : ($deadline !== null ? 'Batas '.self::shortDate($deadline['due']) : ''),
            6 => self::shortDate($report?->verified_at),
        ], fn (string $text): bool => $text !== '');
    }

    /**
     * Letters the guru may open (LetterPolicy::view: the mentor sees both letters only after TU sent them).
     * "Buka" shows the PDF in the preview popup; the link itself stays the download (QA5, D-105's open point).
     *
     * @return list<array{title: string, meta: string, href: string, name: string, preview: ?string, previewType: ?string}>
     */
    private function letterRows(Registration $registration): array
    {
        return $registration->letters
            ->filter(fn (Letter $letter): bool => $letter->file_id !== null && Gate::allows('view', $letter))
            ->sortBy(fn (Letter $letter): int => $letter->type === LetterType::Guru ? 0 : 1)
            ->map(fn (Letter $letter): array => [
                'title' => $letter->type === LetterType::Guru ? 'Surat perintah tugas guru' : 'Surat tugas siswa',
                'meta' => trim($letter->full_number.' · '.self::longDate($letter->issued_on), ' ·'),
                'href' => route('files.show', $letter->file_id),
                'name' => (string) ($letter->file?->original_name ?? 'surat-tugas.pdf'),
                'preview' => $letter->file !== null ? FileController::previewUrl($letter->file) : null,
                'previewType' => $letter->file !== null ? FileController::previewType($letter->file) : null,
            ])->values()->all();
    }
}
