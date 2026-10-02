<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Actions\Users\AdminRecipients;
use App\Domain\Points\PointFormatter;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use App\Notifications\Admin\LargeExportNotice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Talenta siswa (UI-MAP #18, board Kesiswaan-Talenta): pipeline tabs, filters (jenis → cabang, kelas,
 * nama/NIS), table with bulk moves, detail panel (pembina, jadwal asesmen, catatan). Every change goes
 * through MoveTalentStage / RejectTalentApplication / UpdateTalentAssessment (one call per row for bulk
 * moves), which notify the student. NIS is masked in the table; the Excel export escapes formulas, needs
 * a password from the last 15 minutes, is capped and audited with its filters.
 */
#[Title('Talenta siswa')]
#[Layout('layouts::app', ['subtitle' => 'Penjaringan, pemetaan, dan seleksi peserta pembinaan.'])]
class Talenta extends Component
{
    use RequiresRecentPassword, WithKesiswaanBadges;

    public const EXPORT_MAX_ROWS = 10000;

    private const PAGE = 30;

    /** Upper bound of `$limit` (a public property the browser can set): beyond it the user narrows the filter. */
    private const MAX = 300;

    private const TABS = ['semua', 'terdaftar', 'pemetaan', 'seleksi', 'pembinaan', 'tidak_lolos'];

    #[Url(as: 'tahap', except: 'semua')]
    public string $tahap = 'semua';

    #[Url(as: 'jenis', except: '')]
    public string $jenis = '';

    #[Url(as: 'cabang', except: '')]
    public string $cabang = '';

    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

    /** Checked row ids. @var list<string> */
    public array $checked = [];

    public bool $bulkRejecting = false;

    public string $bulkNote = '';

    #[Locked]
    public ?string $panelId = null;

    public string $mentorId = '';

    public string $assessmentOn = '';

    public string $panelNote = '';

    /** "Dipindahkan dari …" line under the panel actions. */
    #[Locked]
    public ?string $panelDone = null;

    public function mount(): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->sanitizeFilters();

        $first = $this->orderedQuery()->first();

        if ($first !== null) {
            $this->openPanel($first);
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'limit') {
            $this->clampLimit();
        }

        if (in_array($property, ['tahap', 'jenis', 'cabang', 'kelas', 'q'], true)) {
            if ($property === 'jenis') {
                $this->cabang = '';
            }

            $this->sanitizeFilters();
            $this->limit = self::PAGE;
            $this->checked = [];
            $this->bulkRejecting = false;
        }
    }

    public function setTahap(string $tahap): void
    {
        $this->tahap = in_array($tahap, self::TABS, true) ? $tahap : 'semua';
        $this->updated('tahap');
    }

    public function setJenis(string $jenis): void
    {
        $this->jenis = TalentTypeCode::tryFrom($jenis) !== null ? $jenis : '';
        $this->updated('jenis');
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE;
        $this->clampLimit();
    }

    public function showPanel(string $id): void
    {
        $this->panelDone = null;
        $this->openPanel($this->find($id));
    }

    /** "Lolos ke …" from the panel (saves pembina/date/note in the same step). */
    public function advance(MoveTalentStage $move): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);
        $from = $application->status;
        $next = $from->next();

        if ($next === null) {
            return;
        }

        try {
            $move->handle($this->actor(), $application, $next, $this->panelDetails());
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->panelDone = 'Dipindahkan dari '.$from->label().' ke '.$next->label().'. Notifikasi terkirim.';
        $this->openPanel($application->refresh(), keepDone: true);
    }

    /** Row check icon: move one row to its next stage. */
    public function advanceRow(string $id, MoveTalentStage $move): void
    {
        $application = $this->find($id);
        $next = $application->status->next();

        if ($next === null) {
            return;
        }

        try {
            $move->handle($this->actor(), $application, $next);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: collect($e->errors())->flatten()->first() ?? 'Tahap tidak bisa diubah.', tone: 'error');

            return;
        }

        $this->dispatch('sth-toast', message: 'Dipindahkan ke '.$next->label().'.', tone: 'success');

        if ($this->panelId === $id) {
            $this->openPanel($application->refresh());
        }
    }

    public function rejectPanel(RejectTalentApplication $reject): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);
        $from = $application->status;

        try {
            $reject->handle($this->actor(), $application, $this->panelNote);
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->panelDone = 'Tidak lolos dari tahap '.$from->label().'. Catatan terkirim ke siswa.';
        $this->openPanel($application->refresh(), keepDone: true);
    }

    public function savePanel(UpdateTalentAssessment $update): void
    {
        $this->resetErrorBag();

        try {
            $update->handle($this->actor(), $this->find((string) $this->panelId), $this->panelDetails());
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->dispatch('sth-toast', message: 'Data asesmen tersimpan.', tone: 'success');
    }

    public function bulkAdvance(MoveTalentStage $move): void
    {
        $moved = 0;
        $skipped = 0;

        foreach ($this->checkedApplications() as $application) {
            $next = $application->status->next();

            if ($next === null) {
                $skipped++;

                continue;
            }

            try {
                $move->handle($this->actor(), $application, $next);
                $moved++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->afterBulk($moved.' pilihan talenta dipindahkan ke tahap berikutnya.', $skipped);
    }

    /** Header checkbox: select every visible row, or clear when all are selected. */
    public function toggleAll(): void
    {
        $visible = $this->orderedQuery()->limit($this->limit)->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $this->checked = array_diff($visible, $this->checked) === [] ? [] : $visible;
        $this->bulkRejecting = false;
    }

    public function startBulkReject(): void
    {
        $this->bulkRejecting = $this->checked !== [];
        $this->bulkNote = '';
        $this->resetErrorBag('bulkNote');
    }

    public function cancelBulk(): void
    {
        $this->bulkRejecting = false;
        $this->bulkNote = '';
        $this->checked = [];
        $this->resetErrorBag('bulkNote');
    }

    public function bulkReject(RejectTalentApplication $reject): void
    {
        $this->resetErrorBag();

        if (trim($this->bulkNote) === '') {
            $this->addError('bulkNote', 'Catatan wajib diisi.');

            return;
        }

        $done = 0;
        $skipped = 0;

        foreach ($this->checkedApplications() as $application) {
            try {
                $reject->handle($this->actor(), $application, $this->bulkNote);
                $done++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->afterBulk($done.' pilihan talenta ditandai tidak lolos.', $skipped);
    }

    /**
     * Excel download of the filtered list (full NIS, names, notes): password from the last 15 minutes, at
     * most EXPORT_MAX_ROWS, audited with its filters, Admins alerted above LargeExportNotice::LIMIT rows
     * (SECURITY §3i, §3m-9; same gates as Database prestasi).
     */
    public function export(AdminRecipients $recipients): ?BinaryFileResponse
    {
        $this->authorize('manage', TalentApplication::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return null;
        }

        $count = $this->filteredQuery()->count();

        if ($count > self::EXPORT_MAX_ROWS) {
            $this->dispatch('sth-toast', message: 'Terlalu banyak baris ('.number_format($count, 0, ',', '.').'). Persempit filter, maks. '
                .number_format(self::EXPORT_MAX_ROWS, 0, ',', '.').' baris.', tone: 'error');

            return null;
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'talenta');
        @unlink($base);
        $path = $base.'.xlsx';
        $writer = SimpleExcelWriter::create($path);

        $this->filteredQuery()->with(['student.user', 'student.classroom', 'talentType', 'talentBranch', 'mentor'])
            ->orderBy('created_at')
            ->chunk(500, function (Collection $chunk) use ($writer): void {
                foreach ($chunk as $a) {
                    $writer->addRow([
                        'Nama' => self::cell($a->student?->user?->name),
                        'NIS' => self::cell($a->student?->nis),
                        'Kelas' => self::cell($a->student?->classroom?->name),
                        'Jenis' => self::cell($a->talentType?->code?->label()),
                        'Cabang' => self::cell($a->talentBranch?->name),
                        'Tahap' => self::cell($a->status->label()),
                        'Guru pembina' => self::cell($a->mentor?->name),
                        'Jadwal asesmen' => $a->assessment_on?->format('Y-m-d') ?? '',
                        'Catatan' => self::cell($a->note),
                        'Didaftarkan' => $a->created_at?->format('Y-m-d') ?? '',
                    ]);
                }
            });

        $writer->close();

        $actor = $this->actor();

        activity('talent')->causedBy($actor)->event('talent.exported')
            ->withProperties([
                'filters' => $this->activeFilters(),
                'rows' => $count,
                'large' => $count > LargeExportNotice::LIMIT,
                'format' => 'xlsx',
            ])
            ->log('talent.exported');

        if ($count > LargeExportNotice::LIMIT) {
            Notification::send($recipients->admins(), new LargeExportNotice((string) $actor->name, 'data talenta siswa', $count, now()->translatedFormat('j F Y H:i')));
        }

        return response()->download($path, 'talenta-siswa-'.now()->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }

    public function render(PointFormatter $formatter): View
    {
        $this->clampLimit();

        $rows = $this->orderedQuery()
            ->with(['student.user', 'student.classroom', 'talentType', 'talentBranch'])
            ->limit($this->limit + 1)
            ->get();
        $more = $rows->count() > $this->limit;
        $rows = $rows->take($this->limit);
        $prestasi = $this->prestasi($rows->pluck('student_id')->unique()->values()->all());

        return view('livewire.kesiswaan.talenta', [
            'counts' => $this->counts(),
            'rows' => $rows->map(fn (TalentApplication $a): array => $this->row($a, $prestasi, $formatter)),
            'hasMore' => $more && $this->limit < self::MAX,
            'capped' => $more && $this->limit >= self::MAX,
            'shownText' => $this->shownText($rows->count()),
            'types' => TalentType::query()->ordered()->get(),
            'branches' => $this->branchOptions(),
            'classes' => $this->classOptions(),
            'mentors' => User::query()->role(RoleName::Guru->value)->canLogIn()->orderBy('name')->pluck('name', 'id')->all(),
            'panel' => $this->panelId !== null ? $this->panel($formatter) : null,
            'bulkNext' => $this->bulkNextLabel(),
        ])->layoutData(['badges' => $this->kesiswaanBadges()]);
    }

    /** Where Fortify sends the user back after the password confirmation (RequiresRecentPassword). */
    protected function returnUrl(): string
    {
        return route('kesiswaan.talenta.index', $this->activeFilters());
    }

    // Queries --------------------------------------------------------------------------------

    /** @return array<string, string> the filters that are set, by their URL key (also the audit properties) */
    private function activeFilters(): array
    {
        return array_filter([
            'tahap' => $this->tahap === 'semua' ? '' : $this->tahap,
            'jenis' => $this->jenis,
            'cabang' => $this->cabang,
            'kelas' => $this->kelas,
            'q' => $this->q,
        ], fn (string $value): bool => $value !== '');
    }

    /** @return Builder<TalentApplication> */
    private function baseQuery(): Builder
    {
        return TalentApplication::query()
            ->whereHas('student')
            ->when($this->jenis !== '', fn (Builder $q) => $q->whereHas('talentType', fn (Builder $t) => $t->where('code', $this->jenis)))
            ->when($this->cabang !== '', fn (Builder $q) => $q->where('talent_branch_id', $this->cabang))
            ->when($this->kelas !== '', fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('classroom_id', $this->kelas)))
            ->when($this->q !== '', fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->search($this->q)));
    }

    /** @return Builder<TalentApplication> */
    private function filteredQuery(): Builder
    {
        return $this->baseQuery()->when($this->tahap !== 'semua', fn (Builder $q) => $q->where('status', $this->tahap));
    }

    /** @return Builder<TalentApplication> */
    private function orderedQuery(): Builder
    {
        return $this->filteredQuery()
            ->orderByRaw('case status when ? then 0 when ? then 1 when ? then 2 when ? then 3 else 4 end', ['terdaftar', 'pemetaan', 'seleksi', 'pembinaan'])
            ->orderByDesc('created_at')
            ->orderBy('id');
    }

    /** @return array<string, int> status => count (within jenis/cabang/kelas/q), plus 'semua' */
    private function counts(): array
    {
        $counts = $this->baseQuery()->toBase()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $all = array_fill_keys(array_map(fn (TalentStatus $s): string => $s->value, TalentStatus::cases()), 0);
        $all = array_merge($all, $counts);
        $all['semua'] = array_sum($all);

        return $all;
    }

    /**
     * @param  list<string>  $studentIds
     * @return array<string, array{points: string, count: int}>
     */
    private function prestasi(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return Achievement::query()
            ->whereIn('student_id', $studentIds)
            ->groupBy('student_id')
            ->selectRaw('student_id, sum(points) as total, count(*) as n')
            ->get()
            ->mapWithKeys(fn ($r): array => [(string) $r->student_id => ['points' => (string) $r->total, 'count' => (int) $r->n]])
            ->all();
    }

    /**
     * @param  array<string, array{points: string, count: int}>  $prestasi
     * @return array<string, mixed>
     */
    private function row(TalentApplication $a, array $prestasi, PointFormatter $formatter): array
    {
        $student = $a->student;
        $p = $prestasi[(string) $a->student_id] ?? null;

        return [
            'id' => $a->getKey(),
            'name' => $student?->user?->name ?? 'Siswa',
            'class' => $student?->classroom?->name,
            'nis' => self::maskNis($student?->nis),
            'type' => $a->talentType?->code?->label() ?? $a->talentType?->name,
            'bidang' => $a->talentType?->bidang,
            'branch' => $a->talentBranch?->name ?? 'Tanpa cabang',
            'hasEvidence' => $a->evidence_file_id !== null,
            'points' => $p !== null ? $formatter->format($p['points']) : null,
            'prestasi' => $p['count'] ?? 0,
            'status' => $a->status,
            'next' => $a->status->next(),
            'selected' => $a->getKey() === $this->panelId,
        ];
    }

    /** @return array<string, mixed> */
    private function panel(PointFormatter $formatter): ?array
    {
        $a = TalentApplication::query()
            ->with(['student.user', 'student.classroom', 'student.talentProfile', 'talentType', 'talentBranch', 'evidenceFile', 'mentor'])
            ->find($this->panelId);

        if ($a === null || $a->student === null) {
            return null;
        }

        $student = $a->student;
        $others = TalentApplication::query()->where('student_id', $student->getKey())->with(['talentType', 'talentBranch'])->orderBy('created_at')->get();
        $sum = Achievement::query()->where('student_id', $student->getKey())->selectRaw('sum(points) as total, count(*) as n')->first();
        $profile = $student->talentProfile;

        return [
            'id' => $a->getKey(),
            'name' => $student->user?->name ?? 'Siswa',
            'meta' => trim(($student->classroom?->name ?? '').' · NIS '.$student->nis, ' ·'),
            'points' => $sum !== null && $sum->n > 0 ? $formatter->format((string) $sum->total) : null,
            'prestasi' => (int) ($sum->n ?? 0),
            'choices' => $others->map(fn (TalentApplication $o): array => [
                'id' => $o->getKey(),
                'label' => trim(($o->talentType?->code?->label() ?? '').' '.($o->talentBranch?->name ?? '')),
                'status' => $o->status,
                'current' => $o->getKey() === $a->getKey(),
            ])->all(),
            'evidence' => $a->evidenceFile !== null ? [
                'name' => $a->evidenceFile->original_name,
                'size' => self::fileSize($a->evidenceFile->size),
                'url' => Route::has('files.show') ? route('files.show', $a->evidenceFile) : null,
            ] : null,
            'minat' => $profile !== null && is_array($profile->interests) && $profile->interests !== [] ? implode(', ', $profile->interests) : null,
            'organisasi' => $profile?->organizations,
            'smp' => $profile?->junior_high_achievements,
            'status' => $a->status,
            'next' => $a->status->next(),
            'final' => $a->status->isFinal(),
            'mentorName' => $a->mentor?->name,
            'note' => $a->note,
        ];
    }

    // Helpers --------------------------------------------------------------------------------

    private function openPanel(TalentApplication $application, bool $keepDone = false): void
    {
        $this->panelId = $application->getKey();
        $this->mentorId = $application->mentor_id !== null ? (string) $application->mentor_id : '';
        $this->assessmentOn = $application->assessment_on?->format('Y-m-d') ?? '';
        $this->panelNote = (string) ($application->note ?? '');

        if (! $keepDone) {
            $this->panelDone = null;
        }

        $this->resetErrorBag(['mentorId', 'assessmentOn', 'panelNote', 'panel']);
    }

    /** @return array{mentor_id: string, assessment_on: string, note: string} */
    private function panelDetails(): array
    {
        return ['mentor_id' => $this->mentorId, 'assessment_on' => $this->assessmentOn, 'note' => $this->panelNote];
    }

    private function showPanelErrors(ValidationException $e): void
    {
        $map = ['mentor_id' => 'mentorId', 'assessment_on' => 'assessmentOn', 'note' => 'panelNote'];

        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? 'panel', (string) ($messages[0] ?? 'Periksa isian.'));
        }
    }

    /** @return Collection<int, TalentApplication> */
    private function checkedApplications(): Collection
    {
        $ids = array_values(array_filter($this->checked, 'is_string'));

        return $ids === [] ? collect() : TalentApplication::query()->whereKey($ids)->get();
    }

    private function afterBulk(string $message, int $skipped): void
    {
        $this->checked = [];
        $this->bulkRejecting = false;
        $this->bulkNote = '';
        $this->dispatch('sth-toast', message: $message.($skipped > 0 ? ' '.$skipped.' dilewati (tahap final).' : ''), tone: 'success');

        if ($this->panelId !== null) {
            $application = TalentApplication::query()->find($this->panelId);

            if ($application !== null) {
                $this->openPanel($application);
            }
        }
    }

    private function bulkNextLabel(): string
    {
        if ($this->checked === []) {
            return 'tahap berikutnya';
        }

        $statuses = TalentApplication::query()->whereKey(array_values(array_filter($this->checked, 'is_string')))->pluck('status')->unique();

        if ($statuses->count() === 1) {
            $status = $statuses->first();
            $status = $status instanceof TalentStatus ? $status : TalentStatus::tryFrom((string) $status);

            return $status?->next()?->label() ?? 'tahap berikutnya';
        }

        return 'tahap berikutnya';
    }

    private function shownText(int $shown): string
    {
        $filtered = $this->jenis !== '' || $this->cabang !== '' || $this->kelas !== '' || $this->q !== '';

        return $filtered
            ? $shown.' hasil sesuai filter'
            : 'Menampilkan '.$shown.' dari '.$this->filteredQuery()->count().' pilihan talenta';
    }

    /** @return array<string, string> */
    private function branchOptions(): array
    {
        $type = $this->jenis !== '' ? TalentType::query()->where('code', $this->jenis)->first() : null;

        if ($type === null || ! $type->code->requiresBranch()) {
            return [];
        }

        return TalentBranch::query()->where('talent_type_id', $type->getKey())->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    /** @return array<string, array<string, string>> */
    private function classOptions(): array
    {
        return Classroom::query()->active()->ordered()->get()
            ->groupBy(fn (Classroom $c): string => 'Kelas '.match ($c->level) {
                10 => 'X', 11 => 'XI', 12 => 'XII', default => (string) $c->level
            })
            ->map(fn (Collection $group): array => $group->pluck('name', 'id')->all())
            ->all();
    }

    private function clampLimit(): void
    {
        $this->limit = max(self::PAGE, min(self::MAX, $this->limit));
    }

    private function sanitizeFilters(): void
    {
        $this->tahap = in_array($this->tahap, self::TABS, true) ? $this->tahap : 'semua';
        $this->jenis = TalentTypeCode::tryFrom($this->jenis) !== null ? $this->jenis : '';
        $this->q = mb_substr(trim($this->q), 0, 100);

        if ($this->cabang !== '' && ! TalentBranch::query()->whereKey($this->cabang)->exists()) {
            $this->cabang = '';
        }

        if ($this->kelas !== '' && ! Classroom::query()->whereKey($this->kelas)->exists()) {
            $this->kelas = '';
        }
    }

    private static function maskNis(?string $nis): string
    {
        if ($nis === null || $nis === '') {
            return '';
        }

        return mb_strlen($nis) <= 4 ? '••••' : mb_substr($nis, 0, 4).'•••'.mb_substr($nis, -2);
    }

    /** Excel cell text; values that a spreadsheet would read as a formula get a leading apostrophe. */
    private static function cell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function find(string $id): TalentApplication
    {
        $application = TalentApplication::query()->find($id);

        abort_if($application === null, 404);

        return $application;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
