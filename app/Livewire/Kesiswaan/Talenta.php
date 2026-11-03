<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Exports\ExportAchievements;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Actions\Users\AdminRecipients;
use App\Domain\Points\PointFormatter;
use App\Enums\RoleName;
use App\Enums\TalentTypeCode;
use App\Http\Controllers\FileController;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Student;
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
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database talenta siswa (UI-MAP #18, board Kesiswaan-Talenta). D-142 (FS1-E): talents have no stages and no
 * verification any more; Kesiswaan only records. Two tabs: "Semua" (every choice, old rows of any status as plain
 * data) and "Belum mengisi (kelas X)" (active kelas X students without a talent, D-144). Filters jenis → cabang, kelas
 * and name/NISN; the detail panel shows the student's choices, the bukti (preview popup) and the Riwayat talenta, and
 * records an optional guru pembina with the note for him (UpdateTalentAssessment, nobody is notified).
 *
 * The table shows the NISN in full (D-137). The Excel export follows the screen filters (no status dialog), never makes
 * an empty file, escapes formulas (the student's "Lainnya" text too, SECURITY §3f), needs a password from the last 15
 * minutes, is capped and audited with its filters.
 *
 * QA9: a choice the student deleted while it is open gives a toast, not a 404 page; the recorded guru pembina whose
 * account left does not block "Simpan".
 */
#[Title('Database talenta siswa')]
#[Layout('layouts::app', ['subtitle' => 'Data talenta pilihan siswa.'])]
class Talenta extends Component
{
    use RequiresRecentPassword, WithKesiswaanBadges;

    public const EXPORT_MAX_ROWS = 10000;

    public const EXPORT_HEADER = ['Nama', 'NISN', 'Kelas', 'Jenis', 'Cabang', 'Keterangan lainnya', 'Guru pembina', 'Didaftarkan'];

    /** `semua` = every choice; `belum` = active kelas X students without a talent. */
    public const TABS = ['semua', 'belum'];

    /** QA9: the student deleted the choice (Profil talenta, "Hapus") while it was open here. */
    public const CANCELLED_MESSAGE = 'Siswa sudah menghapus pilihan ini.';

    public const EXPORT_NOTHING = 'Tidak ada data talenta dengan filter ini. Tidak ada yang di-download.';

    /** FS1 fix wave: "Belum mengisi (kelas X)" has no download (the button is hidden there); a crafted call gets this. */
    public const EXPORT_WRONG_TAB = 'Download data hanya dari tab Semua.';

    public const SAVED_MESSAGE = 'Data talenta tersimpan.';

    /**
     * r3 review: a search or filter moved the panel to another row while what was typed for the open row was not saved,
     * or "Simpan" named a row the panel no longer shows (the 400 ms search, or a click on another row, in the same
     * request). Nothing is saved. Final review: worded for every cause, the list need not have changed, and the other
     * row may be another choice of the same student (the panel's own list of choices).
     */
    public const PANEL_MOVED_MESSAGE = 'Panel kini menampilkan pilihan talenta lain. Perubahan belum disimpan.';

    /** The same, when the panel closed (nothing matched any more). */
    public const PANEL_CLOSED_MESSAGE = 'Panel sudah tertutup. Perubahan belum disimpan.';

    /**
     * The same, when the panel ended on the very row "Simpan" named, with that row's stored data (only after the row the
     * panel held was deleted by the student and the first row left is that one).
     */
    public const PANEL_RELOADED_MESSAGE = 'Perubahan belum disimpan. Panel menampilkan data yang tersimpan.';

    /** id of the detail panel, which the browser event `sth-reveal` brings into view after a row button (FS1 QA). */
    public const PANEL_ID = 'panel-talenta';

    /** The search reads at most this many characters (the box keeps what was typed, term() trims). */
    public const SEARCH_MAX = 100;

    private const PAGE = 30;

    /** Upper bound of `$limit` (a public property the browser can set): beyond it the user narrows the filter. */
    private const MAX = 300;

    #[Url(as: 'tab', except: 'semua')]
    public string $tab = 'semua';

    #[Url(as: 'jenis', except: '')]
    public string $jenis = '';

    #[Url(as: 'cabang', except: '')]
    public string $cabang = '';

    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::PAGE;

    #[Locked]
    public ?string $panelId = null;

    /** Guru pembina (user id) or '' = tanpa guru pembina. */
    public string $mentorId = '';

    /** Catatan untuk guru pembina (internal). */
    public string $panelNote = '';

    /*
     * Request state of keepPanelInList() (private: Livewire keeps none of it between requests). Livewire applies the
     * updates of one request in the snapshot's property order, so the filters and the search come before mentorId and
     * panelNote: once the panel moved to another row in this request, a panel field that arrives after it was typed for
     * the row the panel left, and must not land on the new one.
     */
    private bool $panelMoved = false;

    /** The row the panel showed before its first move of this request (null = the panel was closed). */
    private ?string $leftRowId = null;

    /** @var array{mentorId: string, panelNote: string}|null what that row has stored */
    private ?array $leftRowFields = null;

    /** @var array{mentorId: string, panelNote: string} what the row the panel moved to has stored */
    private array $openedFields = ['mentorId' => '', 'panelNote' => ''];

    /** One "Perubahan belum disimpan." toast per request. */
    private bool $notSavedWarned = false;

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

        if ($property === 'mentorId' || $property === 'panelNote') {
            $this->keepFieldOnItsRow($property);
        }

        if (in_array($property, ['tab', 'jenis', 'cabang', 'kelas', 'q'], true)) {
            if ($property === 'jenis') {
                $this->cabang = '';
            }

            $this->sanitizeFilters();
            $this->limit = self::PAGE;
            $this->keepPanelInList();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'semua';
        $this->updated('tab');
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
        $this->authorize('manage', TalentApplication::class);
        $application = $this->find($id);

        if ($application !== null) {
            $this->openPanel($application);
            // FS1 QA: below xl the panel stands under the list, so on a phone the click seemed to do nothing.
            $this->dispatch('sth-reveal', id: self::PANEL_ID);
        }
    }

    /**
     * "Simpan": the guru pembina (optional) and the note for him. Nobody is notified (D-142).
     *
     * r3 review: the button names the row the panel showed ($shownId). When a search or filter of the same request (the
     * 400 ms search still on its way when "Simpan" was clicked), or a click on another row queued with it, moved the
     * panel to another row or closed it, nothing is saved: a warning toast, and the panel shows the data of the row it
     * now holds (stale-screen rule, CLAUDE.md §6).
     * Without $shownId (a page rendered before this change) the open row is saved as before.
     */
    public function savePanel(UpdateTalentAssessment $update, ?string $shownId = null): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->resetErrorBag();

        if ($shownId !== null && $shownId !== $this->panelId) {
            $this->reloadPanel();
            $this->warnNotSaved($shownId);

            return;
        }

        // r3: a search that matched nothing closed the panel; a "Simpan" sent before that render has no row to save.
        if ($this->panelId === null) {
            return;
        }

        $application = $this->find($this->panelId);

        if ($application === null) {
            return;
        }

        try {
            $update->handle($this->actor(), $application, ['mentor_id' => $this->mentorId, 'note' => $this->panelNote]);
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->dispatch('sth-toast', message: self::SAVED_MESSAGE, tone: 'success');
    }

    /**
     * Excel download of the choices that match the screen filters (jenis, cabang, kelas, search; full NISN, names):
     * password from the last 15 minutes, at most EXPORT_MAX_ROWS, never an empty file, audited with its filters,
     * Admins alerted above LargeExportNotice::LIMIT rows (SECURITY §3i, §3m-9; same gates as Database prestasi).
     */
    public function export(AdminRecipients $recipients): ?BinaryFileResponse
    {
        $this->authorize('manage', TalentApplication::class);

        // The file holds the choices of the "Semua" tab: never hand it out from a tab that lists other rows.
        if ($this->tab !== 'semua') {
            $this->dispatch('sth-toast', message: self::EXPORT_WRONG_TAB, tone: 'error');

            return null;
        }

        if (! $this->passwordRecentlyConfirmed('export')) {
            return null;
        }

        $count = $this->baseQuery()->count();

        if ($count === 0) {
            $this->dispatch('sth-toast', message: self::EXPORT_NOTHING, tone: 'error');

            return null;
        }

        if ($count > self::EXPORT_MAX_ROWS) {
            $this->dispatch('sth-toast', message: 'Terlalu banyak baris ('.number_format($count, 0, ',', '.').'). Persempit filter, maks. '
                .number_format(self::EXPORT_MAX_ROWS, 0, ',', '.').' baris.', tone: 'error');

            return null;
        }

        $actor = $this->actor();
        $exportedAt = now();
        $base = (string) tempnam(sys_get_temp_dir(), 'talenta');
        @unlink($base);
        $path = $base.'.xlsx';
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $bold = (new Style)->setFontBold();
        $describe = $this->describeFilters();

        // Marked like Database prestasi (ExportAchievements, SECURITY §3m): title, who exported it and when, filter.
        $writer->addRow(self::exportRow(['Database talenta siswa SMAGA Talent Hub'], $bold));
        $writer->addRow(self::exportRow(['Diekspor oleh '.$actor->name.' · '.$exportedAt->format('d-m-Y H.i').' WIB']));
        $writer->addRow(self::exportRow(['Filter: '.($describe === [] ? 'semua talenta' : implode('; ', $describe))]));
        $writer->addRow(self::exportRow(['']));
        $writer->addRow(self::exportRow(self::EXPORT_HEADER, $bold));

        $this->baseQuery()
            ->with(['student.user', 'student.classroom', 'talentType', 'talentBranch', 'mentor'])
            ->orderBy('created_at')
            // QA9: a unique order for the pages of 500. Rows created in the same second shared their place, so a
            // page could repeat one row and skip another.
            ->orderBy('id')
            ->chunk(500, function (Collection $chunk) use ($writer): void {
                foreach ($chunk as $a) {
                    $writer->addRow(self::exportRow([
                        (string) $a->student?->user?->name,
                        (string) $a->student?->nisn,
                        (string) $a->student?->classroom?->name,
                        (string) $a->talentType?->code?->label(),
                        (string) $a->talentBranch?->name,
                        // The student's own text (D-143): formula-escaped like every cell (exportRow()).
                        (string) $a->other_text,
                        (string) $a->mentor?->name,
                        (string) $a->created_at?->format('Y-m-d'),
                    ]));
                }
            });

        $writer->close();

        activity('talent')->causedBy($actor)->event('talent.exported')
            ->withProperties([
                'filters' => $this->activeFilters(),
                'rows' => $count,
                'large' => $count > LargeExportNotice::LIMIT,
                'format' => 'xlsx',
            ])
            ->log('talent.exported');

        if ($count > LargeExportNotice::LIMIT) {
            Notification::send($recipients->admins(), new LargeExportNotice((string) $actor->name, 'Database talenta siswa', $count, now()->translatedFormat('j F Y H:i')));
        }

        return response()->download($path, 'talenta-siswa-'.$exportedAt->format('Ymd').'.xlsx')->deleteFileAfterSend();
    }

    public function render(PointFormatter $formatter): View
    {
        $this->authorize('manage', TalentApplication::class);
        $this->clampLimit();

        $missingTab = $this->tab === 'belum';
        $counts = $this->counts();

        if ($missingTab) {
            $students = $this->missingQuery()
                ->with(['user:id,name', 'classroom:id,name'])
                ->limit($this->limit + 1)
                ->get();
            $more = $students->count() > $this->limit;
            $students = $students->take($this->limit);
            $rows = collect();
            $missing = $students->map(fn (Student $s): array => [
                'id' => (string) $s->getKey(),
                'name' => $s->user?->name ?? 'Siswa',
                'class' => $s->classroom?->name,
                'nisn' => (string) $s->nisn,
            ]);
            $shown = $students->count();
            $total = $counts['belum'];
        } else {
            $applications = $this->orderedQuery()
                ->with(['student.user', 'student.classroom', 'talentType', 'talentBranch', 'mentor'])
                ->limit($this->limit + 1)
                ->get();
            $more = $applications->count() > $this->limit;
            $applications = $applications->take($this->limit);
            $prestasi = $this->prestasi($applications->pluck('student_id')->unique()->values()->all());
            $rows = $applications->map(fn (TalentApplication $a): array => $this->row($a, $prestasi, $formatter));
            $missing = collect();
            $shown = $applications->count();
            $total = $counts['semua'];
        }

        return view('livewire.kesiswaan.talenta', [
            'counts' => $counts,
            'missingTab' => $missingTab,
            'rows' => $rows,
            'missing' => $missing,
            'hasMore' => $more && $this->limit < self::MAX,
            'capped' => $more && $this->limit >= self::MAX,
            'shownText' => $this->shownText($shown, $total, $missingTab),
            'types' => TalentType::query()->ordered()->get(),
            'branches' => $this->branchOptions(),
            'classes' => $this->classOptions(),
            'mentors' => User::query()->role(RoleName::Guru->value)->canLogIn()->orderBy('name')->pluck('name', 'id')->all(),
            'panel' => ! $missingTab && $this->panelId !== null ? $this->panel($formatter) : null,
            // One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
        ])->layoutData(['badges' => $this->kesiswaanBadges(), 'search' => false]);
    }

    /** Where Fortify sends the user back after the password confirmation (RequiresRecentPassword). */
    protected function returnUrl(): string
    {
        return route('kesiswaan.talenta.index', $this->activeFilters());
    }

    // Queries --------------------------------------------------------------------------------

    /**
     * The filters of the list that are set, by their URL key (also the audit properties). The search as term() reads
     * it, never with the spaces the box keeps.
     *
     * @return array<string, string>
     */
    private function activeFilters(): array
    {
        return array_filter([
            'jenis' => $this->jenis,
            'cabang' => $this->cabang,
            'kelas' => $this->kelas,
            'q' => $this->term(),
        ], fn (string $value): bool => $value !== '');
    }

    /** @return Builder<TalentApplication> every choice inside jenis, cabang, kelas and search */
    private function baseQuery(): Builder
    {
        $term = $this->term();

        return TalentApplication::query()
            ->whereHas('student')
            ->when($this->jenis !== '', fn (Builder $q) => $q->whereHas('talentType', fn (Builder $t) => $t->where('code', $this->jenis)))
            ->when($this->cabang !== '', fn (Builder $q) => $q->where('talent_branch_id', $this->cabang))
            ->when($this->kelas !== '', fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->where('classroom_id', $this->kelas)))
            ->when($term !== '', fn (Builder $q) => $q->whereHas('student', fn (Builder $s) => $s->search($term)));
    }

    /** @return Builder<TalentApplication> newest first (D-142: no stage order any more) */
    private function orderedQuery(): Builder
    {
        return $this->baseQuery()->orderByDesc('created_at')->orderBy('id');
    }

    /**
     * Tab "Belum mengisi (kelas X)": active kelas X students (an account that can still log in, activated or not) with
     * no talent choice (a deleted one does not count; an old "tidak lolos" row does), inside kelas and search. By class,
     * then name.
     *
     * @return Builder<Student>
     */
    private function missingQuery(): Builder
    {
        $term = $this->term();

        return Student::query()
            ->active()
            ->whereHas('classroom', fn (Builder $c) => $c->where('level', 10))
            ->whereDoesntHave('talentApplications')
            ->when($this->kelas !== '', fn (Builder $q) => $q->where('classroom_id', $this->kelas))
            ->when($term !== '', fn (Builder $q) => $q->search($term))
            ->orderBy(Classroom::query()->selectRaw('LENGTH(name)')->whereColumn('classrooms.id', 'students.classroom_id'))
            ->orderBy(Classroom::query()->select('name')->whereColumn('classrooms.id', 'students.classroom_id'))
            ->orderBy(User::query()->withTrashed()->select('name')->whereColumn('users.id', 'students.user_id'))
            ->orderBy('students.id');
    }

    /** @return array{semua: int, belum: int} the rows of each tab inside the filters that tab uses */
    private function counts(): array
    {
        return [
            'semua' => $this->baseQuery()->count(),
            'belum' => $this->missingQuery()->reorder()->count(),
        ];
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
            'nisn' => (string) $student?->nisn,
            // One label for a talent on every screen (FS1 QA): "OSN · Kimia", "OSN · Lainnya (…)", "Lainnya (…)", "OLPAIN".
            'title' => $a->title(),
            'bidang' => $a->talentType?->bidang,
            'mentorName' => $a->mentor?->name,
            'hasEvidence' => $a->evidence_file_id !== null,
            'points' => $p !== null ? $formatter->format($p['points']) : null,
            'prestasi' => $p['count'] ?? 0,
            'selected' => $a->getKey() === $this->panelId,
        ];
    }

    /** @return array<string, mixed>|null */
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
            'meta' => trim(($student->classroom?->name ?? '').' · NISN '.$student->nisn, ' ·'),
            'points' => $sum !== null && $sum->n > 0 ? $formatter->format((string) $sum->total) : null,
            'prestasi' => (int) ($sum->n ?? 0),
            'choices' => $others->map(fn (TalentApplication $o): array => [
                'id' => $o->getKey(),
                'label' => $o->title(),
                'current' => $o->getKey() === $a->getKey(),
            ])->all(),
            'evidence' => $a->evidenceFile !== null ? [
                'name' => $a->evidenceFile->original_name,
                'size' => self::fileSize($a->evidenceFile->size),
                'url' => Route::has('files.show') ? route('files.show', $a->evidenceFile) : null,
                // M3-C: the click opens the preview popup; the href stays the download.
                'preview' => FileController::previewUrl($a->evidenceFile),
                'previewType' => FileController::previewType($a->evidenceFile),
            ] : null,
            'minat' => $profile !== null && is_array($profile->interests) && $profile->interests !== [] ? implode(', ', $profile->interests) : null,
            'organisasi' => $profile?->organizations,
            'smp' => $profile?->junior_high_achievements,
            // QA9: the recorded guru pembina whose account left is not in the select; say so under it.
            'mentorHint' => $a->mentor_id !== null && ! UpdateTalentAssessment::isActiveGuru($a->mentor_id)
                ? 'Guru pembina yang tercatat'.($a->mentor?->name !== null ? ', '.$a->mentor->name.',' : '').' tidak aktif lagi. Pilih guru pembina lain.'
                : null,
        ];
    }

    // Helpers --------------------------------------------------------------------------------

    private function openPanel(TalentApplication $application): void
    {
        $this->panelId = $application->getKey();
        ['mentorId' => $this->mentorId, 'panelNote' => $this->panelNote] = self::storedFields($application);
        $this->resetErrorBag(['mentorId', 'panelNote', 'panel']);
    }

    /** @return array{mentorId: string, panelNote: string} the panel fields as the row has them stored */
    private static function storedFields(TalentApplication $application): array
    {
        return [
            'mentorId' => $application->mentor_id !== null ? (string) $application->mentor_id : '',
            'panelNote' => (string) ($application->note ?? ''),
        ];
    }

    /** @param array{mentorId: string, panelNote: string} $stored */
    private function panelDiffersFrom(array $stored): bool
    {
        return self::fieldDiffers('mentorId', $this->mentorId, $stored['mentorId'])
            || self::fieldDiffers('panelNote', $this->panelNote, $stored['panelNote']);
    }

    /** A note that differs only in the spaces around it is not a change (UpdateTalentAssessment trims it). */
    private static function fieldDiffers(string $field, string $value, string $stored): bool
    {
        return $field === 'panelNote' ? trim($value) !== trim($stored) : $value !== $stored;
    }

    /**
     * The panel shows its row's stored data again. A row the student deleted meanwhile takes the path of every other
     * action (cancelledByStudent(): the toast, then the first row left); a row gone for good opens the first row left.
     */
    private function reloadPanel(): void
    {
        if ($this->panelId === null) {
            $this->closePanel();

            return;
        }

        $row = TalentApplication::withTrashed()->find($this->panelId);

        if ($row === null) {
            $this->openFirstOrClose();
        } elseif ($row->trashed()) {
            $this->cancelledByStudent((string) $row->getKey());
        } else {
            $this->openPanel($row);
        }
    }

    private function openFirstOrClose(): void
    {
        $first = $this->orderedQuery()->first();

        if ($first !== null) {
            $this->openPanel($first);
        } else {
            $this->closePanel();
        }
    }

    private function closePanel(): void
    {
        $this->panelId = null;
        $this->mentorId = '';
        $this->panelNote = '';
        $this->resetErrorBag(['mentorId', 'panelNote', 'panel']);
    }

    /**
     * r3 (2026-10-31): after a filter or search change the panel shows a row of the filtered list: the open one while it
     * still matches (what was typed in the panel stays), else the first match as mount() opens it, else nothing. Before,
     * the panel kept a student the list no longer showed and its "Simpan" still saved that row. A choice opened from the
     * panel's own list of the student's choices is a deliberate click and stays until the next filter change.
     *
     * r3 review: "matches" means one of the rows the list shows: updated() just put $limit back to the first page, so a
     * row opened after "Tampilkan lebih banyak" that still matches may stand below it, and then the first shown row
     * opens. When the panel leaves a row whose guru pembina or note was changed and not saved, a warning toast says so.
     * The tab "Belum mengisi (kelas X)" shows no panel: the panel is picked again when "Semua" comes back.
     */
    private function keepPanelInList(): void
    {
        if ($this->tab !== 'semua') {
            return;
        }

        $shown = $this->orderedQuery()->limit($this->limit)->pluck('id')->map(fn (mixed $id): string => (string) $id);

        if ($this->panelId !== null && $shown->contains($this->panelId)) {
            return;
        }

        $unsaved = $this->leaveOpenRow();
        $first = $shown->isNotEmpty() ? TalentApplication::query()->find($shown->first()) : null;

        if ($first !== null) {
            $this->openPanel($first);
        } else {
            $this->closePanel();
        }

        $this->openedFields = ['mentorId' => $this->mentorId, 'panelNote' => $this->panelNote];

        if ($unsaved && $this->panelId !== $this->leftRowId) {
            $this->warnNotSaved($this->leftRowId);
        }
    }

    /**
     * The first move of the panel in this request remembers the row the screen showed and what it has stored.
     *
     * @return bool whether the panel fields held a change that was not saved for that row
     */
    private function leaveOpenRow(): bool
    {
        if ($this->panelMoved) {
            return false;
        }

        $this->panelMoved = true;
        $this->leftRowId = $this->panelId;

        if ($this->panelId === null) {
            return false;
        }

        $row = TalentApplication::withTrashed()->find($this->panelId);
        $this->leftRowFields = $row !== null ? self::storedFields($row) : ['mentorId' => '', 'panelNote' => ''];

        return $this->panelDiffersFrom($this->leftRowFields);
    }

    /**
     * r3 review: a guru pembina or note that arrives after the panel moved in the same request was typed for the row the
     * panel left. It does not land on the new row (its "Simpan" would save it there): the field shows that row's stored
     * value again, and a toast says the change was not saved.
     */
    private function keepFieldOnItsRow(string $field): void
    {
        if (! $this->panelMoved || $this->panelId === $this->leftRowId) {
            return;
        }

        $typed = $field === 'mentorId' ? $this->mentorId : $this->panelNote;

        if ($field === 'mentorId') {
            $this->mentorId = $this->openedFields['mentorId'];
        } else {
            $this->panelNote = $this->openedFields['panelNote'];
        }

        if ($this->leftRowFields !== null && self::fieldDiffers($field, $typed, $this->leftRowFields[$field])) {
            $this->warnNotSaved($this->leftRowId);
        }
    }

    /** @param  string|null  $shownRow  the row the screen showed in the panel when the change was typed or "Simpan" clicked */
    private function warnNotSaved(?string $shownRow): void
    {
        if ($this->notSavedWarned) {
            return;
        }

        $this->notSavedWarned = true;
        $this->dispatch('sth-toast', message: match (true) {
            $this->panelId === null => self::PANEL_CLOSED_MESSAGE,
            $this->panelId !== $shownRow => self::PANEL_MOVED_MESSAGE,
            default => self::PANEL_RELOADED_MESSAGE,
        }, tone: 'warning');
    }

    private function showPanelErrors(ValidationException $e): void
    {
        $map = ['mentor_id' => 'mentorId', 'note' => 'panelNote'];

        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? 'panel', (string) ($messages[0] ?? 'Periksa isian.'));
        }
    }

    /**
     * What the file holds, in words, for the export's "Filter:" row (same style as AchievementFilters::describe()):
     * jenis, cabang, kelas and search. Empty = everything.
     *
     * @return list<string>
     */
    private function describeFilters(): array
    {
        $jenis = TalentTypeCode::tryFrom($this->jenis);
        $cabang = $this->cabang !== '' ? TalentBranch::query()->whereKey($this->cabang)->value('name') : null;
        $kelas = $this->kelas !== '' ? Classroom::query()->whereKey($this->kelas)->value('name') : null;

        return array_values(array_filter([
            $jenis !== null ? 'Jenis: '.$jenis->label() : null,
            $cabang !== null ? 'Cabang: '.$cabang : null,
            $kelas !== null ? 'Kelas: '.$kelas : null,
            $this->term() !== '' ? 'Siswa: '.$this->term() : null,
        ]));
    }

    /**
     * One export row of explicit text cells (never a formula), each value guarded like Database prestasi.
     *
     * @param  list<string>  $values
     */
    private static function exportRow(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (string $value): StringCell => new StringCell(ExportAchievements::cell($value), $style), $values));
    }

    /** QA9: every row that matches, not only the ones on screen (the list comes in steps of PAGE). */
    private function shownText(int $shown, int $total, bool $missingTab): string
    {
        $filtered = $this->kelas !== '' || $this->term() !== '' || (! $missingTab && ($this->jenis !== '' || $this->cabang !== ''));

        if (! $filtered) {
            return 'Menampilkan '.$shown.' dari '.$total.($missingTab ? ' siswa' : ' pilihan talenta');
        }

        return $shown < $total ? 'Menampilkan '.$shown.' dari '.$total.' hasil sesuai filter' : $total.' hasil sesuai filter';
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

    /**
     * The Kelas filter, grouped by level. "Belum mengisi (kelas X)" lists kelas X students only, so that tab offers only
     * the kelas X classes: another class could never match there (FS1 QA round 2, the rule of BUG-04).
     *
     * @return array<string, array<string, string>>
     */
    private function classOptions(): array
    {
        return Classroom::query()->active()->ordered()
            ->when($this->tab === 'belum', fn (Builder $q) => $q->where('level', 10))
            ->get()
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

    /** The search as the list, the export, its "Filter:" row and its audit row read it: trimmed, at most SEARCH_MAX. */
    private function term(): string
    {
        return mb_substr(trim($this->q), 0, self::SEARCH_MAX);
    }

    private function sanitizeFilters(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'semua';
        $this->jenis = TalentTypeCode::tryFrom($this->jenis) !== null ? $this->jenis : '';

        // The search box keeps the text as typed (SV1, backlog #81): a trimmed value written back here would land in the
        // box while Kesiswaan is still typing and eat the space before the next word ("Hana " + pause + "Safira" became
        // "HanaSafira"). term() trims it for the list, the export and its audit row; here it is only cut to what the
        // search reads.
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }

        if ($this->cabang !== '' && ! TalentBranch::query()->whereKey($this->cabang)->exists()) {
            $this->cabang = '';
        }

        // A class the open tab does not offer is dropped: on "Belum mengisi (kelas X)" an XI or XII class would only show
        // an empty list that reads as "everyone chose" (FS1 QA round 2).
        if ($this->kelas !== '' && ! Classroom::query()->whereKey($this->kelas)
            ->when($this->tab === 'belum', fn (Builder $q) => $q->where('level', 10))->exists()) {
            $this->kelas = '';
        }
    }

    /**
     * The row an action is about. QA9: a choice the student deleted meanwhile (Profil talenta "Hapus", a soft delete)
     * gets a warning toast and a fresh panel instead of a 404 page; null tells the action to stop. An id that never
     * existed is still a 404.
     */
    private function find(string $id): ?TalentApplication
    {
        $application = TalentApplication::withTrashed()->find($id);

        abort_if($application === null, 404);

        if (! $application->trashed()) {
            return $application;
        }

        $this->cancelledByStudent((string) $application->getKey());

        return null;
    }

    /** QA9: the panel lets go of a row the student deleted and shows the first row left. */
    private function cancelledByStudent(string $id): void
    {
        $this->dispatch('sth-toast', message: self::CANCELLED_MESSAGE, tone: 'warning');

        if ($this->panelId !== $id) {
            return;
        }

        $this->openFirstOrClose();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
