<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\NamedStudent;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Http\Controllers\FileController;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Humas · Arsip foto (D-156, lead 2026-10-16: "Humas bisa lihat arsip foto dokumentasi lomba dari para siswa dan bisa
 * mendownload foto tersebut karena nanti akan dibuatkan flyer secara manual oleh humas"). The photos students attach
 * to their laporan hasil (report files of kind `foto`, required by SubmitReport), from verified reports only, newest
 * verification first, 12 reports per page, search on lomba or student name. Each photo opens in the preview popup and
 * downloads through `files.show` (FilePolicy: Humas holds `report.verify`, D-139; every download is audited).
 *
 * Consent (D-16, D-71, SECURITY §3m-7): the photos are meant for a public flyer, so a report is listed only when every
 * member of its team may be named (NamedStudent) and allows the use of a photo (`photo_consent`); the others are only
 * counted in one sentence. Never NIS/NISN, points or contact data.
 */
#[Title('Arsip foto')]
#[Layout('layouts::app', ['subtitle' => 'Foto dokumentasi lomba dari laporan hasil yang sudah diverifikasi.'])]
class ArsipFoto extends Component
{
    use WithPagination;

    public const PER_PAGE = 12;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Publication::class);
    }

    /** The box keeps the text as typed (EM2: no trimmed write-back while typing); the query trims it. */
    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->resetPage();
    }

    /** Verified reports that hold at least one photo. @return Builder<Report> */
    public static function withPhotos(): Builder
    {
        return Report::query()
            ->where('status', ReportStatus::Diverifikasi)
            ->whereHas('registration')
            ->whereHas('files', fn (Builder $files) => $files->where('kind', ReportFileKind::Foto));
    }

    /**
     * withPhotos() whose every confirmed member may be named and allows a photo (the consent rule above).
     *
     * @return Builder<Report>
     */
    public static function usable(): Builder
    {
        return self::withPhotos()->whereDoesntHave('registration.members', fn (Builder $members) => $members
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->whereDoesntHave('student', fn (Builder $student) => NamedStudent::constrain($student)->where('photo_consent', true)));
    }

    public function render(): View
    {
        $this->authorize('viewAny', Publication::class);

        /** @var User $user */
        $user = Auth::user();

        $query = self::usable()
            ->with([
                'registration.competition',
                'registration.members' => fn ($members) => $members->whereNotNull('confirmed_at')->whereNull('declined_at'),
                'registration.members.student.user',
                'registration.members.student.classroom',
                'files' => fn ($files) => $files->where('kind', ReportFileKind::Foto)->orderBy('created_at'),
                'files.file',
            ])
            ->orderByDesc('verified_at')
            ->orderByDesc('id');

        $term = mb_substr(trim($this->q), 0, 100);

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn (Builder $q) => $q
                ->whereHas('registration.competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $like))
                ->orWhereHas('registration.members.student.user', fn (Builder $u) => $u->where('name', 'like', $like)));
        }

        $page = $query->paginate(self::PER_PAGE);

        return view('livewire.humas.arsip-foto', [
            'rows' => $page->getCollection()->map(fn (Report $report): array => self::row($report))->all(),
            'page' => $page,
            'total' => $page->total(),
            'searching' => $term !== '',
            // Reports left out for consent, whatever the search (one sentence, no names).
            'hidden' => max(0, self::withPhotos()->count() - self::usable()->count()),
        ])->layoutData(['badges' => HumasQueries::badges($user), 'search' => false]);
    }

    /** @return array{id: string, competition: string, branch: ?string, result: ?string, level: ?string, students: list<string>, verified: string, photos: list<array<string, mixed>>} */
    private static function row(Report $report): array
    {
        /** @var Registration $registration */
        $registration = $report->registration;
        $competition = $registration->competition;
        $leaderId = (string) $registration->leader_student_id;

        $students = $registration->members
            ->sortBy(fn (RegistrationMember $m): string => ((string) $m->student_id === $leaderId ? '0' : '1').mb_strtolower((string) $m->student?->user?->name))
            ->map(fn (RegistrationMember $m): string => trim((string) $m->student?->user?->name).($m->student?->classroom?->name ? ' ('.$m->student->classroom->name.')' : ''))
            ->values()
            ->all();

        return [
            'id' => (string) $report->getKey(),
            'competition' => (string) $competition?->name,
            'branch' => trim((string) $registration->branch) !== '' ? trim((string) $registration->branch) : null,
            'result' => ($report->final_result ?? $report->claimed_result)?->label(),
            'level' => $competition?->level?->label(),
            'students' => $students,
            'verified' => HumasQueries::shortDate($report->verified_at, true),
            'photos' => $report->files
                ->filter(fn (ReportFile $rf): bool => $rf->file !== null)
                ->map(fn (ReportFile $rf): array => [
                    'id' => (string) $rf->getKey(),
                    'name' => (string) $rf->file->original_name,
                    'size' => Number::fileSize((int) $rf->file->size, 0),
                    'href' => route('files.show', $rf->file),
                    'preview' => FileController::previewUrl($rf->file),
                    'previewType' => FileController::previewType($rf->file),
                ])
                ->values()
                ->all(),
        ];
    }
}
