<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya;

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Reports\SubmitReport;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Livewire\Siswa\LombaSaya\Concerns\ReportHelpers;
use App\Models\Achievement;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lomba saya (UI-MAP #12, board Lomba-Saya): list Berjalan/Selesai + detail pane with the 6-phase tracker,
 * surat tugas, tim and the laporan hasil card (inline LaporHasil on desktop, `siswa.lapor` on phones).
 * Routes: siswa.lomba-saya.index (list; desktop auto-selects the first item) and siswa.lomba-saya.show.
 * Shows registrations where the student is a member who did not decline (incl. open invitations).
 */
#[Title('Lomba saya')]
#[Layout('layouts::app', ['subtitle' => 'Pantau pendaftaran, surat tugas, dan laporan hasil.'])]
final class Index extends Component
{
    use ReportHelpers;

    public const TABS = ['berjalan', 'selesai'];

    #[Url(except: 'berjalan')]
    public string $tab = 'berjalan';

    #[Locked]
    public ?string $selectedId = null;

    /** Opened through /lomba-saya/{registration}: phones show the detail instead of the list. */
    #[Locked]
    public bool $explicit = false;

    /** Desktop: the report form is open inside the detail pane. */
    public bool $lapor = false;

    public function mount(?Registration $registration = null): void
    {
        $student = $this->student();
        abort_if($student === null, 403);

        if ($registration !== null) {
            abort_unless(Gate::allows('view', $registration) && $this->isVisibleTo($registration, $student), 404);

            $this->selectedId = (string) $registration->getKey();
            $this->explicit = true;
            $this->tab = $registration->status->isFinal() ? 'selesai' : 'berjalan';

            return;
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'berjalan';
        }

        $this->selectedId = $this->registrations()->first()?->getKey();
    }

    public function openLapor(): void
    {
        $registration = $this->selectedOrFail();

        if ($this->reportSentElsewhere($registration)) {
            return;
        }

        Gate::authorize('create', [Report::class, $registration]);

        $this->lapor = true;
    }

    public function closeLapor(): void
    {
        $this->lapor = false;
    }

    public function cancel(): void
    {
        $registration = $this->selectedOrFail();
        $user = $this->user();

        if (! Gate::allows('cancel', $registration)) {
            $this->dispatch('sth-modal-close', id: 'batal-daftar');
            $this->dispatch('sth-toast', message: 'Pendaftaran ini tidak bisa dibatalkan lagi.', tone: 'error');

            return;
        }

        try {
            app(CancelRegistration::class)->handle($user, $registration);
        } catch (ValidationException $e) {
            $this->dispatch('sth-modal-close', id: 'batal-daftar');
            $this->dispatch('sth-toast', message: (string) collect($e->errors())->flatten()->first(), tone: 'error');

            return;
        }

        unset($this->selected, $this->counts);
        $this->dispatch('sth-modal-close', id: 'batal-daftar');
        $this->dispatch('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'success');
    }

    /** @return array{berjalan: int, selesai: int} */
    #[Computed]
    public function counts(): array
    {
        $all = $this->baseQuery()->pluck('status');

        $selesai = $all->filter(fn (RegistrationStatus $s): bool => $s->isFinal())->count();

        return ['berjalan' => $all->count() - $selesai, 'selesai' => $selesai];
    }

    /** @return Collection<int, Registration> */
    #[Computed]
    public function items(): Collection
    {
        return $this->registrations();
    }

    #[Computed]
    public function selected(): ?Registration
    {
        if ($this->selectedId === null) {
            return null;
        }

        $registration = $this->baseQuery()
            ->whereKey($this->selectedId)
            ->with([
                'competition',
                'mentor.staffProfile',
                'members' => fn ($q) => $q->whereNull('declined_at')->orderByRaw("case when role = 'ketua' then 0 else 1 end")->orderBy('invited_at'),
                'members.student.user',
                'members.student.classroom',
                'letters',
                'report.files.file',
            ])
            ->first();

        return $registration !== null && Gate::allows('view', $registration) ? $registration : null;
    }

    public function render(): View
    {
        $registration = $this->selected;

        return view('livewire.siswa.lomba-saya.index', [
            'cards' => $this->items->map(fn (Registration $r): array => $this->card($r))->all(),
            'counts' => $this->counts,
            'detail' => $registration !== null ? $this->detail($registration) : null,
        ]);
    }

    // View models -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function card(Registration $registration): array
    {
        $competition = $registration->competition;
        $mine = $registration->members->first();
        [$chip, $tone] = $this->statusChip($registration, $mine);

        return [
            'id' => (string) $registration->getKey(),
            'href' => route('siswa.lomba-saya.show', $registration),
            'title' => $competition->name,
            'meta' => ($competition->bidang?->label() ?? 'Lomba').' · '.$this->typeLabel($registration, (int) $registration->getAttribute('active_members_count')),
            'bidang' => $competition->bidang,
            'status' => $registration->status,
            'chip' => $chip,
            'tone' => $tone,
            'current' => (string) $registration->getKey() === $this->selectedId,
        ];
    }

    /**
     * @return array{0: string, 1: string} chip text and tone
     */
    private function statusChip(Registration $registration, ?RegistrationMember $mine): array
    {
        $status = $registration->status;

        if ($status === RegistrationStatus::MenungguKonfirmasi && $mine !== null && ! $mine->isLeader() && $mine->confirmed_at === null) {
            return ['Konfirmasi undangan', 'orange'];
        }

        if ($status === RegistrationStatus::MenungguLaporan) {
            if ($registration->report?->status === ReportStatus::PerluRevisi) {
                return ['Perlu revisi', 'orange'];
            }

            $info = $this->deadlineInfo($registration->competition);

            return $info !== null ? [$info['chip'], $info['tone']] : [$status->label(), $status->tone()];
        }

        if ($status === RegistrationStatus::Selesai && $registration->report?->status === ReportStatus::Ditolak) {
            return ['Laporan ditolak', 'red'];
        }

        return [$status->label(), $status->tone()];
    }

    /** @return array<string, mixed> */
    private function detail(Registration $registration): array
    {
        $student = $this->student();
        $competition = $registration->competition;
        $status = $registration->status;
        $mine = $registration->members->firstWhere('student_id', $student?->getKey());
        $isLeader = $registration->leader_student_id === $student?->getKey();
        $report = $registration->report;
        // A letter exists for the student once Tata Usaha sent it (LetterPolicy::view); until then the card says
        // "sedang menyiapkan" instead of offering a file the policy would answer with 404.
        $sentLetters = $registration->letters->filter(fn (Letter $letter): bool => $letter->sent_at !== null);
        $studentLetter = $sentLetters->firstWhere('type', LetterType::Siswa);
        $guruLetter = $sentLetters->firstWhere('type', LetterType::Guru);
        $achievement = $report !== null && $student !== null
            ? Achievement::query()->where('report_id', $report->getKey())->where('student_id', $student->getKey())->first()
            : null;
        $deadline = $this->deadlineInfo($competition);
        [$chip, $tone] = $this->statusChip($registration, $mine);

        return [
            'registration' => $registration,
            'competition' => $competition,
            'status' => $status,
            'chip' => $chip,
            'tone' => $tone,
            'sub' => collect([$competition->organizer_name, $competition->weight_category?->label()])->filter()->implode(' · '),
            'typeLabel' => $this->typeLabel($registration, $registration->members->count()),
            'dateText' => self::dateRange($competition->starts_on, $competition->final_on),
            'place' => $competition->location,
            'trackerDates' => $this->trackerDates($registration, $studentLetter, $achievement, $deadline),
            'isLeader' => $isLeader,
            'isTeam' => $registration->type === RegistrationType::Tim,
            'pendingInvite' => $mine !== null && ! $mine->isLeader() && $mine->confirmed_at === null
                && $status === RegistrationStatus::MenungguKonfirmasi,
            'inviteUrl' => Route::has('siswa.undangan.show') ? route('siswa.undangan.show', $registration) : null,
            'canCancel' => Gate::allows('cancel', $registration),
            // B3 (#9): the ketua invites replacements in the registration panel of the lomba.
            'inviteHref' => Gate::allows('invite', $registration) && Route::has('siswa.lomba.show')
                ? route('siswa.lomba.show', $registration->competition_id).'#h-daftar'
                : null,
            'canReport' => Gate::allows('create', [Report::class, $registration]),
            'reportUrl' => route('siswa.lapor', $registration),
            'report' => $report,
            'deadline' => $deadline,
            // The sentence "ditandai terlambat" follows SubmitReport's rule (QA8).
            'marksLate' => SubmitReport::marksLate($competition, $report),
            'estimate' => $report !== null ? $this->previewBreakdown($competition, $report->claimed_result) : null,
            'achievement' => $achievement,
            'certificate' => $this->fileLink($report?->files->firstWhere('kind', ReportFileKind::Sertifikat)?->file_id),
            'letters' => $this->letterRows($studentLetter, $guruLetter, $registration),
            'lettersIssuedOn' => $studentLetter?->issued_on !== null ? self::shortDate($studentLetter->issued_on) : null,
            'members' => $registration->members->map(fn (RegistrationMember $m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'meta' => trim(($m->student?->classroom?->name ?? '').' · '.$m->role?->label(), ' ·'),
                'confirmed' => $m->confirmed_at !== null,
                'me' => $m->student_id === $student?->getKey(),
            ])->all(),
            'mentor' => $registration->mentor !== null ? $this->mentorName($registration->mentor) : null,
            'decidedOn' => self::longDate($registration->principal_decided_at),
            'registeredOn' => self::longDate($registration->created_at),
            'allConfirmed' => $registration->members->every(fn (RegistrationMember $m): bool => $m->confirmed_at !== null),
        ];
    }

    /**
     * @param  array{dueText: string}|null  $deadline
     * @return array<int, string>
     */
    private function trackerDates(Registration $registration, ?Letter $studentLetter, ?Achievement $achievement, ?array $deadline): array
    {
        $competition = $registration->competition;
        $report = $registration->report;

        return array_filter([
            1 => self::shortDate($registration->created_at),
            2 => self::shortDate($registration->principal_decided_at),
            3 => self::shortDate($studentLetter?->issued_on),
            4 => self::dateRange($competition->starts_on, $competition->final_on, false),
            5 => $report?->submitted_at !== null
                ? self::shortDate($report->submitted_at)
                : ($deadline !== null && ! $registration->status->isFinal() ? 'Batas '.self::shortDate($deadline['due']) : ''),
            6 => self::shortDate($achievement?->verified_at),
        ], fn (string $text): bool => $text !== '');
    }

    /**
     * Rows of the "Surat tugas" card (sent letters only). `info` = a row without a file for the student.
     *
     * @return list<array{title: string, meta: string, note: ?string, href: ?string, label: string, info: bool}>
     */
    private function letterRows(?Letter $studentLetter, ?Letter $guruLetter, Registration $registration): array
    {
        $rows = [];
        $count = $registration->members->count();

        if ($studentLetter !== null) {
            $rows[] = [
                'title' => 'Surat tugas siswa',
                'meta' => trim($studentLetter->full_number.' · PDF'),
                'note' => $registration->type === RegistrationType::Tim ? '1 surat untuk '.$count.' anggota' : null,
                // The link exists exactly when files.show would serve the file (FilePolicy → LetterPolicy::view).
                'href' => Gate::allows('view', $studentLetter) ? $this->fileLink($studentLetter->file_id) : null,
                'label' => 'Download surat tugas siswa (PDF)',
                'info' => false,
            ];
        }

        if ($guruLetter !== null) {
            // M2-C (Q1-01): the guru letter and its Lampiran carry the teacher's NIP and rank, so LetterPolicy
            // keeps the file from students. The student only learns that it exists and for whom: never a link.
            $rows[] = [
                'title' => 'Surat perintah tugas untuk guru pendamping sudah terbit',
                'meta' => $registration->mentor !== null
                    ? 'Untuk '.$this->mentorName($registration->mentor)
                    : 'Hanya untuk guru pendamping',
                'note' => null,
                'href' => null,
                'label' => '',
                'info' => true,
            ];
        }

        return $rows;
    }

    private function fileLink(?string $fileId): ?string
    {
        return $fileId !== null && Route::has('files.show') ? route('files.show', $fileId) : null;
    }

    private function mentorName(User $mentor): string
    {
        $honorific = $mentor->staffProfile?->honorific;

        return trim(($honorific ? $honorific.' ' : '').$mentor->name);
    }

    private function typeLabel(Registration $registration, int $members): string
    {
        return $registration->type === RegistrationType::Tim ? 'Tim '.$members.' orang' : 'Individu';
    }

    // Queries ---------------------------------------------------------------------------------

    /** Registrations of this student (member who did not decline). @return Builder<Registration> */
    private function baseQuery(): Builder
    {
        $studentId = $this->student()?->getKey();

        return Registration::query()->whereHas(
            'members',
            fn (Builder $m) => $m->where('student_id', $studentId)->whereNull('declined_at'),
        );
    }

    /** @return Collection<int, Registration> */
    private function registrations(): Collection
    {
        $studentId = $this->student()?->getKey();
        $final = array_filter(RegistrationStatus::cases(), fn (RegistrationStatus $s): bool => $s->isFinal());

        return $this->baseQuery()
            ->when(
                $this->tab === 'selesai',
                fn (Builder $q) => $q->whereIn('status', $final),
                fn (Builder $q) => $q->whereIn('status', RegistrationStatus::nonFinal()),
            )
            ->with([
                'competition',
                'report',
                'members' => fn ($q) => $q->where('student_id', $studentId),
            ])
            ->withCount(['members as active_members_count' => fn (Builder $m) => $m->whereNull('declined_at')])
            // Needs action first (report due, open invitation), then the most recently changed.
            ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [
                RegistrationStatus::MenungguLaporan->value,
                RegistrationStatus::MenungguKonfirmasi->value,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    private function isVisibleTo(Registration $registration, Student $student): bool
    {
        return $registration->members()->where('student_id', $student->getKey())->whereNull('declined_at')->exists();
    }

    /**
     * The ketua sent the report in another tab since this card was drawn (QA9; QA8 left it): ReportPolicy::create
     * refused "Laporkan hasil" with a full-screen 403. Say so and draw the card again. A teammate never had the
     * button and still gets the 403.
     */
    private function reportSentElsewhere(Registration $registration): bool
    {
        $report = $registration->report()->first();
        $leader = (string) $registration->leader_student_id === (string) $this->student()?->getKey();

        if (! $leader || $report === null || $report->status === ReportStatus::PerluRevisi) {
            return false;
        }

        $this->lapor = false;
        unset($this->selected, $this->counts);
        $this->dispatch('sth-toast', message: 'Laporan hasil sudah dikirim. Halaman sudah diperbarui.', tone: 'warning');

        return true;
    }

    private function selectedOrFail(): Registration
    {
        $registration = $this->selected;
        abort_if($registration === null, 404);

        return $registration;
    }

    private function student(): ?Student
    {
        return $this->user()->student;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
