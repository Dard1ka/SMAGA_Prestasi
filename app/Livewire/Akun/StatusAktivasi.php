<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\ExportActivationReport;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Accounts\UpdateActivationWindow;
use App\Actions\Exports\ExportedFile;
use App\Enums\Permission;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Admin\Concerns\SelectsListedStudents;
use App\Livewire\Admin\HapusSiswa;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Models\Classroom;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Status aktivasi (`kesiswaan.aktivasi.index`, `admin.aktivasi.index`; docs/UI-MAP.md §4a/§5, SECURITY K9/K2,
 * B1): per-class activation report (counts, activation time, last login, NISN in full since D-137, masked email,
 * automatic flags), filters (kelas, status, nama/NISN), formula-safe Excel export, one sheet per tingkat since SV3 (password confirmed in the last 15
 * min, audited; it carries the NISN in full too),
 * K2 "Buka ulang aktivasi" (72 h, reason, Kesiswaan + Admin), Admin "Buka pembekuan" and the K1 window length
 * (Admin), K7 alerts. Every action re-authorizes; the target id is #[Locked] and set only by open*().
 *
 * M1-E (lead, 2026-10-04, item 16): "Lihat" on a class row opens the dialog `aktivasi-kelas` with that class's
 * students (status tabs, steps of 40, the same row actions) instead of filtering the list at the bottom of
 * the page. `?kelas=<classroom id>` opens the page with that dialog. The list at the bottom stays for
 * cross-class lookups and the Excel file; its class filter is `?kelas_daftar=`.
 *
 * D-159 (the lead, 2026-10-19): the page no longer scrolls through both tables. On top the stat tiles stand next to
 * the two boxes "Akun dibekukan" and "Kata sandi awal dicoba di akun aktif" (each BOX rows, then "Tampilkan lebih
 * banyak"); below, one segmented choice "Per kelas" | "Daftar siswa" (`?tampil=siswa`) shows one table at the full
 * width, PREVIEW rows first and STEP more per "Tampilkan lebih banyak", with "Menampilkan X dari Y". The list keeps
 * its status tabs, class filter, search and the Excel download. A list filter in the address, or a change of one, opens the list.
 *
 * SV5 (the lead, 2026-11-04: a nonaktif, archived or frozen student "bisa di undo"): for Admin only (`user.archive`)
 * every student row of "Daftar siswa" and of the class dialog has "Ubah status", which reads "Aktifkan lagi" for a
 * row in nonaktif, arsip, dibekukan or kedaluwarsa and opens the shared dialog UbahStatusSiswa with "Aktif"
 * preselected (askStatus()). Kesiswaan sees nothing of it. "Buka ulang" and "Buka pembekuan" stay as they were. After
 * a change the dialog sends UbahStatusSiswa::DONE_EVENT and the page redraws its lists, boxes and counts.
 *
 * SV6, D-185 (the lead, 5 Nov 2026, requests 4 and 6): both student lists ("Daftar siswa" and the class dialog) offer the
 * same student actions as Admin › Kelas › Lihat siswa (partials livewire.partials.siswa-pilih / siswa-cek / siswa-aksi,
 * SelectsListedStudents): ticks with "Pilih semua" (at most 400), "Ubah status" / "Ubah status terpilih (N)" (Admin,
 * `user.archive`, UbahStatusSiswa), "Hapus" / "Hapus terpilih (N)" (`student.trash`: Admin and Kesiswaan, the shared
 * dialog HapusSiswa) and "Ubah data" (`student.update`, the shared dialog UbahDataSiswa, D-187). Every id is checked
 * against the rows this visit listed (#[Locked] $shownStudents): any other id answers 404, a listed student who went to
 * the trash meanwhile a warning toast. The ticks are one selection for the list on screen; they are cleared whenever
 * that list changes (tab, filter, class, status tab) and after a shared dialog changed something.
 * "Download Excel" (ExportActivationReport) stands on both tabs and in the class dialog: "Per kelas" = the whole school
 * (or the chosen tingkat), "Daftar siswa" = what the list shows (its class filter gives one sheet named after the class,
 * its status tab and search apply), the class dialog = that whole class. No student matches: a warning toast, no file.
 */
#[Title('Status aktivasi')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Aktivasi akun siswa per kelas.', 'search' => false])]
final class StatusAktivasi extends Component
{
    use RequiresRecentPassword;
    use SelectsListedStudents;

    /** Rows per step in the class dialog: one full class fits; 100 rows made the first page about 300 kB. */
    public const PAGE = 40;

    /**
     * Upper bound of every list's rows (the limits are counters the page raises): beyond it the user narrows the list.
     * r3 (2026-10-31): "Daftar siswa" offered "Tampilkan lebih banyak" at this bound and the click did nothing; now the
     * button stops there and capNote() says how to see the others.
     */
    public const MAX = 1000;

    /** D-159: rows of "Per kelas" and "Daftar siswa" before the first "Tampilkan lebih banyak", and the step after. */
    public const PREVIEW = 10;

    public const STEP = 20;

    /** D-159: rows of the two boxes on top before their "Tampilkan lebih banyak", and their step. */
    public const BOX = 3;

    public const BOX_STEP = 10;

    /** Frozen accounts and K7 alerts the boxes can list at most (both queries stop at 50). */
    public const BOX_MAX = 50;

    public const VIEW_CLASSES = 'kelas';

    public const VIEW_STUDENTS = 'siswa';

    public const ACTION_REOPEN = 'buka';

    public const ACTION_UNFREEZE = 'cair';

    public const CLASS_DIALOG = 'aktivasi-kelas';

    /** SV6: a listed student went to the trash (or stopped being a student) meanwhile. */
    public const MSG_STUDENT_GONE = 'Siswa ini sudah dihapus. Daftar sudah diperbarui.';

    public const MSG_STUDENTS_GONE = 'Sebagian siswa yang dipilih sudah dihapus. Daftar dan pilihan sudah diperbarui.';

    public const MSG_CLASS_GONE = 'Kelas ini sudah dihapus. Daftar sudah diperbarui.';

    /** Class shown in the dialog ("Lihat"); '' = no dialog. Checked against the classes that have students. */
    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    /** Status tab inside the dialog. */
    public string $kelasStatus = '';

    #[Locked]
    public int $kelasLimit = self::PAGE;

    /** Class filter of the cross-class list at the bottom (SV6, D-185: also the class of its Excel file, one sheet). */
    #[Url(as: 'kelas_daftar', except: '')]
    public string $daftarKelas = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /**
     * The chooser next to "Download Excel" (SV3; since 2026-11-05 the one chooser of every student download,
     * ExportActivationReport::scopeOptions(): '' = Semua kelas, '10' / '11' / '12' = a tingkat, else a classroom id).
     * A class picked in the list filter of "Daftar siswa" is copied into it, so the file and the list agree until the
     * chooser is changed. In the address (SV3 review) so the download that resumes after the password page is the
     * choice that was made.
     */
    #[Url(as: 'unduh', except: '')]
    public string $unduhTingkat = '';

    /** D-159: which table stands below the summary, "Per kelas" or "Daftar siswa". */
    #[Url(as: 'tampil', except: self::VIEW_CLASSES)]
    public string $tampil = self::VIEW_CLASSES;

    #[Locked]
    public int $limit = self::PREVIEW;

    #[Locked]
    public int $classLimit = self::PREVIEW;

    #[Locked]
    public int $frozenLimit = self::BOX;

    #[Locked]
    public int $k7Limit = self::BOX;

    /** True only in the request that mounts the page with `?kelas=`: the dialog is rendered open. */
    private bool $openClassOnLoad = false;

    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $action = '';

    public string $reason = '';

    public string $windowDays = '';

    #[Locked]
    public string $returnTo = '';

    public function mount(AccountControls $controls): void
    {
        Gate::authorize(Permission::StudentView->value);

        $route = request()->routeIs('admin.*') ? 'admin.aktivasi.index' : 'kesiswaan.aktivasi.index';
        $this->returnTo = Route::has($route) ? route($route) : url()->current();
        $this->windowDays = (string) $controls->windowDays();

        if (! array_key_exists($this->status, AccountControls::STATES)) {
            $this->status = '';
        }

        if (ExportActivationReport::scope($this->unduhTingkat) === null) {
            $this->unduhTingkat = '';
        }

        // A list filter in the address (a link, the way back from the password page) means the list (D-159).
        $listFiltered = $this->status !== '' || trim($this->q) !== '' || $this->daftarKelas !== '';

        if ($listFiltered || $this->tampil !== self::VIEW_STUDENTS) {
            $this->tampil = $listFiltered ? self::VIEW_STUDENTS : self::VIEW_CLASSES;
        }

        // `?kelas=` opens the class dialog; an unknown or empty class is dropped.
        $this->openClassOnLoad = self::knownClass($this->kelas);

        if (! $this->openClassOnLoad) {
            $this->kelas = '';
        }
    }

    public function updated(string $property): void
    {
        // The class of the list is also the class of the file (the one chooser, 2026-11-05).
        if ($property === 'daftarKelas') {
            $this->unduhTingkat = self::knownClass($this->daftarKelas) ? $this->daftarKelas : ExportActivationReport::SCOPE_ALL;
        }

        if (in_array($property, ['daftarKelas', 'status', 'q'], true)) {
            $this->limit = self::PREVIEW;
            $this->tampil = self::VIEW_STUDENTS;
            $this->forgetClass();
            $this->reset('selectedStudents');
        }

        if ($property === 'tampil') {
            $this->reset('selectedStudents');

            if ($this->tampil !== self::VIEW_STUDENTS) {
                $this->tampil = self::VIEW_CLASSES;
            }
        }

        // The address changed under the page (browser history): follow it.
        if ($property === 'kelas') {
            $this->kelasStatus = '';
            $this->kelasLimit = self::PAGE;
            $this->reset('selectedStudents');

            if (self::knownClass($this->kelas)) {
                $this->dispatch('sth-modal-open', id: self::CLASS_DIALOG);
            } else {
                $this->kelas = '';
                $this->dispatch('sth-modal-close', id: self::CLASS_DIALOG);
            }
        }
    }

    /** D-159: the segmented choice between the two tables. */
    public function setView(string $view): void
    {
        $this->tampil = $view === self::VIEW_STUDENTS ? self::VIEW_STUDENTS : self::VIEW_CLASSES;
        $this->forgetClass();
        $this->reset('selectedStudents');
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, AccountControls::STATES) ? $status : '';
        $this->limit = self::PREVIEW;
        $this->tampil = self::VIEW_STUDENTS;
        $this->forgetClass();
        $this->reset('selectedStudents');
    }

    public function resetFilters(): void
    {
        // The chooser followed the list's class: it follows the reset too.
        if ($this->daftarKelas !== '' && $this->unduhTingkat === $this->daftarKelas) {
            $this->unduhTingkat = ExportActivationReport::SCOPE_ALL;
        }

        $this->reset('daftarKelas', 'status', 'q', 'selectedStudents');
        $this->limit = self::PREVIEW;
        $this->forgetClass();
    }

    public function showMore(): void
    {
        $this->limit = min(self::MAX, $this->limit + self::STEP);
        $this->forgetClass();
    }

    public function showMoreClasses(): void
    {
        $this->classLimit = min(self::MAX, $this->classLimit + self::STEP);
        $this->forgetClass();
    }

    public function showMoreFrozen(): void
    {
        $this->frozenLimit = min(self::BOX_MAX, $this->frozenLimit + self::BOX_STEP);
        $this->forgetClass();
    }

    public function showMoreK7(): void
    {
        $this->k7Limit = min(self::BOX_MAX, $this->k7Limit + self::BOX_STEP);
        $this->forgetClass();
    }

    /** The line in place of "Tampilkan lebih banyak" once "Daftar siswa" shows MAX rows and more match (r3). */
    public static function capNote(): string
    {
        return 'Menampilkan '.number_format(self::MAX, 0, ',', '.').' siswa pertama. Persempit dengan kelas, status, atau pencarian untuk melihat lainnya.';
    }

    // Class dialog ("Lihat") ----------------------------------------------------------------------

    public function showClass(string $classroomId): void
    {
        Gate::authorize(Permission::StudentView->value);
        abort_unless(self::knownClass($classroomId), 404);

        $this->kelas = $classroomId;
        $this->kelasStatus = '';
        $this->kelasLimit = self::PAGE;
        $this->reset('selectedStudents');
        $this->dispatch('sth-modal-open', id: self::CLASS_DIALOG);
    }

    public function setClassStatus(string $status): void
    {
        $this->kelasStatus = array_key_exists($status, AccountControls::STATES) ? $status : '';
        $this->kelasLimit = self::PAGE;
        $this->reset('selectedStudents');
    }

    public function showMoreClass(): void
    {
        $this->kelasLimit = min(self::MAX, $this->kelasLimit + self::PAGE);
    }

    public function closeClass(): void
    {
        $this->forgetClass();
        $this->reset('selectedStudents');
        $this->dispatch('sth-modal-close', id: self::CLASS_DIALOG);
    }

    // Actions -------------------------------------------------------------------------------------

    public function openReopen(int $userId): void
    {
        Gate::authorize(Permission::StudentImport->value);
        $student = $this->studentAccount($userId);

        // A stale list (the button is only drawn where a reopen does something): say why instead of a dialog.
        if (($notNeeded = ReopenActivationWindow::notNeededMessage($student)) !== null) {
            $this->dispatch('sth-toast', message: $notNeeded, tone: 'error');

            return;
        }

        $this->openAction($student, self::ACTION_REOPEN);
    }

    public function openUnfreeze(int $userId): void
    {
        Gate::authorize(Permission::UserResetPassword->value);
        $this->openAction($this->account($userId), self::ACTION_UNFREEZE);
    }

    public function submitAction(ReopenActivationWindow $reopen, UnfreezeAccount $unfreeze): void
    {
        abort_if($this->targetId === null, 404);

        // A reopen needs a real reason (M2-B: "ab" was accepted); the Action checks the trimmed text again.
        $min = $this->action === self::ACTION_REOPEN ? ReopenActivationWindow::MIN_REASON : 1;

        $this->validate(
            ['reason' => ['required', 'string', 'min:'.$min, 'max:500']],
            ['required' => 'Isi alasannya.', 'min' => 'Alasan minimal :min karakter.', 'max' => 'Maksimal :max karakter.'],
            ['reason' => 'alasan'],
        );

        try {
            if ($this->action === self::ACTION_REOPEN) {
                Gate::authorize(Permission::StudentImport->value);
                $until = $reopen->handle($this->actor(), $this->studentAccount($this->targetId), $this->reason);
                $message = 'Aktivasi dibuka lagi sampai '.$until->translatedFormat('j M Y H:i').'.';
            } elseif ($this->action === self::ACTION_UNFREEZE) {
                Gate::authorize(Permission::UserResetPassword->value);

                if (! $this->passwordRecentlyConfirmed()) {
                    return;
                }

                $unfreeze->handle($this->actor(), $this->account($this->targetId), $this->reason);
                $message = 'Pembekuan dibuka. Akun bisa dipakai masuk lagi.';
            } else {
                abort(404);
            }
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['reason' => collect($e->errors())->flatten()->all()]);
        }

        $this->reset('reason', 'targetId', 'action');
        $this->dispatch('sth-modal-close', id: 'aktivasi-aksi');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    // Student actions of both lists (SV5, SV6) ------------------------------------------------------

    /**
     * SV5 "Ubah status" / "Aktifkan lagi" of one student row (Admin only): the shared dialog, with "Aktif"
     * preselected for a row whose account can be brought back. An id this visit never listed answers 404 (SV6: a
     * staff, unknown or forged id), a listed student who went to the trash meanwhile a warning toast.
     */
    public function askStatus(int $userId): void
    {
        Gate::authorize('changeStudentStatus', User::class);
        $student = $this->listedStudent($userId);

        if ($student === null) {
            return;
        }

        Gate::authorize('changeStudentStatus', $student);

        $this->dispatch(
            UbahStatusSiswa::OPEN_EVENT,
            ids: [(int) $student->getKey()],
            target: UbahStatusSiswa::restorable(AccountControls::state($student)) ? UbahStatusSiswa::TARGET_ACTIVE : null,
        )->to(UbahStatusSiswa::class);
    }

    /** SV6 "Ubah status terpilih (N)" (Admin only): the shared dialog for the ticked rows. */
    public function askStatusSelected(): void
    {
        Gate::authorize('changeStudentStatus', User::class);
        $ids = $this->tickedStudents();

        if ($ids !== null) {
            $this->dispatch(UbahStatusSiswa::OPEN_EVENT, ids: $ids)->to(UbahStatusSiswa::class);
        }
    }

    /** SV6 "Hapus" of one row (`student.trash`): the shared dialog "Hapus 1 siswa?". */
    public function askTrashStudent(int $userId): void
    {
        Gate::authorize('trashStudents', TrashEntry::class);
        $student = $this->listedStudent($userId);

        if ($student !== null) {
            $this->dispatch(HapusSiswa::OPEN_EVENT, ids: [(int) $student->getKey()])->to(HapusSiswa::class);
        }
    }

    /** SV6 "Hapus terpilih (N)" (`student.trash`): the shared dialog "Hapus N siswa?" for the ticked rows. */
    public function askTrashSelected(): void
    {
        Gate::authorize('trashStudents', TrashEntry::class);
        $ids = $this->tickedStudents();

        if ($ids !== null) {
            $this->dispatch(HapusSiswa::OPEN_EVENT, ids: $ids)->to(HapusSiswa::class);
        }
    }

    /** SV6, D-187 "Ubah data" of one row (`student.update`): the shared dialog UbahDataSiswa for that student. */
    public function askData(int $userId): void
    {
        Gate::authorize(Permission::StudentUpdate->value);
        $student = $this->listedStudent($userId);

        if ($student !== null) {
            $this->dispatch(UbahDataSiswa::OPEN_EVENT, userId: (int) $student->getKey());
        }
    }

    /** "Pilih semua yang tampil": the rows of the open class dialog, else the rows of "Daftar siswa" (at most 400). */
    public function toggleAllStudents(ActivationReport $report): void
    {
        Gate::authorize(Permission::StudentView->value);
        abort_unless(Gate::allows('changeStudentStatus', User::class) || Gate::allows('trashStudents', TrashEntry::class), 403);

        $this->toggleTicks($this->listedIds($report));
    }

    /** A shared dialog changed something: render() reads the lists and counts again, and the ticks are cleared. */
    #[On(UbahStatusSiswa::DONE_EVENT)]
    #[On(HapusSiswa::DONE_EVENT)]
    #[On(UbahDataSiswa::DONE_EVENT)]
    public function statusChanged(): void
    {
        $this->reset('selectedStudents');
    }

    public function saveWindow(UpdateActivationWindow $update): void
    {
        Gate::authorize(Permission::SettingManage->value);
        $this->forgetClass();

        $this->validate(
            ['windowDays' => ['required', 'integer', 'min:1', 'max:'.AccountControls::MAX_WINDOW_DAYS]],
            ['required' => 'Wajib diisi.', 'integer' => 'Isi angka hari.', 'min' => 'Minimal :min hari.', 'max' => 'Maksimal :max hari.'],
            ['windowDays' => 'jendela aktivasi'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $update->handle($this->actor(), (int) $this->windowDays);
        $this->dispatch('sth-toast', message: 'Jendela aktivasi disimpan: '.(int) $this->windowDays.' hari untuk impor berikutnya.', tone: 'success');
    }

    /**
     * "Download Excel" next to the two tabs (SV3; SV6, D-185 moved the file into ExportActivationReport; since 2026-11-05
     * the Status aktivasi file with the student data and the one chooser): the chosen scope (Semua kelas, a tingkat or one
     * class); on "Daftar siswa" its status tab and search apply too. No match: a warning toast, no file.
     */
    public function export(ExportActivationReport $export): ?BinaryFileResponse
    {
        Gate::authorize(Permission::StudentView->value);
        // A value the chooser never offered (forged): 404. A class that lost its students meanwhile: the toast below.
        abort_unless(ExportActivationReport::scope($this->unduhTingkat) !== null || Str::isUlid($this->unduhTingkat), 404);
        // The file belongs to the tab: coming back from the password page must not reopen a dialog.
        $this->forgetClass();

        if (! $this->passwordRecentlyConfirmed('export')) {
            return null;
        }

        $scope = $this->downloadScope();

        if ($scope === null) {
            $this->dispatch('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning');

            return null;
        }

        return $this->download($export->handle($this->actor(), $scope['classroom'], $scope['level'], $scope['state'], $scope['search'], kind: ActivationReport::KIND_STATUS));
    }

    /** SV6, D-185 "Download Excel" in the class dialog: every student of that class, every status, one sheet. */
    public function exportClass(string $classroomId): ?BinaryFileResponse
    {
        Gate::authorize(Permission::StudentView->value);
        $classroom = Classroom::query()->find($classroomId, ['id']);

        if ($classroom === null) {
            $this->dispatch('sth-toast', message: self::MSG_CLASS_GONE, tone: 'warning');

            return null;
        }

        // The id comes from the database, so the call the password page resumes is a plain literal.
        if (! $this->passwordRecentlyConfirmed("exportClass('".$classroom->getKey()."')")) {
            return null;
        }

        return $this->download(app(ExportActivationReport::class)->handle($this->actor(), (string) $classroom->getKey(), kind: ActivationReport::KIND_STATUS));
    }

    // View ----------------------------------------------------------------------------------------

    public function render(ActivationReport $report): View
    {
        $this->limit = max(self::PREVIEW, min(self::MAX, $this->limit));
        $this->classLimit = max(self::PREVIEW, min(self::MAX, $this->classLimit));
        $this->frozenLimit = max(self::BOX, min(self::BOX_MAX, $this->frozenLimit));
        $this->k7Limit = max(self::BOX, min(self::BOX_MAX, $this->k7Limit));
        $all = $report->rows();
        $filtered = $report->rows($this->daftarKelas ?: null, $this->status ?: null, $this->q);
        $isAdmin = Gate::allows(Permission::UserResetPassword->value);
        $canReopen = Gate::allows(Permission::StudentImport->value);
        $canChangeStatus = Gate::allows('changeStudentStatus', User::class);
        $canTrash = Gate::allows('trashStudents', TrashEntry::class);
        $target = $this->targetId !== null
            ? User::query()->with('student.classroom:id,name')->find($this->targetId, ['id', 'name', 'initial_password_expires_at', 'frozen_at'])
            : null;

        $summary = $report->summary($all);
        $frozen = $this->frozenAccounts($isAdmin);
        $k7 = $report->initialPasswordAlerts();
        $classDialog = $this->classDialog($all, $canReopen, $isAdmin, $canChangeStatus);
        $rows = $filtered->take($this->limit)->map(fn (array $row): array => $row + [
            'action' => self::rowAction($row['state'], $row['frozenReason'], true, $canReopen, $isAdmin, $row['expiresAt']),
            'change' => self::changeLabel($row['state'], $canChangeStatus),
        ]);

        // SV6: the rows drawn now are the ones the student actions may name.
        $listIds = $this->tampil === self::VIEW_STUDENTS ? $rows->pluck('userId')->map(intval(...))->values()->all() : [];
        $classIds = $classDialog !== null ? $classDialog['rows']->pluck('userId')->map(intval(...))->values()->all() : [];
        $this->rememberShown([...$listIds, ...$classIds]);

        return view('livewire.akun.status-aktivasi', [
            'totals' => ActivationReport::totals($all),
            'summary' => $summary->take($this->classLimit),
            'classCount' => $summary->count(),
            'classDialog' => $classDialog,
            'openClassOnLoad' => $this->openClassOnLoad && $this->kelas !== '',
            'rows' => $rows,
            'listTicks' => $this->tickState($listIds),
            'classTicks' => $this->tickState($classIds),
            'canTick' => $canChangeStatus || $canTrash,
            'canTrash' => $canTrash,
            'canEditData' => Gate::allows(Permission::StudentUpdate->value),
            'downloadNote' => $this->downloadNote(),
            'downloadOptions' => ExportActivationReport::scopeOptions(),
            'filteredCount' => $filtered->count(),
            // r3: no button at the cap (a click could not show more); the note says how to narrow the list instead.
            'hasMore' => $filtered->count() > $this->limit && $this->limit < self::MAX,
            'capNote' => $filtered->count() > $this->limit && $this->limit >= self::MAX ? self::capNote() : null,
            'classes' => ActivationReport::classOptions()->all(),
            'states' => AccountControls::STATES,
            'frozen' => $frozen->take($this->frozenLimit)->map(fn (array $account): array => $account + [
                'action' => self::rowAction(AccountControls::STATE_FROZEN, $account['reason'], $account['student'], $canReopen, $isAdmin),
            ]),
            'frozenCount' => $frozen->count(),
            'k7' => $k7->take($this->k7Limit),
            'k7Count' => $k7->count(),
            'canReopen' => $canReopen,
            'canUnfreeze' => $isAdmin,
            'canChangeStatus' => $canChangeStatus,
            'statusReturnUrl' => $canChangeStatus ? $this->returnUrl() : null,
            'trashReturnUrl' => $canTrash ? $this->returnUrl() : null,
            'canSetWindow' => Gate::allows(Permission::SettingManage->value),
            'currentWindow' => app(AccountControls::class)->windowDays(),
            'target' => $target === null ? null : [
                'name' => (string) $target->name,
                'classroom' => $target->student?->classroom?->name,
                'reopen' => $this->action === self::ACTION_REOPEN ? self::reopenPreview($target) : null,
            ],
            'filtersActive' => $this->daftarKelas !== '' || $this->status !== '' || trim($this->q) !== '',
        ]);
    }

    /**
     * Content of the class dialog: the students of `$kelas` out of the rows the page already loaded (no extra
     * query), the status tabs that have students, and one step of rows. Null while no class is chosen.
     *
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array{name: string, total: int, tabs: list<array{key: string, label: string, count: int}>, rows: Collection<int, array<string, mixed>>, count: int, hasMore: bool}|null
     */
    private function classDialog(Collection $all, bool $canReopen, bool $canUnfreeze, bool $canChangeStatus = false): ?array
    {
        if ($this->kelas === '') {
            return null;
        }

        $students = $all->where('classroomId', $this->kelas)->values();

        if ($students->isEmpty()) {
            $this->kelas = '';

            return null;
        }

        $this->kelasLimit = max(self::PAGE, min(self::MAX, $this->kelasLimit));
        $totals = ActivationReport::totals($students);

        if (! array_key_exists($this->kelasStatus, AccountControls::STATES)) {
            $this->kelasStatus = '';
        }

        $tabs = [['key' => '', 'label' => 'Semua', 'count' => $students->count()]];

        foreach (AccountControls::STATES as $key => $meta) {
            if ($totals[$key] > 0 || $this->kelasStatus === $key) {
                $tabs[] = ['key' => $key, 'label' => $meta['label'], 'count' => $totals[$key]];
            }
        }

        $shown = $this->kelasStatus === '' ? $students : $students->where('state', $this->kelasStatus)->values();

        return [
            'name' => (string) $students->first()['classroom'],
            'total' => $students->count(),
            'tabs' => $tabs,
            'rows' => $shown->take($this->kelasLimit)->map(fn (array $row): array => $row + [
                'action' => self::rowAction($row['state'], $row['frozenReason'], true, $canReopen, $canUnfreeze, $row['expiresAt']),
                'change' => self::changeLabel($row['state'], $canChangeStatus),
            ]),
            'count' => $shown->count(),
            'hasMore' => $shown->count() > $this->kelasLimit,
        ];
    }

    /** A classroom id that exists and has students (the only ones "Lihat" is offered for). */
    private static function knownClass(string $classroomId): bool
    {
        return $classroomId !== '' && ActivationReport::classOptions()->has($classroomId);
    }

    /** The dialog is closed (or about to be): drop its state so the address and a reload do not reopen it. */
    private function forgetClass(): void
    {
        $this->kelas = '';
        $this->kelasStatus = '';
        $this->kelasLimit = self::PAGE;
    }

    /**
     * Frozen accounts (K3/K8). Kesiswaan sees students only; Admin also staff (K8 can freeze any account).
     *
     * @return Collection<int, array{id: int, name: string, where: string, reason: ?string, reasonText: string, since: ?CarbonImmutable, student: bool}>
     */
    private function frozenAccounts(bool $includeStaff): Collection
    {
        return User::query()
            ->whereNotNull('frozen_at')
            ->when(! $includeStaff, fn ($q) => $q->whereHas('student'))
            ->with('student.classroom:id,name')
            ->orderByDesc('frozen_at')
            ->limit(self::BOX_MAX)
            ->get(['id', 'name', 'status', 'frozen_at', 'frozen_reason'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->getKey(),
                'name' => (string) $u->name,
                'where' => $u->student !== null ? (string) ($u->student->classroom?->name ?? 'Siswa') : 'Staf',
                'reason' => AccountControls::frozenReason($u),
                'reasonText' => self::frozenReasonText(AccountControls::frozenReason($u)),
                'since' => AccountControls::date($u, 'frozen_at'),
                'student' => $u->student !== null,
            ]);
    }

    /** Why an account of the box "Akun dibekukan" is frozen, in words. */
    private static function frozenReasonText(?string $reason): string
    {
        return match ($reason) {
            AccountControls::FROZEN_NOT_ME => 'laporan "Bukan saya"',
            AccountControls::FROZEN_ADMIN => AccountControls::FROZEN_ADMIN_LABEL,
            default => 'gagal masuk '.AccountControls::MAX_FAILED_ATTEMPTS.' kali',
        };
    }

    /**
     * Which button a row gets: 'reopen' (K2: expired, frozen after failed logins, or a window with less than
     * 72 hours left, so the reopen really moves the deadline later; M2-B), 'unfreeze' (Admin, any freeze),
     * 'admin' (frozen by "Bukan saya?" and the viewer is not Admin) or null.
     */
    private static function rowAction(string $state, ?string $frozenReason, bool $student, bool $canReopen, bool $canUnfreeze, ?CarbonImmutable $expiresAt = null): ?string
    {
        $reopenable = $student && match ($state) {
            AccountControls::STATE_EXPIRED => true,
            AccountControls::STATE_FROZEN => $frozenReason === AccountControls::FROZEN_FAILED_ATTEMPTS,
            AccountControls::STATE_PENDING => ReopenActivationWindow::wouldChange($expiresAt, false),
            default => false,
        };

        return match (true) {
            $reopenable && $canReopen => self::ACTION_REOPEN,
            $state === AccountControls::STATE_FROZEN && $canUnfreeze => self::ACTION_UNFREEZE,
            $state === AccountControls::STATE_FROZEN => 'admin',
            default => null,
        };
    }

    /**
     * SV5: the label of a row's "Ubah status" button for Admin ("Aktifkan lagi" for a state that "Aktif" brings back:
     * UbahStatusSiswa::RESTORABLE), null for everybody else (no button).
     */
    private static function changeLabel(string $state, bool $canChangeStatus): ?string
    {
        if (! $canChangeStatus) {
            return null;
        }

        return UbahStatusSiswa::restorable($state) ? 'Aktifkan lagi' : 'Ubah status';
    }

    /**
     * What "Buka ulang" will do for the account in the dialog, so the dialog says it before the click
     * (the rule itself is ReopenActivationWindow::deadlineAfter()).
     *
     * @return array{until: string, kept: bool, unfreezes: bool}
     */
    private static function reopenPreview(User $target): array
    {
        $current = AccountControls::date($target, 'initial_password_expires_at');
        $until = ReopenActivationWindow::deadlineAfter($current);

        return [
            'until' => $until->translatedFormat('j M Y H:i'),
            // The window already runs longer than 72 hours: only the freeze is lifted.
            'kept' => $current !== null && $until->equalTo($current),
            'unfreezes' => AccountControls::isFrozen($target),
        ];
    }

    private function openAction(User $target, string $action): void
    {
        $this->resetErrorBag();
        $this->reset('reason');
        $this->targetId = (int) $target->getKey();
        $this->action = $action;
        $this->dispatch('sth-modal-open', id: 'aktivasi-aksi');
    }

    /**
     * SV6: a student account one of the lists drew during this visit that is still a student (not in the trash). An id
     * never listed (a staff account, an unknown or forged id) is refused with 404; a listed one that went to the trash
     * meanwhile gets a warning toast and null.
     */
    private function listedStudent(int $userId): ?User
    {
        abort_unless(in_array($userId, $this->shownStudents, true), 404);

        /** @var User|null $user */
        $user = User::query()->whereHas('student')->find($userId);

        if ($user === null) {
            $this->dispatch('sth-toast', message: self::MSG_STUDENT_GONE, tone: 'warning');
        }

        return $user;
    }

    /**
     * SV6: the ticked rows (SelectsListedStudents::tickedIds()) when every one is still a student. A ticked student who
     * went to the trash meanwhile drops out of the ticks with a warning toast and null.
     *
     * @return list<int>|null
     */
    private function tickedStudents(): ?array
    {
        $ids = $this->tickedIds();

        if ($ids === null) {
            return null;
        }

        $present = User::query()->whereKey($ids)->whereHas('student')->pluck('id')->map(intval(...))->all();

        if (count($present) !== count($ids)) {
            $this->selectedStudents = array_map(strval(...), array_values(array_intersect($ids, $present)));
            $this->dispatch('sth-toast', message: self::MSG_STUDENTS_GONE, tone: 'warning');

            return null;
        }

        return $ids;
    }

    /**
     * SV6: the student ids the list on screen shows now, in its order (the same rows and limits as render()): the open
     * class dialog, else "Daftar siswa" when that tab is open, else none.
     *
     * @return list<int>
     */
    private function listedIds(ActivationReport $report): array
    {
        if ($this->kelas !== '') {
            $students = $report->rows($this->kelas);
            $rows = ($this->kelasStatus === '' ? $students : $students->where('state', $this->kelasStatus))
                ->take(max(self::PAGE, min(self::MAX, $this->kelasLimit)));
        } elseif ($this->tampil === self::VIEW_STUDENTS) {
            $rows = $report->rows($this->daftarKelas ?: null, $this->status ?: null, $this->q)
                ->take(max(self::PREVIEW, min(self::MAX, $this->limit)));
        } else {
            return [];
        }

        return $rows->pluck('userId')->map(intval(...))->values()->all();
    }

    /**
     * What "Download Excel" next to the tabs writes: the class or tingkat of the chooser (ExportActivationReport::scope());
     * on "Daftar siswa" also its status tab and its search. Null when the chosen class no longer has a student.
     *
     * @return array{classroom: ?string, level: ?int, state: string, search: string}|null
     */
    private function downloadScope(): ?array
    {
        $chosen = ExportActivationReport::scope($this->unduhTingkat);

        if ($chosen === null) {
            return null;
        }

        $list = $this->tampil === self::VIEW_STUDENTS;

        return $chosen + [
            'state' => $list && array_key_exists($this->status, AccountControls::STATES) ? $this->status : '',
            'search' => $list ? trim($this->q) : '',
        ];
    }

    /** SV6: one line under "Download Excel" that says what the file will hold. */
    private function downloadNote(): string
    {
        $scope = $this->downloadScope();
        $parts = [
            ExportActivationReport::scopeLabel($this->unduhTingkat),
            ($scope['state'] ?? '') !== '' ? 'status '.AccountControls::stateLabel($scope['state']) : 'semua status',
        ];

        if (($scope['search'] ?? '') !== '') {
            $parts[] = 'hasil pencarian';
        }

        return 'Isi file: '.implode(', ', $parts).'.';
    }

    /** The finished file, or the warning toast when no student matched (no file, no audit row). */
    private function download(?ExportedFile $file): ?BinaryFileResponse
    {
        if ($file === null) {
            $this->dispatch('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning');

            return null;
        }

        return response()->download($file->path, $file->filename)->deleteFileAfterSend();
    }

    private function studentAccount(int $userId): User
    {
        /** @var User|null $user */
        $user = User::query()->whereHas('student')->find($userId);
        abort_if($user === null, 404);

        return $user;
    }

    private function account(int $userId): User
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);
        abort_if($user === null, 404);

        return $user;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function returnUrl(): string
    {
        // `kelas` is only set while the class dialog is in use (e.g. Admin "Buka pembekuan" from a dialog row).
        $query = array_filter(['kelas' => $this->kelas, 'tampil' => $this->tampil === self::VIEW_STUDENTS ? self::VIEW_STUDENTS : '', 'kelas_daftar' => $this->daftarKelas, 'status' => $this->status, 'q' => $this->q, 'unduh' => $this->unduhTingkat]);

        return $this->returnTo.($query !== [] ? '?'.http_build_query($query) : '');
    }
}
