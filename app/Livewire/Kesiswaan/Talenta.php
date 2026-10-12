<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Actions\Exports\ExportAchievements;
use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\RestoreTalentApplication;
use App\Actions\Talent\TalentNote;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Actions\Users\AdminRecipients;
use App\Domain\Points\PointFormatter;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Http\Controllers\FileController;
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
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database talenta siswa (UI-MAP #18, board Kesiswaan-Talenta): pipeline tabs, filters (jenis → cabang, kelas,
 * nama/NIS), table with bulk moves, detail panel (pembina, jadwal asesmen, two notes). Every change goes
 * through MoveTalentStage / RejectTalentApplication / UpdateTalentAssessment (one call per row for bulk
 * moves), which notify the student. The table shows the NIS in full (D-131); the Excel export escapes formulas, needs
 * a password from the last 15 minutes, is capped and audited with its filters.
 *
 * Notes (lead, 2026-10-04), both optional: "Catatan untuk guru pembina" (`panelNote`, internal, saved by every
 * panel button) and "Catatan untuk siswa" (`panelStudentNote`, sent with "Lolos ke …" / "Tidak lolos" only).
 * A "tidak lolos" row has "Pulihkan" (recycle icon → confirm dialog → RestoreTalentApplication); rows whose
 * student sent "Minta ditinjau ulang" carry a chip and come first inside their stage.
 *
 * M2-B (QA 2026-10-05): "Simpan" keeps a typed "Catatan untuk siswa" as a draft on the row
 * (`student_note_draft`, shown again after a reload, never shown or sent to the student) and says that it is
 * sent with the next decision (Q2-14). "Tidak lolos", in the panel and in the bulk bar, goes through the
 * confirm dialog REJECT_DIALOG like "Pulihkan", because it notifies the student at once (Q2-15).
 *
 * M3-A (lead, 2026-10-06): "Pulihkan" asks where the choice goes back to (`restoreTo`: Terdaftar, Pemetaan,
 * Seleksi or Peserta pembinaan; the stage before the rejection is preselected and marked "Tahap sebelumnya").
 * "Download data (Excel)" opens EXPORT_DIALOG first: Kesiswaan ticks the statuses that go into the file
 * (`exportStatuses`, preselected from the open tab), `download()` turns them into the literal argument of
 * `export()`, so the automatic download after the password page (RequiresRecentPassword) carries the same
 * choice. The tab "Semua" lists every status, "Tidak lolos" included, each row with its chip.
 *
 * QA9 (2026-10-09): a move starts only from the stage the screen showed (`$shownStages`, so a stale second tab never
 * moves a row twice); a choice the student cancelled while it is open gives a toast, not a 404 page; the recorded guru
 * pembina whose account left does not block the panel; "Siswa diberi tahu" only when the Action told the student.
 *
 * D-128 (lead, 2026-10-10): the bulk "Pindahkan ke …" asks first (ADVANCE_DIALOG): it tells every checked student at
 * once, sends the saved draft notes, and a stage cannot be moved back. askBulkAdvance() fixes which rows move and from
 * which stage (`$advanceAsk`); bulkAdvance() is the confirm step and does nothing without that question.
 */
#[Title('Database talenta siswa')]
#[Layout('layouts::app', ['subtitle' => 'Data talenta pilihan siswa.'])]
class Talenta extends Component
{
    use RequiresRecentPassword, WithKesiswaanBadges;

    public const EXPORT_MAX_ROWS = 10000;

    private const EXPORT_HEADER = [
        'Nama', 'NIS', 'Kelas', 'Jenis', 'Cabang', 'Tahap', 'Guru pembina', 'Jadwal asesmen',
        'Catatan untuk guru pembina', 'Catatan untuk siswa', 'Didaftarkan',
    ];

    private const PAGE = 30;

    /** Upper bound of `$limit` (a public property the browser can set): beyond it the user narrows the filter. */
    private const MAX = 300;

    private const TABS = ['semua', 'terdaftar', 'pemetaan', 'seleksi', 'pembinaan', 'tidak_lolos'];

    /** id of the "Pulihkan" confirm dialog (<x-sth.modal>). */
    public const RESTORE_DIALOG = 'pulihkan-talenta';

    /** id of the "Tidak lolos" confirm dialog (panel and bulk bar). */
    public const REJECT_DIALOG = 'tidak-lolos-talenta';

    public const REJECT_PANEL = 'panel';

    public const REJECT_BULK = 'bulk';

    /** id of the bulk "Pindahkan ke …" confirm dialog (D-128). */
    public const ADVANCE_DIALOG = 'pindahkan-talenta';

    /** id of the "Download data (Excel)" dialog: which statuses go into the file. */
    public const EXPORT_DIALOG = 'download-talenta';

    /** `export('semua')`: every status, whatever tab is open. */
    public const EXPORT_ALL = 'semua';

    public const EXPORT_EMPTY_CHOICE = 'Status yang dipilih belum punya data. Pilih status lain atau ubah filter.';

    public const EXPORT_NOTHING = 'Tidak ada pilihan talenta dengan status dan filter ini. Tidak ada yang di-download.';

    public const SAVED_WITH_DRAFT = 'Tersimpan. Catatan untuk siswa terkirim saat Lolos atau Tidak lolos ditekan.';

    /** QA9: the student cancelled the choice (Profil talenta, "Batalkan") while it was open here. */
    public const CANCELLED_MESSAGE = 'Siswa sudah membatalkan pilihan ini.';

    /** QA9: a decision on a student who cannot log in (alumnus, disabled account) is not sent to anyone. */
    public const NO_NOTICE = 'Akun siswa tidak aktif, jadi tidak ada notifikasi.';

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

    /** What the open "Tidak lolos" dialog will reject: '' (nothing), the panel row or the checked rows. */
    #[Locked]
    public string $rejectTarget = '';

    /** Note for the students of a bulk rejection (typed in the dialog). */
    public string $bulkNote = '';

    /**
     * What the open "Pindahkan" dialog asks about, null while nothing is asked (D-128). `stages` = the rows that move,
     * id => the stage the screen showed for each; `final`, `moved`, `rejected` = checked rows left out when it was
     * asked (already final; moved on or marked tidak lolos by a colleague since the screen was drawn). Set only by
     * askBulkAdvance(); bulkAdvance() moves nothing else.
     *
     * @var array{stages: array<string, string>, final: int, moved: int, rejected: int}|null
     */
    #[Locked]
    public ?array $advanceAsk = null;

    #[Locked]
    public ?string $panelId = null;

    public string $mentorId = '';

    public string $assessmentOn = '';

    /** Catatan untuk guru pembina (internal). */
    public string $panelNote = '';

    /**
     * Catatan untuk siswa: sent with the next "Lolos ke …" / "Tidak lolos". "Simpan" keeps it as a draft on
     * the row (never as the sent `student_note`), so it is here again after a reload.
     */
    public string $panelStudentNote = '';

    /** "Dipindahkan dari …" line under the panel actions. */
    #[Locked]
    public ?string $panelDone = null;

    /** Row waiting in the "Pulihkan" confirm dialog. */
    #[Locked]
    public ?string $restoreId = null;

    public string $restoreNote = '';

    /** Stage ticked in the "Pulihkan" dialog; restore() accepts only RestoreTalentApplication::TARGETS. */
    public string $restoreTo = '';

    /** Guru pembina in the "Pulihkan" dialog (user id or ''); required for Peserta pembinaan (D-109). */
    public string $restoreMentor = '';

    /**
     * Statuses ticked in the "Download data" dialog; download() keeps only real TalentStatus values.
     *
     * @var list<string>
     */
    public array $exportStatuses = [];

    /**
     * The stage of every row as this screen last drew it (the listed rows and the panel row), id => TalentStatus
     * value; render() writes it. QA9: a move goes ahead only from the stage the screen showed, so a second tab that
     * still shows "Terdaftar" cannot push a row another tab already moved to Pemetaan on to Seleksi (and tell the
     * student twice). Locked: the browser cannot claim another stage.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $shownStages = [];

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
        }

        // The rows a bulk "Tidak lolos" dialog was opened for changed under it: it has to be asked again.
        if ($this->rejectTarget === self::REJECT_BULK
            && (in_array($property, ['tahap', 'jenis', 'cabang', 'kelas', 'q'], true) || str_starts_with($property, 'checked'))) {
            $this->rejectTarget = '';
        }

        // The same for a bulk "Pindahkan" question (D-128).
        if ($this->advanceAsk !== null
            && (in_array($property, ['tahap', 'jenis', 'cabang', 'kelas', 'q'], true) || str_starts_with($property, 'checked'))) {
            $this->advanceAsk = null;
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
        $application = $this->find($id);

        if ($application !== null) {
            $this->openPanel($application);
        }
    }

    /** "Lolos ke …" from the panel (saves pembina/date/note in the same step, sends the note for the student). */
    public function advance(MoveTalentStage $move): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);

        if ($application === null || $this->movedElsewhere($application)) {
            return;
        }

        $from = $application->status;
        $next = $from->next();

        if ($next === null) {
            return;
        }

        try {
            $move->handle($this->actor(), $application, $next, [...$this->panelDetails(), 'student_note' => $this->panelStudentNote]);
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->panelDone = 'Dipindahkan dari '.$from->label().' ke '.$next->label().'.'.$this->sentText(MoveTalentStage::notifiesStudent($application));
        $this->openPanel($application->refresh(), keepDone: true);
    }

    /** Row check icon: move one row to its next stage. */
    public function advanceRow(string $id, MoveTalentStage $move): void
    {
        $application = $this->find($id);

        if ($application === null || $this->movedElsewhere($application)) {
            return;
        }

        $next = $application->status->next();

        if ($next === null) {
            return;
        }

        try {
            // No note is typed here: a draft saved with "Simpan" goes out with this move (MoveTalentStage).
            $moved = $move->handle($this->actor(), $application, $next);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: collect($e->errors())->flatten()->first() ?? 'Tahap tidak bisa diubah.', tone: 'error');

            return;
        }

        $this->dispatch('sth-toast', message: 'Dipindahkan ke '.$next->label().'.'
            .($moved->student_note !== null ? ' Catatan untuk siswa yang tersimpan ikut terkirim.' : ''), tone: 'success');

        if ($this->panelId === $id) {
            $this->openPanel($application->refresh());
        }
    }

    // "Tidak lolos": one confirm dialog for the panel row and for the checked rows (M2-B, Q2-15) ----------

    /**
     * "Tidak lolos" in the panel: checks the panel fields, then opens the dialog. Nothing changes and nobody
     * is told before reject().
     */
    public function askReject(UpdateTalentAssessment $assessment): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);

        if ($application === null) {
            return;
        }

        $this->authorize('move', $application);

        // QA9: moved or marked tidak lolos by a colleague since the panel was drawn: no dialog, the typed note stays.
        if ($this->movedElsewhere($application)) {
            return;
        }

        if ($application->status->isFinal()) {
            $this->dispatch('sth-toast', message: 'Tahap ini sudah final.', tone: 'error');

            return;
        }

        try {
            // The same checks the rejection runs, so the dialog never opens over a field error.
            $assessment->validated($this->panelDetails(), $application);
            TalentNote::clean($this->panelStudentNote, 'student_note');
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->rejectTarget = self::REJECT_PANEL;
        $this->dispatch('sth-modal-open', id: self::REJECT_DIALOG);
    }

    /** "Tidak lolos" in the bulk bar: the dialog asks for the optional note and the confirmation. */
    public function askBulkReject(): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->resetErrorBag('bulkNote');
        $rows = $this->checkedApplications();

        if ($rows->isEmpty()) {
            $this->checked = [];

            return;
        }

        // "Semua" mixes every status: rows that are already final are skipped, and with only such rows
        // there is nothing to ask.
        if ($rows->every(fn (TalentApplication $a): bool => $a->status->isFinal())) {
            $this->dispatch('sth-toast', message: 'Pilihan yang dicentang sudah final, tidak bisa ditandai tidak lolos.', tone: 'error');

            return;
        }

        $this->bulkNote = '';
        $this->rejectTarget = self::REJECT_BULK;
        $this->dispatch('sth-modal-open', id: self::REJECT_DIALOG);
    }

    /** Confirm in the dialog. Without a dialog opened by askReject() / askBulkReject() there is nothing to confirm. */
    public function reject(RejectTalentApplication $reject): void
    {
        match ($this->rejectTarget) {
            self::REJECT_PANEL => $this->rejectPanel($reject),
            self::REJECT_BULK => $this->bulkReject($reject),
            default => abort(404),
        };
    }

    private function rejectPanel(RejectTalentApplication $reject): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);

        if ($application === null) {
            return;
        }

        // QA9: the row left the stage the dialog was opened for: nothing is rejected, the typed note stays.
        if ($this->movedElsewhere($application)) {
            $this->closeRejectDialog();

            return;
        }

        $from = $application->status;

        try {
            // What the panel shows is what goes out: an emptied field means "no note", also over a saved draft.
            $reject->handle($this->actor(), $application, $this->panelStudentNote, $this->panelDetails());
        } catch (ValidationException $e) {
            $this->closeRejectDialog();
            $this->showPanelErrors($e);

            return;
        }

        $this->closeRejectDialog();
        $this->panelDone = 'Tidak lolos dari tahap '.$from->label().'.'.$this->sentText(RejectTalentApplication::notifiesStudent($application));
        $this->openPanel($application->refresh(), keepDone: true);
    }

    /** Recycle icon / "Pulihkan" on a tidak-lolos row: opens the confirm dialog. */
    public function askRestore(string $id): void
    {
        $application = $this->find($id);

        if ($application === null) {
            return;
        }

        $this->authorize('restore', $application);

        if (! $application->status->isRestorable()) {
            $this->dispatch('sth-toast', message: 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.', tone: 'error');

            return;
        }

        $this->restoreId = $application->getKey();
        $this->restoreNote = '';
        // The stage before the rejection stays the preselected choice (Terdaftar when it is unknown).
        $this->restoreTo = $application->restoreTarget()->value;
        // Preselect the row's pembina only while the dialog lists that guru (an active guru account).
        $this->restoreMentor = RestoreTalentApplication::isActiveGuru($application->mentor_id) ? (string) $application->mentor_id : '';
        $this->resetErrorBag(['restoreNote', 'restoreTo', 'restoreMentor']);
        $this->dispatch('sth-modal-open', id: self::RESTORE_DIALOG);
    }

    /** Confirm in the dialog: back into the stage Kesiswaan ticked (M3-A), the student is told. */
    public function restore(RestoreTalentApplication $restore): void
    {
        $this->resetErrorBag(['restoreNote', 'restoreTo', 'restoreMentor']);
        $application = $this->find((string) $this->restoreId);

        if ($application === null) {
            return;
        }

        $this->authorize('restore', $application);
        $to = TalentStatus::tryFrom($this->restoreTo);

        if ($to === null || ! in_array($to, RestoreTalentApplication::TARGETS, true)) {
            $this->addError('restoreTo', RestoreTalentApplication::TARGET_MESSAGE);

            return;
        }

        try {
            $restored = $restore->handle($this->actor(), $application, $this->restoreNote, $to, $this->restoreMentor);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['student_note'])) {
                $this->addError('restoreNote', (string) ($errors['student_note'][0] ?? 'Periksa catatan.'));

                return;
            }

            if (isset($errors['mentor_id'])) {
                $this->addError('restoreMentor', (string) ($errors['mentor_id'][0] ?? RestoreTalentApplication::MENTOR_MESSAGE));

                return;
            }

            if (isset($errors['to'])) {
                $this->addError('restoreTo', (string) ($errors['to'][0] ?? RestoreTalentApplication::TARGET_MESSAGE));

                return;
            }

            $this->closeRestoreDialog();
            $this->dispatch('sth-toast', message: (string) (collect($errors)->flatten()->first() ?? 'Pilihan ini tidak bisa dipulihkan.'), tone: 'error');

            return;
        }

        $this->closeRestoreDialog();
        // QA9: the notice is claimed only when the student was told (not an alumnus or a disabled account).
        $this->dispatch('sth-toast', message: 'Dipulihkan ke tahap '.$restored->status->label().'.'
            .(RestoreTalentApplication::notifiesStudent($restored) ? ' Siswa diberi tahu.' : ''), tone: 'success');

        if ($this->panelId === $restored->getKey()) {
            $this->openPanel($restored);
        }
    }

    /**
     * "Simpan": pembina, jadwal and the note for the guru pembina are saved; a typed note for the student is
     * kept as a draft on the row and the toast says when it will be sent (M2-B, Q2-14). Nobody is notified.
     */
    public function savePanel(UpdateTalentAssessment $update): void
    {
        $this->resetErrorBag();
        $application = $this->find((string) $this->panelId);

        if ($application === null) {
            return;
        }

        $details = $this->panelDetails();

        // The field is only drawn for a row that still gets a decision (not pembinaan, not tidak lolos).
        if (! $application->status->isFinal()) {
            $details['student_note_draft'] = $this->panelStudentNote;
        }

        try {
            $saved = $update->handle($this->actor(), $application, $details);
        } catch (ValidationException $e) {
            $this->showPanelErrors($e);

            return;
        }

        $this->dispatch('sth-toast', message: $saved->student_note_draft !== null ? self::SAVED_WITH_DRAFT : 'Data asesmen tersimpan.', tone: 'success');
    }

    /**
     * "Pindahkan ke …" in the bulk bar (D-128): fixes which checked rows move and from which stage, then opens the
     * dialog. Nothing moves and nobody is told before bulkAdvance().
     */
    public function askBulkAdvance(): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->advanceAsk = null;
        $rows = $this->checkedApplications();

        if ($rows->isEmpty()) {
            $this->checked = [];

            return;
        }

        $ask = ['stages' => [], 'final' => 0, 'moved' => 0, 'rejected' => 0];

        foreach ($rows as $application) {
            // QA9: a row another tab moved or rejected since this one was drawn stays where it is. Judged here, before
            // the render that follows this call writes the new stages into $shownStages.
            if (! $this->showsStage($application)) {
                $ask[$application->status === TalentStatus::TidakLolos ? 'rejected' : 'moved']++;
            } elseif ($application->status->next() === null) {
                $ask['final']++;
            } else {
                $ask['stages'][(string) $application->getKey()] = $application->status->value;
            }
        }

        if ($ask['stages'] !== []) {
            $this->advanceAsk = $ask;
            $this->dispatch('sth-modal-open', id: self::ADVANCE_DIALOG);

            return;
        }

        // Nothing is left to move, so there is nothing to ask.
        if ($ask['moved'] + $ask['rejected'] > 0) {
            $this->afterBulk('0 pilihan talenta dipindahkan ke tahap berikutnya.', $ask['final'], $ask['moved'], $ask['rejected']);

            return;
        }

        $this->dispatch('sth-toast', message: 'Pilihan yang dicentang sudah final, tidak bisa dipindahkan.', tone: 'error');
    }

    /**
     * "Pindahkan" in the dialog. Without a dialog opened by askBulkAdvance() there is nothing to confirm: it returns
     * without a word (never an error page), so a second click after the move went through changes nothing.
     */
    public function bulkAdvance(MoveTalentStage $move): void
    {
        $ask = $this->advanceAsk;
        $this->closeAdvanceDialog();

        if ($ask === null) {
            return;
        }

        $moved = 0;
        $skipped = $ask['final'];
        $movedBefore = $ask['moved'];
        $rejectedBefore = $ask['rejected'];
        $notes = 0;

        foreach ($this->checkedApplications() as $application) {
            $asked = $ask['stages'][(string) $application->getKey()] ?? null;

            // Left out when the dialog was opened (final, or moved before it): counted above.
            if ($asked === null) {
                continue;
            }

            // QA9: a row a colleague moved or rejected since the dialog was opened stays where it is.
            if ($application->status->value !== $asked) {
                $application->status === TalentStatus::TidakLolos ? $rejectedBefore++ : $movedBefore++;

                continue;
            }

            $next = $application->status->next();

            if ($next === null) {
                $skipped++;

                continue;
            }

            try {
                // No note is typed in the bulk bar: a draft saved with "Simpan" goes out with the row's move.
                $notes += $move->handle($this->actor(), $application, $next)->student_note !== null ? 1 : 0;
                $moved++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->afterBulk($moved.' pilihan talenta dipindahkan ke tahap berikutnya.'.self::draftsSentText($notes), $skipped, $movedBefore, $rejectedBefore);
    }

    /** Header checkbox: select every visible row, or clear when all are selected. */
    public function toggleAll(): void
    {
        $visible = $this->orderedQuery()->limit($this->limit)->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $this->checked = array_diff($visible, $this->checked) === [] ? [] : $visible;
        $this->rejectTarget = '';
        $this->advanceAsk = null;
    }

    public function cancelBulk(): void
    {
        $this->rejectTarget = '';
        $this->advanceAsk = null;
        $this->bulkNote = '';
        $this->checked = [];
        $this->resetErrorBag('bulkNote');
    }

    /**
     * Confirmed bulk rejection. The note is optional; a typed one goes to every checked row. Without one, a
     * row that has a saved draft sends that draft (the dialog says so).
     */
    private function bulkReject(RejectTalentApplication $reject): void
    {
        $this->resetErrorBag();

        try {
            $note = TalentNote::clean($this->bulkNote, 'bulkNote');
        } catch (ValidationException $e) {
            $this->addError('bulkNote', (string) ($e->errors()['bulkNote'][0] ?? 'Periksa catatan.'));

            return;
        }

        $done = 0;
        $skipped = 0;
        $drafts = 0;
        $told = 0;
        $movedBefore = 0;
        $rejectedBefore = 0;

        foreach ($this->checkedApplications() as $application) {
            // QA9: a row a colleague moved or rejected since the dialog was opened is not rejected (again).
            if (! $this->showsStage($application)) {
                $application->status === TalentStatus::TidakLolos ? $rejectedBefore++ : $movedBefore++;

                continue;
            }

            try {
                $rejected = $reject->handle($this->actor(), $application, $note);
                $drafts += $note === null && $rejected->student_note !== null ? 1 : 0;
                $told += RejectTalentApplication::notifiesStudent($rejected) ? 1 : 0;
                $done++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        $this->closeRejectDialog();
        $this->afterBulk($done.' pilihan talenta ditandai tidak lolos.'.self::toldText($told, $done).self::draftsSentText($drafts), $skipped, $movedBefore, $rejectedBefore);
    }

    // "Download data (Excel)": Kesiswaan chooses the statuses first (M3-A) -------------------------------

    /** Opens the dialog with the statuses of the open tab ticked ("Semua" = every status). */
    public function askExport(): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->resetErrorBag('exportStatuses');
        // An empty status cannot be ticked (QA5): the open tab's status only when it has rows.
        $this->exportStatuses = array_values(array_intersect(self::statusValues($this->tabStatuses()), $this->filledStatuses()));
        $this->dispatch('sth-modal-open', id: self::EXPORT_DIALOG);
    }

    /** "Pilih semua status" in the dialog. */
    public function selectAllExport(): void
    {
        $this->authorize('manage', TalentApplication::class);
        $this->resetErrorBag('exportStatuses');
        $this->exportStatuses = $this->filledStatuses();
    }

    /**
     * "Download" in the dialog: the ticked statuses become the literal argument of export(), so the download
     * that starts by itself after the password page is the same one.
     */
    public function download(AdminRecipients $recipients): ?BinaryFileResponse
    {
        $this->authorize('manage', TalentApplication::class);
        $this->resetErrorBag('exportStatuses');

        $chosen = array_values(array_filter(
            TalentStatus::cases(),
            fn (TalentStatus $status): bool => in_array($status->value, $this->exportStatuses, true),
        ));

        if ($chosen === []) {
            $this->addError('exportStatuses', 'Pilih minimal satu status.');

            return null;
        }

        if ($this->baseQuery()->whereIn('status', self::statusValues($chosen))->doesntExist()) {
            $this->addError('exportStatuses', self::EXPORT_EMPTY_CHOICE);

            return null;
        }

        $this->dispatch('sth-modal-close', id: self::EXPORT_DIALOG);

        // Every status that has rows is ticked (the empty ones cannot be): that is "every status" for the file.
        $every = array_diff($this->filledStatuses(), self::statusValues($chosen)) === [];

        return $this->export($recipients, $every ? self::EXPORT_ALL : self::exportArgument($chosen));
    }

    /**
     * Excel download of the list (full NIS, names, notes): password from the last 15 minutes, at most
     * EXPORT_MAX_ROWS, audited with its filters, Admins alerted above LargeExportNotice::LIMIT rows
     * (SECURITY §3i, §3m-9; same gates as Database prestasi).
     *
     * `$status` says which statuses go into the file: '' = what the open tab lists, EXPORT_ALL = every
     * status, else TalentStatus values joined by commas ("terdaftar,seleksi"); anything else is a 404. The
     * filters jenis, cabang, kelas and search always apply.
     */
    public function export(AdminRecipients $recipients, string $status = ''): ?BinaryFileResponse
    {
        $this->authorize('manage', TalentApplication::class);

        $statuses = $this->exportSelection($status);
        abort_if($statuses === null, 404);

        // Rebuilt from TalentStatus values (never the raw input), so the call to resume stays a literal.
        if (! $this->passwordRecentlyConfirmed($status === '' ? 'export' : "export('".self::exportArgument($statuses)."')")) {
            return null;
        }

        $values = self::statusValues($statuses);
        $count = $this->baseQuery()->whereIn('status', $values)->count();

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
        $describe = $this->describeFilters($statuses);

        // Marked like Database prestasi (ExportAchievements, SECURITY §3m): title, who exported it and when, filter.
        $writer->addRow(self::exportRow(['Database talenta siswa SMAGA Talent Hub'], $bold));
        $writer->addRow(self::exportRow(['Diekspor oleh '.$actor->name.' · '.$exportedAt->format('d-m-Y H.i').' WIB']));
        $writer->addRow(self::exportRow(['Filter: '.($describe === [] ? 'semua talenta' : implode('; ', $describe))]));
        $writer->addRow(self::exportRow(['']));
        $writer->addRow(self::exportRow(self::EXPORT_HEADER, $bold));

        $this->baseQuery()->whereIn('status', $values)
            ->with(['student.user', 'student.classroom', 'talentType', 'talentBranch', 'mentor'])
            ->orderBy('created_at')
            // QA9: a unique order for the pages of 500. Rows created in the same second shared their place, so a
            // page could repeat one row and skip another.
            ->orderBy('id')
            ->chunk(500, function (Collection $chunk) use ($writer): void {
                foreach ($chunk as $a) {
                    $writer->addRow(self::exportRow([
                        (string) $a->student?->user?->name,
                        (string) $a->student?->nis,
                        (string) $a->student?->classroom?->name,
                        (string) $a->talentType?->code?->label(),
                        (string) $a->talentBranch?->name,
                        $a->status->label(),
                        (string) $a->mentor?->name,
                        (string) $a->assessment_on?->format('Y-m-d'),
                        (string) $a->note,
                        (string) $a->student_note,
                        (string) $a->created_at?->format('Y-m-d'),
                    ]));
                }
            });

        $writer->close();

        activity('talent')->causedBy($actor)->event('talent.exported')
            ->withProperties([
                'filters' => $this->exportFilters($statuses),
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

    /**
     * What the file holds, in words, for the export's "Filter:" row (same style as
     * AchievementFilters::describe()): the chosen statuses first ("Tahap: Terdaftar, Seleksi/asesmen", or
     * "Tahap: semua" next to another filter), then jenis, cabang, kelas and search. Empty = everything.
     *
     * @param  list<TalentStatus>  $statuses
     * @return list<string>
     */
    private function describeFilters(array $statuses): array
    {
        $others = $this->describeOtherFilters();
        $tahap = self::isEveryStatus($statuses)
            ? ($others === [] ? null : 'Tahap: semua')
            : 'Tahap: '.implode(', ', array_map(fn (TalentStatus $status): string => $status->label(), $statuses));

        return array_values(array_filter([$tahap, ...$others]));
    }

    /**
     * The filters next to the status, in words (also shown in the download dialog).
     *
     * @return list<string>
     */
    private function describeOtherFilters(): array
    {
        $jenis = TalentTypeCode::tryFrom($this->jenis);
        $cabang = $this->cabang !== '' ? TalentBranch::query()->whereKey($this->cabang)->value('name') : null;
        $kelas = $this->kelas !== '' ? Classroom::query()->whereKey($this->kelas)->value('name') : null;

        return array_values(array_filter([
            $jenis !== null ? 'Jenis: '.$jenis->label() : null,
            $cabang !== null ? 'Cabang: '.$cabang : null,
            $kelas !== null ? 'Kelas: '.$kelas : null,
            $this->q !== '' ? 'Siswa: '.$this->q : null,
        ]));
    }

    /**
     * The audit properties of an export: like activeFilters(), with the chosen statuses under `tahap`
     * (several joined by commas, absent when every status is in the file).
     *
     * @param  list<TalentStatus>  $statuses
     * @return array<string, string>
     */
    private function exportFilters(array $statuses): array
    {
        return array_filter([
            'tahap' => self::isEveryStatus($statuses) ? '' : implode(',', self::statusValues($statuses)),
            'jenis' => $this->jenis,
            'cabang' => $this->cabang,
            'kelas' => $this->kelas,
            'q' => $this->q,
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The statuses an export call asks for, in pipeline order; null = not a valid choice.
     *
     * @return list<TalentStatus>|null
     */
    private function exportSelection(string $status): ?array
    {
        if ($status === '') {
            return $this->tabStatuses();
        }

        if ($status === self::EXPORT_ALL) {
            return TalentStatus::cases();
        }

        if (strlen($status) > 100) {
            return null;
        }

        $asked = array_unique(explode(',', $status));
        $chosen = array_values(array_filter(TalentStatus::cases(), fn (TalentStatus $s): bool => in_array($s->value, $asked, true)));

        // Every part has to be a status: a mistyped one is refused, never dropped without a word.
        return $chosen !== [] && count($chosen) === count($asked) ? $chosen : null;
    }

    /** @return list<string> the status values that have rows inside jenis, cabang, kelas and search, in pipeline order */
    private function filledStatuses(): array
    {
        $counts = $this->counts();

        return array_values(array_filter(self::statusValues(TalentStatus::cases()), fn (string $status): bool => ($counts[$status] ?? 0) > 0));
    }

    /** @return list<TalentStatus> the statuses the open tab lists ("Semua" = every one) */
    private function tabStatuses(): array
    {
        $tab = TalentStatus::tryFrom($this->tahap);

        return $tab !== null ? [$tab] : TalentStatus::cases();
    }

    /**
     * The literal argument of export() for a choice made in the dialog.
     *
     * @param  list<TalentStatus>  $statuses
     */
    private static function exportArgument(array $statuses): string
    {
        return self::isEveryStatus($statuses) ? self::EXPORT_ALL : implode(',', self::statusValues($statuses));
    }

    /** @param  list<TalentStatus>  $statuses */
    private static function isEveryStatus(array $statuses): bool
    {
        return count($statuses) === count(TalentStatus::cases());
    }

    /**
     * @param  list<TalentStatus>  $statuses
     * @return list<string>
     */
    private static function statusValues(array $statuses): array
    {
        return array_map(fn (TalentStatus $status): string => $status->value, $statuses);
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

    public function render(PointFormatter $formatter): View
    {
        $this->authorize('manage', TalentApplication::class);
        $this->clampLimit();

        $rows = $this->orderedQuery()
            ->with(['student.user', 'student.classroom', 'talentType', 'talentBranch'])
            ->limit($this->limit + 1)
            ->get();
        $more = $rows->count() > $this->limit;
        $rows = $rows->take($this->limit);
        $prestasi = $this->prestasi($rows->pluck('student_id')->unique()->values()->all());
        $counts = $this->counts();
        $panel = $this->panelId !== null ? $this->panel($formatter) : null;

        // What this render shows is what a move may start from (QA9, movedElsewhere()).
        $this->shownStages = $rows->mapWithKeys(fn (TalentApplication $a): array => [(string) $a->getKey() => $a->status->value])->all();

        if ($panel !== null) {
            $this->shownStages[(string) $panel['id']] = $panel['status']->value;
        }

        return view('livewire.kesiswaan.talenta', [
            'counts' => $counts,
            'rows' => $rows->map(fn (TalentApplication $a): array => $this->row($a, $prestasi, $formatter)),
            'hasMore' => $more && $this->limit < self::MAX,
            'capped' => $more && $this->limit >= self::MAX,
            'shownText' => $this->shownText($rows->count()),
            'types' => TalentType::query()->ordered()->get(),
            'branches' => $this->branchOptions(),
            'classes' => $this->classOptions(),
            'mentors' => User::query()->role(RoleName::Guru->value)->canLogIn()->orderBy('name')->pluck('name', 'id')->all(),
            'panel' => $panel,
            'bulkNext' => $this->bulkNextLabel(),
            'restoring' => $this->restoring(),
            'rejecting' => $this->rejecting(),
            'advancing' => $this->advancing(),
            'exportOptions' => self::exportOptions($counts),
            'exportScope' => $this->exportScope(),
            // One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
        ])->layoutData(['badges' => $this->kesiswaanBadges(), 'search' => false]);
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
            // "Minta ditinjau ulang" first inside Tidak lolos (the only stage that has requests).
            ->orderByRaw('case when review_requested_at is null then 1 else 0 end')
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
            'nis' => (string) $student?->nis,
            'type' => $a->talentType?->code?->label() ?? $a->talentType?->name,
            'bidang' => $a->talentType?->bidang,
            'branch' => $a->talentBranch?->name ?? 'Tanpa cabang',
            'hasEvidence' => $a->evidence_file_id !== null,
            'points' => $p !== null ? $formatter->format($p['points']) : null,
            'prestasi' => $p['count'] ?? 0,
            'status' => $a->status,
            'next' => $a->status->next(),
            'restorable' => $a->status->isRestorable(),
            'reviewRequested' => $a->hasOpenReviewRequest(),
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
                // M3-C: the click opens the preview popup; the href stays the download.
                'preview' => FileController::previewUrl($a->evidenceFile),
                'previewType' => FileController::previewType($a->evidenceFile),
            ] : null,
            'minat' => $profile !== null && is_array($profile->interests) && $profile->interests !== [] ? implode(', ', $profile->interests) : null,
            'organisasi' => $profile?->organizations,
            'smp' => $profile?->junior_high_achievements,
            'status' => $a->status,
            'next' => $a->status->next(),
            'final' => $a->status->isFinal(),
            'mentorName' => $a->mentor?->name,
            // QA9: the recorded guru pembina whose account left is not in the select; say so under it.
            'mentorHint' => $a->mentor_id !== null && ! RestoreTalentApplication::isActiveGuru($a->mentor_id)
                ? 'Guru pembina yang tercatat'.($a->mentor?->name !== null ? ', '.$a->mentor->name.',' : '').' tidak aktif lagi. Pilih guru pembina lain.'
                : null,
            'note' => $a->note,
            // The note the student reads now (sent with the latest decision).
            'studentNote' => $a->student_note,
            // A draft is saved and not sent yet (the field shows it; only staff ever see it).
            'hasDraft' => ! $a->status->isFinal() && $a->student_note_draft !== null,
            'restorable' => $a->status->isRestorable(),
            'restoreHint' => $a->status->isRestorable() ? self::restoreHint(self::rejectedFrom($a), RejectTalentApplication::notifiesStudent($a)) : null,
            'review' => $a->hasOpenReviewRequest() ? [
                'at' => self::whenLabel($a->review_requested_at),
                'message' => $a->review_message,
            ] : null,
        ];
    }

    /**
     * The row in the "Pulihkan" dialog: who, which talent, the stage it was rejected from (null = unknown) and
     * the stages it can go back to.
     *
     * @return array{name: string, talent: string, from: ?string, options: array<string, array{label: string, sub: string}>, requested: bool}|null
     */
    private function restoring(): ?array
    {
        if ($this->restoreId === null) {
            return null;
        }

        $a = TalentApplication::query()->with(['student.user', 'talentType', 'talentBranch'])->find($this->restoreId);

        if ($a === null) {
            return null;
        }

        $from = self::rejectedFrom($a);

        return [
            'name' => $a->student?->user?->name ?? 'Siswa',
            'talent' => MoveTalentStage::talentLabel($a),
            'from' => $from?->label(),
            'options' => self::restoreOptions($from),
            'requested' => $a->hasOpenReviewRequest(),
            // QA9: the dialog promises the notice only when the student gets it.
            'notice' => 'Pilih tahap tujuannya. '.(RestoreTalentApplication::notifiesStudent($a) ? 'Siswa diberi tahu.' : self::NO_NOTICE),
        ];
    }

    /** The stage a tidak-lolos row had before the rejection, null when it was never recorded. */
    private static function rejectedFrom(TalentApplication $a): ?TalentStatus
    {
        $from = $a->rejected_from;

        return $from instanceof TalentStatus && in_array($from, RestoreTalentApplication::TARGETS, true) ? $from : null;
    }

    /**
     * The tiles of the "Pulihkan" dialog: every stage a choice can go back to, the earlier one marked.
     *
     * @return array<string, array{label: string, sub: string}>
     */
    private static function restoreOptions(?TalentStatus $previous): array
    {
        $options = [];

        foreach (RestoreTalentApplication::TARGETS as $stage) {
            $options[$stage->value] = [
                'label' => $stage->label(),
                'sub' => 'Tahap '.$stage->step().($stage === $previous ? ' · tahap sebelumnya' : ''),
            ];
        }

        return $options;
    }

    /** The sentence in the panel of a tidak-lolos row; "diberi tahu" only for a student who gets notices (QA9). */
    private static function restoreHint(?TalentStatus $previous, bool $told): string
    {
        return ($told ? 'Siswa sudah diberi tahu. ' : '')
            .($previous !== null ? 'Tahap sebelumnya: '.$previous->label().'. ' : '')
            .'Pulihkan untuk mengembalikannya ke tahap yang dipilih.';
    }

    /**
     * The tiles of the "Download data" dialog: every status with its number of rows inside the other filters.
     *
     * @param  array<string, int>  $counts
     * @return array<string, array{label: string, sub: string}>
     */
    private static function exportOptions(array $counts): array
    {
        $options = [];

        foreach (TalentStatus::cases() as $status) {
            $n = $counts[$status->value] ?? 0;
            $options[$status->value] = [
                'label' => $status->label(),
                'sub' => $n > 0 ? number_format($n, 0, ',', '.').' pilihan' : 'Kosong',
                // QA5: a status without rows cannot be ticked, so no empty Excel file is made by accident.
                'disabled' => $n === 0,
            ];
        }

        return $options;
    }

    /** One line in the "Download data" dialog: the filters that also decide what the file holds. */
    private function exportScope(): string
    {
        $others = $this->describeOtherFilters();

        return $others === []
            ? 'File mengikuti filter di layar. Saat ini tidak ada filter jenis, cabang, kelas, atau pencarian.'
            : 'Filter yang sedang dipakai ikut berlaku: '.implode('; ', $others).'.';
    }

    // Helpers --------------------------------------------------------------------------------

    private function openPanel(TalentApplication $application, bool $keepDone = false): void
    {
        $this->panelId = $application->getKey();
        $this->mentorId = $application->mentor_id !== null ? (string) $application->mentor_id : '';
        $this->assessmentOn = $application->assessment_on?->format('Y-m-d') ?? '';
        $this->panelNote = (string) ($application->note ?? '');
        // The unsent draft "Simpan" kept for this row, if any. Never the note that was already sent
        // (`student_note`): that one is written for one decision and shown read-only above the fields.
        $this->panelStudentNote = $application->status->isFinal() ? '' : (string) ($application->student_note_draft ?? '');

        // A "Tidak lolos" question about the row that was open is void once another row is shown.
        if ($this->rejectTarget === self::REJECT_PANEL) {
            $this->rejectTarget = '';
        }

        if (! $keepDone) {
            $this->panelDone = null;
        }

        $this->resetErrorBag(['mentorId', 'assessmentOn', 'panelNote', 'panelStudentNote', 'panel']);
    }

    /** @return array{mentor_id: string, assessment_on: string, note: string} */
    private function panelDetails(): array
    {
        return ['mentor_id' => $this->mentorId, 'assessment_on' => $this->assessmentOn, 'note' => $this->panelNote];
    }

    private function showPanelErrors(ValidationException $e): void
    {
        $map = ['mentor_id' => 'mentorId', 'assessment_on' => 'assessmentOn', 'note' => 'panelNote', 'student_note' => 'panelStudentNote'];

        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? 'panel', (string) ($messages[0] ?? 'Periksa isian.'));
        }
    }

    /** End of the "done" line under the panel buttons; nothing when the student is not told (QA9, D-109). */
    private function sentText(bool $told): string
    {
        if (! $told) {
            return '';
        }

        return trim($this->panelStudentNote) === '' ? ' Siswa diberi tahu.' : ' Siswa diberi tahu, catatan terkirim.';
    }

    /** " Siswa diberi tahu." after a bulk decision, or how many of the rows were told (QA9); nothing when none was. */
    private static function toldText(int $told, int $done): string
    {
        return match (true) {
            $told === 0 => '',
            $told === $done => ' Siswa diberi tahu.',
            default => ' Siswa diberi tahu untuk '.$told.' dari '.$done.' pilihan.',
        };
    }

    /** The line under the title of the "Tidak lolos" and the bulk "Pindahkan" dialog: who is told when it is confirmed (QA9). */
    private static function rejectNotice(int $told, int $total): string
    {
        return match (true) {
            $told === $total => 'Siswa langsung diberi tahu.',
            $told === 0 => self::NO_NOTICE,
            default => 'Siswa yang akunnya aktif langsung diberi tahu.',
        };
    }

    private function closeRestoreDialog(): void
    {
        $this->restoreId = null;
        $this->restoreNote = '';
        $this->restoreTo = '';
        $this->restoreMentor = '';
        $this->dispatch('sth-modal-close', id: self::RESTORE_DIALOG);
    }

    private function closeRejectDialog(): void
    {
        $this->rejectTarget = '';
        $this->dispatch('sth-modal-close', id: self::REJECT_DIALOG);
    }

    private function closeAdvanceDialog(): void
    {
        $this->advanceAsk = null;
        $this->dispatch('sth-modal-close', id: self::ADVANCE_DIALOG);
    }

    /**
     * Texts of the bulk "Pindahkan" dialog (D-128), or null while nothing is asked: how many rows go up one stage, the
     * checked rows that are skipped (`skipped` = already final, `stale` = moved or rejected by a colleague before the
     * question), the saved draft notes that go out with the move, and who is told (D-109).
     *
     * @return array{count: int, skipped: int, stale: int, drafts: int, notice: string}|null
     */
    private function advancing(): ?array
    {
        if ($this->advanceAsk === null) {
            return null;
        }

        $stages = $this->advanceAsk['stages'];
        $rows = $this->checkedApplications()->filter(fn (TalentApplication $a): bool => isset($stages[(string) $a->getKey()]));

        return [
            'count' => count($stages),
            'skipped' => $this->advanceAsk['final'],
            'stale' => $this->advanceAsk['moved'] + $this->advanceAsk['rejected'],
            // No note is typed in the bulk bar: a draft saved with "Simpan" goes out with the row's move.
            'drafts' => $rows->whereNotNull('student_note_draft')->count(),
            'notice' => self::rejectNotice($rows->filter(fn (TalentApplication $a): bool => MoveTalentStage::notifiesStudent($a))->count(), $rows->count()),
        ];
    }

    /** " 2 catatan untuk siswa yang tersimpan ikut terkirim." after a bulk action that sent drafts. */
    private static function draftsSentText(int $count): string
    {
        return $count > 0 ? ' '.$count.' catatan untuk siswa yang tersimpan ikut terkirim.' : '';
    }

    /**
     * Texts of the "Tidak lolos" dialog, or null while nothing is asked.
     *
     * @return array<string, mixed>|null
     */
    private function rejecting(): ?array
    {
        if ($this->rejectTarget === self::REJECT_PANEL && $this->panelId !== null) {
            $a = TalentApplication::query()->with(['student.user', 'talentType', 'talentBranch'])->find($this->panelId);

            if ($a === null) {
                return null;
            }

            $note = trim($this->panelStudentNote);

            return [
                'bulk' => false,
                'name' => $a->student?->user?->name ?? 'Siswa',
                'talent' => MoveTalentStage::talentLabel($a),
                'from' => $a->status->label(),
                // What the student will read: the text in the panel right now.
                'note' => $note !== '' ? $note : null,
                'notice' => self::rejectNotice(RejectTalentApplication::notifiesStudent($a) ? 1 : 0, 1),
            ];
        }

        if ($this->rejectTarget === self::REJECT_BULK) {
            $rows = $this->checkedApplications();
            // Ticked in "Semua", a selection can hold rows that are already final: they are skipped.
            $open = $rows->reject(fn (TalentApplication $a): bool => $a->status->isFinal());

            return [
                'bulk' => true,
                'count' => $open->count(),
                'skipped' => $rows->count() - $open->count(),
                'drafts' => $open->whereNotNull('student_note_draft')->count(),
                'notice' => self::rejectNotice($open->filter(fn (TalentApplication $a): bool => RejectTalentApplication::notifiesStudent($a))->count(), $open->count()),
            ];
        }

        return null;
    }

    /** @return Collection<int, TalentApplication> */
    private function checkedApplications(): Collection
    {
        $ids = array_values(array_filter($this->checked, 'is_string'));

        // student.user loaded at once: the dialog asks per row whether the student is told (QA9 review).
        return $ids === [] ? collect() : TalentApplication::query()->whereKey($ids)->with('student.user')->get();
    }

    /**
     * `$movedBefore` / `$rejectedBefore`: checked rows a colleague moved on or marked tidak lolos since this screen was
     * drawn; they were left alone and the toast counts them (QA9).
     */
    private function afterBulk(string $message, int $skipped, int $movedBefore = 0, int $rejectedBefore = 0): void
    {
        $this->checked = [];
        $this->rejectTarget = '';
        $this->advanceAsk = null;
        $this->bulkNote = '';
        $stale = $movedBefore + $rejectedBefore > 0;
        $this->dispatch('sth-toast', message: $message
            .($movedBefore > 0 ? ' '.$movedBefore.' sudah dipindahkan lebih dulu dan tidak diubah lagi.' : '')
            .($rejectedBefore > 0 ? ' '.$rejectedBefore.' sudah ditandai tidak lolos lebih dulu.' : '')
            .($stale ? ' Daftar sudah diperbarui.' : '')
            .($skipped > 0 ? ' '.$skipped.' dilewati (tahap final).' : ''), tone: $stale ? 'warning' : 'success');

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
        // QA9: every row that matches, not only the ones on screen (the list comes in steps of PAGE).
        $total = $this->filteredQuery()->count();

        if (! $filtered) {
            return 'Menampilkan '.$shown.' dari '.$total.' pilihan talenta';
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

    /**
     * The row an action is about. QA9: a choice the student cancelled meanwhile (Profil talenta "Batalkan", a soft
     * delete) gets a warning toast and a fresh panel instead of a 404 page; null tells the action to stop. An id that
     * never existed is still a 404.
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

    /** QA9: the open dialogs and the panel let go of a row the student cancelled; the panel shows the first row left. */
    private function cancelledByStudent(string $id): void
    {
        $this->dispatch('sth-toast', message: self::CANCELLED_MESSAGE, tone: 'warning');
        $this->checked = array_values(array_diff($this->checked, [$id]));

        if ($this->restoreId === $id) {
            $this->closeRestoreDialog();
        }

        if ($this->panelId !== $id) {
            return;
        }

        if ($this->rejectTarget === self::REJECT_PANEL) {
            $this->closeRejectDialog();
        }

        $this->panelDone = null;
        $first = $this->orderedQuery()->first();

        if ($first !== null) {
            $this->openPanel($first);
        } else {
            $this->panelId = null;
        }
    }

    /** Is the row still in the stage this screen drew for it (QA9, `$shownStages`)? */
    private function showsStage(TalentApplication $application): bool
    {
        return ($this->shownStages[(string) $application->getKey()] ?? null) === $application->status->value;
    }

    /**
     * QA9: the row is no longer in the stage this screen showed (another tab or another Kesiswaan account moved it):
     * nothing moves, a warning says where it is now and the next render shows it. What was typed in the panel stays.
     * Before, the move went on from the new stage: a second "Lolos ke Pemetaan" put the row into Seleksi and told the
     * student a second time.
     */
    private function movedElsewhere(TalentApplication $application): bool
    {
        if ($this->showsStage($application)) {
            return false;
        }

        if ($this->panelId === $application->getKey()) {
            $this->panelDone = null;
        }

        $now = $application->status;
        $this->dispatch('sth-toast', message: match (true) {
            // Not drawn by this screen at all (a page from before this check, or a forged call): no claim about it.
            ! array_key_exists((string) $application->getKey(), $this->shownStages) => 'Daftar sudah diperbarui. Periksa tahapnya, lalu coba lagi.',
            $now === TalentStatus::TidakLolos => 'Pilihan ini sudah ditandai tidak lolos. Daftar sudah diperbarui.',
            default => 'Pilihan ini sudah dipindahkan ke '.$now->label().'. Daftar sudah diperbarui.',
        }, tone: 'warning');

        return true;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
