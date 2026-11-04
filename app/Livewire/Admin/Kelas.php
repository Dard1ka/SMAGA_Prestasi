<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountControls;
use App\Actions\Classrooms\ClassroomName;
use App\Actions\Classrooms\CreateClassroom;
use App\Actions\Classrooms\RenameClassroom;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashClassroom;
use App\Actions\Trash\TrashReason;
use App\Actions\Trash\TrashStudents;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Kelas (`admin.kelas.index`, ClassroomPolicy / `classroom.manage`, Admin only): the classes per tingkat with
 * their active and total students, "Tambah kelas" (CreateClassroom) and "Ganti nama" in a dialog (RenameClassroom, only
 * the part after the dash). The student import puts students only into the classes listed here; it never adds one.
 *
 * "Lihat siswa" (and the class name itself; the lead, 2026-11-04) opens the dialog `kelas-siswa` with the students of
 * that class: name, NISN and account state (K9, AccountControls::state()), by name, STUDENTS_STEP rows at a time.
 * SV5 (the lead, 2026-11-04: "bisa memilih siswa untuk diubah statusnya, apapun, semuanya bisa"): every row has a
 * checkbox and "Ubah status", the head of the list "Pilih semua" (the rows shown) and "Ubah status terpilih (N)". Both
 * open the shared dialog UbahStatusSiswa (since 2026-11-05 the six states of Status aktivasi: Belum aktivasi, Aktif,
 * Kedaluwarsa, Dibekukan, Nonaktif, Diarsipkan; D-180) after askStatus() / askStatusSelected() checked that every id is
 * among the #[Locked] $shownStudents and still in this class. After a change the dialog sends
 * UbahStatusSiswa::DONE_EVENT and this page redraws the list, the chips, the counts and clears the selection.
 *
 * The trash ("Sampah"; the lead's decision of 5 Nov 2026, smaga_server only, replacing the "only an empty class" rule of
 * D-166 here):
 * - "Hapus" on every class row, also one with students: dialog `kelas-hapus` (askDelete() → delete(), TrashClassroom):
 *   the class and every student in it go to the trash; the dialog says how many students and from which date their
 *   data is gone for good;
 * - "Hapus" per row and "Hapus terpilih (N)" in "Lihat siswa": dialog `kelas-siswa-hapus` (askTrashStudent() /
 *   askTrashSelected() → trashStudents(), TrashStudents);
 * - the section "Sampah" below the classes lists the open trash entries (a class with its student count, a student
 *   trashed alone with name and class; who deleted it when, and the purge date), TRASH_STEP at a time, each with
 *   "Pulihkan" behind the dialog `kelas-pulihkan` (askRestore() → restore(), RestoreFromTrash).
 * Every trash and restore needs a reason where asked (TrashReason) and a password confirmation from the last 15 minutes
 * (RequiresRecentPassword, SECURITY §3m-9), checked when asking and again when confirming.
 *
 * Stale screens (CLAUDE.md §6): a class another tab renamed or put in the trash meanwhile, a student who left the class
 * or went to the trash, an entry restored or purged elsewhere: a warning toast and the redrawn list, never a 403/404
 * page. A student id this visit never listed (a staff account, a student of another class) and an entry id that never
 * existed are refused with 404. The dialogs keep what they ask in #[Locked] properties; the confirm methods do nothing
 * without them (D-128 pattern).
 */
#[Title('Kelas')]
#[Layout('layouts::app', ['subtitle' => 'Daftar kelas yang dipakai import siswa.'])]
final class Kelas extends Component
{
    use RequiresRecentPassword;

    public const RENAME_DIALOG = 'kelas-ganti-nama';

    public const DELETE_DIALOG = 'kelas-hapus';

    public const STUDENTS_DIALOG = 'kelas-siswa';

    public const TRASH_STUDENTS_DIALOG = 'kelas-siswa-hapus';

    public const RESTORE_DIALOG = 'kelas-pulihkan';

    /** Students of the open class before the first "Tampilkan lebih banyak" (about one class), and the step after. */
    public const STUDENTS_STEP = 40;

    /** Upper bound of the student list (alumni stay in their last class, so a class can collect rows over the years). */
    public const STUDENTS_MAX = 400;

    /** Entries of the Sampah section before the first "Tampilkan lebih banyak", and the step after. */
    public const TRASH_STEP = 20;

    public const TRASH_MAX = 200;

    public const MSG_GONE = 'Kelas ini sudah dihapus. Daftar sudah diperbarui.';

    public const MSG_RENAMED = 'Nama kelas ini sudah diganti. Daftar sudah diperbarui.';

    public const MSG_CLASS_CHANGED = 'Isi kelas ini sudah berubah. Daftar sudah diperbarui.';

    public const MSG_STUDENT_MOVED = 'Siswa ini sudah tidak ada di kelas ini. Daftar sudah diperbarui.';

    public const MSG_STUDENTS_MOVED = 'Sebagian siswa yang dipilih sudah tidak ada di kelas ini. Daftar dan pilihan sudah diperbarui.';

    public const MSG_NONE_SELECTED = 'Pilih siswa dulu.';

    public const MSG_ENTRY_CLOSED = 'Data ini sudah dipulihkan atau sudah terhapus permanen. Daftar sudah diperbarui.';

    /** "Tambah kelas": tingkat (level 10/11/12) and the part after the dash. Names match the Action's error keys. */
    public string $level = '10';

    public string $suffix = '';

    /** Class in the rename dialog and the name the dialog showed; set only by askRename(). */
    #[Locked]
    public ?string $renamingId = null;

    #[Locked]
    public ?string $renamingName = null;

    public string $renameSuffix = '';

    /** Class in the delete dialog, the name and the student count it showed; set only by askDelete(). */
    #[Locked]
    public ?string $deletingId = null;

    #[Locked]
    public ?string $deletingName = null;

    #[Locked]
    public ?int $deletingStudents = null;

    /** Reason typed in the delete dialog. */
    public string $classReason = '';

    /** Class in the student dialog; set only by showStudents(), emptied when that dialog closes. */
    #[Locked]
    public ?string $viewingId = null;

    /** Rows of the student dialog (clamped in render()). */
    public int $studentLimit = self::STUDENTS_STEP;

    /**
     * @var list<int> user ids the student dialog listed for the open class during this visit (filled in render()): an
     *                id among them that changed since gets a toast, any other id is refused (a forged call)
     */
    #[Locked]
    public array $shownStudents = [];

    /**
     * @var list<string> ticked rows of the student dialog (checkbox values, so strings); render() counts only the rows it
     *                   shows, askStatusSelected() / askTrashSelected() check them against $shownStudents and the class
     */
    public array $selectedStudents = [];

    /** @var list<int> students the "Hapus N siswa?" dialog asks about; set only by askTrashStudent() / askTrashSelected() */
    #[Locked]
    public array $trashingStudents = [];

    /** The class those students were in when asked. */
    #[Locked]
    public ?string $trashingClassId = null;

    /** Reason typed in the "Hapus N siswa?" dialog. */
    public string $studentsReason = '';

    /** Trash entry in the restore dialog; set only by askRestore(). */
    #[Locked]
    public ?string $restoringId = null;

    /** Rows of the Sampah section (clamped in render()). */
    public int $trashLimit = self::TRASH_STEP;

    public function mount(): void
    {
        Gate::authorize('viewAny', Classroom::class);
    }

    public function add(CreateClassroom $create): void
    {
        Gate::authorize('create', Classroom::class);

        // A ValidationException with the keys `level` / `suffix` lands under the two fields.
        $classroom = $create->handle($this->actor(), (int) $this->level, $this->suffix);

        $this->reset('suffix');
        $this->resetErrorBag();
        $this->dispatch('sth-toast', message: "Kelas {$classroom->name} ditambahkan.", tone: 'success');
    }

    public function askRename(string $id): void
    {
        $this->reset('renamingId', 'renamingName', 'renameSuffix');
        $this->resetErrorBag();
        $classroom = Classroom::query()->find($id);

        if ($classroom === null) {
            $this->stale(self::MSG_GONE);

            return;
        }

        Gate::authorize('update', $classroom);

        $this->renamingId = (string) $classroom->getKey();
        $this->renamingName = $classroom->name;
        $this->renameSuffix = ClassroomName::partOf($classroom->name);
        $this->dispatch('sth-modal-open', id: self::RENAME_DIALOG);
    }

    public function rename(RenameClassroom $rename): void
    {
        if ($this->renamingId === null) {
            return;
        }

        $classroom = Classroom::query()->find($this->renamingId);

        if ($classroom === null || $classroom->name !== $this->renamingName) {
            $this->closeRename();
            $this->stale($classroom === null ? self::MSG_GONE : self::MSG_RENAMED);

            return;
        }

        Gate::authorize('update', $classroom);

        try {
            $renamed = $rename->handle($this->actor(), $classroom, $this->renameSuffix);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'renameSuffix' => collect($e->errors())->flatten()->first() ?? ClassroomName::MSG_CHARS,
            ]);
        }

        $this->closeRename();
        $this->dispatch('sth-toast', message: "Nama kelas diganti menjadi {$renamed->name}.", tone: 'success');
    }

    // Trash: a class ---------------------------------------------------------------------------

    /** "Hapus" on a class row: the question "Hapus kelas X-4?" with its student count. */
    public function askDelete(string $id): void
    {
        $this->forgetDelete();
        $classroom = Classroom::query()->find($id);

        if ($classroom === null) {
            $this->stale(self::MSG_GONE);

            return;
        }

        Gate::authorize('delete', $classroom);
        Gate::authorize('create', TrashEntry::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->deletingId = (string) $classroom->getKey();
        $this->deletingName = $classroom->name;
        $this->deletingStudents = self::studentsIn($classroom);
        $this->dispatch('sth-modal-open', id: self::DELETE_DIALOG);
    }

    /**
     * "Hapus kelas" in the dialog. Without a question asked by askDelete() there is nothing to confirm: it returns
     * without a word, so a second click after the delete changes and says nothing.
     */
    public function delete(TrashClassroom $trash): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $classroom = Classroom::query()->find($this->deletingId);
        $stale = match (true) {
            $classroom === null => self::MSG_GONE,
            $classroom->name !== $this->deletingName => self::MSG_RENAMED,
            self::studentsIn($classroom) !== $this->deletingStudents => self::MSG_CLASS_CHANGED,
            default => null,
        };

        if ($stale !== null || $classroom === null) {
            $this->closeDelete();
            $this->stale($stale ?? self::MSG_GONE);

            return;
        }

        Gate::authorize('delete', $classroom);
        Gate::authorize('create', TrashEntry::class);

        // Under the field; the dialog stays open.
        $reason = TrashReason::validated($this->classReason, 'classReason');

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $name = (string) $this->deletingName;
        $students = (int) $this->deletingStudents;

        try {
            $trash->handle($this->actor(), $classroom, $reason);
        } catch (ValidationException $e) {
            $this->closeDelete();
            $this->stale((string) (collect($e->errors())->flatten()->first() ?? self::MSG_GONE));

            return;
        }

        $this->closeDelete();
        $this->dispatch('sth-toast', message: $students > 0
            ? "Kelas {$name} dan {$students} siswanya dipindahkan ke Sampah."
            : "Kelas {$name} dipindahkan ke Sampah.", tone: 'success');
    }

    /** The delete dialog closed (Batal, X, Esc, a click outside): forget the question and the reason. */
    public function forgetDelete(): void
    {
        $this->reset('deletingId', 'deletingName', 'deletingStudents', 'classReason');
        $this->resetErrorBag('classReason');
    }

    // Students of a class ------------------------------------------------------------------------

    /** "Lihat siswa" or the class name: the dialog with the students of that class. */
    public function showStudents(string $id): void
    {
        $this->reset('viewingId', 'shownStudents', 'studentLimit', 'selectedStudents');
        $classroom = Classroom::query()->find($id);

        if ($classroom === null) {
            $this->stale(self::MSG_GONE);

            return;
        }

        Gate::authorize('viewAny', Classroom::class);
        Gate::authorize('viewAny', User::class);

        $this->viewingId = (string) $classroom->getKey();
        $this->dispatch('sth-modal-open', id: self::STUDENTS_DIALOG);
    }

    public function showMoreStudents(): void
    {
        $this->studentLimit = min(self::STUDENTS_MAX, $this->studentLimit + self::STUDENTS_STEP);
    }

    /** The student dialog closed (Tutup, X, Esc, a click outside): forget the class, what it listed and the ticks. */
    public function closeStudents(): void
    {
        $this->reset('viewingId', 'shownStudents', 'studentLimit', 'selectedStudents');
        $this->resetErrorBag();
    }

    /** "Pilih semua": ticks every row the dialog lists, or clears the ticks when they are all ticked already. */
    public function toggleAllStudents(): void
    {
        abort_if($this->viewingId === null, 404);
        Gate::authorize('viewAny', Classroom::class);

        // The rows the list shows now (the same query and limit as render()), never more than this visit listed.
        $listed = $this->studentsInClass($this->viewingId)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(max(self::STUDENTS_STEP, min(self::STUDENTS_MAX, $this->studentLimit)))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter(fn (string $id): bool => in_array((int) $id, $this->shownStudents, true))
            ->values()
            ->all();

        $this->selectedStudents = $listed !== [] && array_diff($listed, $this->selectedStudents) === [] ? [] : $listed;
    }

    /** "Ubah status" of one row: the shared dialog for that student. */
    public function askStatus(int $userId): void
    {
        $student = $this->listedStudent($userId);

        if ($student === null) {
            return;
        }

        $this->openStatusDialog([(int) $student->getKey()]);
    }

    /** "Ubah status terpilih (N)": the shared dialog for the ticked rows. */
    public function askStatusSelected(): void
    {
        $ids = $this->selectedInClass();

        if ($ids !== null) {
            $this->openStatusDialog($ids);
        }
    }

    /** The shared dialog changed something (or found nothing left to change): redraw and clear the ticks. */
    #[On(UbahStatusSiswa::DONE_EVENT)]
    public function statusChanged(): void
    {
        $this->reset('selectedStudents');
    }

    // Trash: students ----------------------------------------------------------------------------

    /** "Hapus" of one row in "Lihat siswa": the question "Hapus 1 siswa?". */
    public function askTrashStudent(int $userId): void
    {
        $student = $this->listedStudent($userId);

        if ($student === null) {
            return;
        }

        $this->openTrashStudents([(int) $student->getKey()]);
    }

    /** "Hapus terpilih (N)": the question "Hapus N siswa?" for the ticked rows. */
    public function askTrashSelected(): void
    {
        $ids = $this->selectedInClass();

        if ($ids !== null) {
            $this->openTrashStudents($ids);
        }
    }

    /**
     * "Hapus siswa" in the dialog. Without a question asked by askTrashStudent() / askTrashSelected() there is nothing
     * to confirm: it returns without a word.
     */
    public function trashStudents(TrashStudents $trash): void
    {
        if ($this->trashingStudents === [] || $this->trashingClassId === null) {
            return;
        }

        $ids = $this->trashingStudents;

        // Every asked student still in that class and not in the trash (another tab, the import, a colleague).
        if ($this->studentsInClass($this->trashingClassId)->whereKey($ids)->count() !== count($ids)) {
            $this->closeTrashStudents();
            $this->reset('selectedStudents');
            $this->stale(self::MSG_STUDENTS_MOVED);

            return;
        }

        Gate::authorize('create', TrashEntry::class);

        $reason = TrashReason::validated($this->studentsReason, 'studentsReason');

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $entries = $trash->handle($this->actor(), $ids, $reason);
        } catch (ValidationException $e) {
            $this->closeTrashStudents();
            $this->reset('selectedStudents');
            $this->stale((string) (collect($e->errors())->flatten()->first() ?? self::MSG_STUDENTS_MOVED));

            return;
        }

        $this->closeTrashStudents();
        $this->reset('selectedStudents');
        $this->dispatch('sth-toast', message: count($entries).' siswa dipindahkan ke Sampah.', tone: 'success');
    }

    /** The "Hapus N siswa?" dialog closed: forget the question and the reason. */
    public function forgetTrashStudents(): void
    {
        $this->reset('trashingStudents', 'trashingClassId', 'studentsReason');
        $this->resetErrorBag('studentsReason');
    }

    // Trash: restore -----------------------------------------------------------------------------

    /** "Pulihkan" on a Sampah row: the short question. */
    public function askRestore(string $id): void
    {
        $this->forgetRestore();
        $entry = TrashEntry::query()->find($id);

        // Entries are never deleted: an unknown id was never listed.
        abort_if($entry === null, 404);
        Gate::authorize('restore', $entry);

        $refusal = $this->restoreRefusal($entry);

        if ($refusal !== null) {
            $this->stale($refusal);

            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->restoringId = (string) $entry->getKey();
        $this->dispatch('sth-modal-open', id: self::RESTORE_DIALOG);
    }

    /** "Pulihkan" in the dialog; a silent no-op without the question of askRestore(). */
    public function restore(RestoreFromTrash $restore): void
    {
        if ($this->restoringId === null) {
            return;
        }

        $entry = TrashEntry::query()->with(['classroom:id,name,deleted_at', 'user:id,name,deleted_at'])->find($this->restoringId);
        abort_if($entry === null, 404);
        Gate::authorize('restore', $entry);

        $refusal = $this->restoreRefusal($entry);

        if ($refusal !== null) {
            $this->closeRestore();
            $this->stale($refusal);

            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $students = $restore->handle($this->actor(), $entry);
        } catch (ValidationException $e) {
            $this->closeRestore();
            $this->stale((string) (collect($e->errors())->flatten()->first() ?? self::MSG_ENTRY_CLOSED));

            return;
        }

        $this->closeRestore();
        $this->dispatch('sth-toast', message: $entry->isClassroom()
            ? 'Kelas '.$entry->classroom?->name.($students > 0 ? " dan {$students} siswanya" : '').' dipulihkan.'
            : ($entry->user?->name ?? 'Siswa').' dipulihkan.', tone: 'success');
    }

    /** The restore dialog closed: forget the question. */
    public function forgetRestore(): void
    {
        $this->reset('restoringId');
    }

    public function showMoreTrash(): void
    {
        $this->trashLimit = min(self::TRASH_MAX, $this->trashLimit + self::TRASH_STEP);
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Classroom::class);

        $classes = Classroom::query()
            ->ordered()
            ->withCount([
                // Every student row that is not in the trash (archived and disabled ones too).
                'students as total_students',
                'students as active_students' => fn (Builder $q) => $q->active(),
            ])
            ->get();

        $groups = [];

        foreach ($classes as $classroom) {
            $level = $classroom->level;
            $groups[$level] ??= [
                'level' => $level,
                'label' => 'Kelas '.(ClassroomName::TINGKAT[$level] ?? (string) $level),
                'rows' => [],
                'active' => 0,
            ];
            $groups[$level]['rows'][] = [
                'id' => (string) $classroom->getKey(),
                'name' => $classroom->name,
                'enabled' => $classroom->active,
                'active' => (int) $classroom->getAttribute('active_students'),
                'total' => (int) $classroom->getAttribute('total_students'),
            ];
            $groups[$level]['active'] += (int) $classroom->getAttribute('active_students');
        }

        $renaming = $this->renamingId !== null ? Classroom::query()->find($this->renamingId) : null;
        $this->studentLimit = max(self::STUDENTS_STEP, min(self::STUDENTS_MAX, $this->studentLimit));
        $this->trashLimit = max(self::TRASH_STEP, min(self::TRASH_MAX, $this->trashLimit));
        $studentsDialog = $this->studentsDialog();
        $purgeDate = TrashEntry::purgeAfterFor(CarbonImmutable::now())->translatedFormat('j F Y');

        return view('livewire.admin.kelas', [
            'groups' => array_values($groups),
            'levelOptions' => array_map(strval(...), ClassroomName::TINGKAT),
            'renamingPrefix' => $renaming !== null ? (ClassroomName::TINGKAT[$renaming->level] ?? null) : null,
            'renamingName' => $renaming?->name,
            'deleteDialog' => $this->deleteDialog($purgeDate),
            'studentsDialog' => $studentsDialog,
            'trashStudentsDialog' => $this->trashStudentsDialog($purgeDate),
            'trash' => $this->trashList(),
            'restoreDialog' => $this->restoreDialog(),
            'trashReasonMin' => TrashReason::MIN,
            'trashReasonMax' => TrashReason::MAX,
        ]);
    }

    /**
     * View model of the delete dialog ("Hapus kelas X-4?"), null without a question.
     *
     * @return array{title: string, lines: list<string>, students: int}|null
     */
    private function deleteDialog(string $purgeDate): ?array
    {
        if ($this->deletingId === null) {
            return null;
        }

        $students = (int) $this->deletingStudents;

        return [
            'title' => 'Hapus kelas '.$this->deletingName.'?',
            'students' => $students,
            'lines' => $students > 0
                ? [
                    "Kelas ini berisi {$students} siswa. Kelas dan semua siswanya dipindahkan ke Sampah: siswanya langsung tidak bisa masuk dan tidak tampil di mana pun.",
                    'Selama '.TrashEntry::KEEP_DAYS.' hari masih bisa dipulihkan. Mulai '.$purgeDate.' kelas dan seluruh data siswanya (akun, pendaftaran, surat tugas, laporan hasil, prestasi, talenta, file) terhapus permanen.',
                ]
                : [
                    'Kelas ini belum berisi siswa. Kelas dipindahkan ke Sampah dan tidak bisa dipakai import siswa.',
                    'Selama '.TrashEntry::KEEP_DAYS.' hari masih bisa dipulihkan. Mulai '.$purgeDate.' kelas ini terhapus permanen.',
                ],
        ];
    }

    /**
     * View model of the "Hapus N siswa?" dialog, null without a question.
     *
     * @return array{title: string, names: string, classroom: ?string, lines: list<string>}|null
     */
    private function trashStudentsDialog(string $purgeDate): ?array
    {
        if ($this->trashingStudents === []) {
            return null;
        }

        $count = count($this->trashingStudents);
        $users = User::query()->whereKey($this->trashingStudents)->orderBy('name')->orderBy('id')->limit(3)->pluck('name');
        $names = $users->implode(', ').($count > 3 ? ', dan '.($count - 3).' lainnya' : '');

        return [
            'title' => 'Hapus '.$count.' siswa?',
            'names' => $names,
            'classroom' => $this->trashingClassId !== null ? Classroom::query()->whereKey($this->trashingClassId)->value('name') : null,
            'lines' => [
                ($count === 1 ? 'Siswa ini' : 'Semua siswa yang dipilih').' dipindahkan ke Sampah: langsung tidak bisa masuk dan tidak tampil di mana pun.',
                'Selama '.TrashEntry::KEEP_DAYS.' hari masih bisa dipulihkan. Mulai '.$purgeDate.' akun dan seluruh data siswanya (pendaftaran, surat tugas, laporan hasil, prestasi, talenta, file) terhapus permanen.',
            ],
        ];
    }

    /**
     * View model of the restore dialog, null without a question (or when the entry vanished meanwhile).
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function restoreDialog(): ?array
    {
        if ($this->restoringId === null) {
            return null;
        }

        $entry = TrashEntry::query()
            ->with(['classroom:id,name,deleted_at', 'user:id,name,deleted_at'])
            ->withCount(['children' => fn (Builder $q) => $q->open()])
            ->find($this->restoringId);

        if ($entry === null) {
            return null;
        }

        if ($entry->isClassroom()) {
            $students = (int) $entry->getAttribute('children_count');

            return [
                'title' => 'Pulihkan kelas '.$entry->classroom?->name.'?',
                'lines' => [
                    $students > 0
                        ? "Kelas dan {$students} siswanya kembali seperti sebelum dihapus. Siswanya bisa masuk lagi dengan kata sandi yang sama; status akunnya tidak berubah."
                        : 'Kelas kembali ke daftar dan bisa dipakai import siswa lagi.',
                ],
            ];
        }

        return [
            'title' => 'Pulihkan '.($entry->user?->name ?? 'siswa ini').'?',
            'lines' => [
                'Siswa kembali ke kelas '.($entry->classroom?->name ?? '–').' seperti sebelum dihapus. Siswa bisa masuk lagi dengan kata sandi yang sama; status akunnya tidak berubah.',
            ],
        ];
    }

    /**
     * View model of the Sampah section: open entries that are not a student trashed with a class (those are counted
     * under the class), newest first, whose class / student is still in the trash.
     *
     * @return array{total: int, rows: list<array<string, mixed>>, hasMore: bool, capped: bool}
     */
    private function trashList(): array
    {
        $query = fn (): Builder => TrashEntry::query()
            ->open()
            ->whereNull('parent_id')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $c) => $c->where('kind', TrashEntry::KIND_CLASSROOM)
                    ->whereHas('classroom', fn (Builder $r) => $r->whereNotNull('classrooms.deleted_at')))
                ->orWhere(fn (Builder $s) => $s->where('kind', TrashEntry::KIND_STUDENT)
                    ->whereHas('user', fn (Builder $u) => $u->whereNotNull('users.deleted_at'))));

        $now = CarbonImmutable::now();
        $entries = $query()
            ->with(['classroom:id,name,deleted_at', 'user:id,name,deleted_at', 'deleter:id,name'])
            ->withCount('children')
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->limit($this->trashLimit + 1)
            ->get();
        $hasMore = $entries->count() > $this->trashLimit;

        $rows = $entries->take($this->trashLimit)->map(function (TrashEntry $entry) use ($now): array {
            $classroom = $entry->classroom?->name ?? '–';
            $students = (int) $entry->getAttribute('children_count');

            return [
                'id' => (string) $entry->getKey(),
                'kind' => $entry->kind,
                'title' => $entry->isClassroom() ? 'Kelas '.$classroom : (string) ($entry->user?->name ?? 'Siswa'),
                'meta' => $entry->isClassroom()
                    ? ($students > 0 ? $students.' siswa ikut dihapus' : 'Tanpa siswa')
                    : 'Kelas '.$classroom,
                'deleted' => 'Dihapus '.$entry->deleted_at->translatedFormat('j M Y, H.i').' oleh '.($entry->deleter?->name ?? 'Admin'),
                'reason' => $entry->reason,
                'purge' => $entry->isRestorable($now)
                    ? 'Terhapus permanen '.$entry->purge_after->translatedFormat('j F Y')
                    : 'Menunggu dihapus permanen',
                'restorable' => $entry->isRestorable($now),
            ];
        })->values()->all();

        return [
            'total' => $query()->count(),
            'rows' => $rows,
            'hasMore' => $hasMore,
            'capped' => $hasMore && $this->trashLimit >= self::TRASH_MAX,
        ];
    }

    /**
     * Why "Pulihkan" cannot run for this entry now (a sentence for the toast), or null.
     */
    private function restoreRefusal(TrashEntry $entry): ?string
    {
        if (! $entry->isOpen()) {
            return self::MSG_ENTRY_CLOSED;
        }

        if (! $entry->isRestorable()) {
            return RestoreFromTrash::MSG_EXPIRED;
        }

        if (! $entry->isClassroom()) {
            $student = Student::withTrashed()->where('user_id', $entry->user_id)->first(['id', 'classroom_id']);
            $classroom = $student !== null ? Classroom::withTrashed()->find($student->classroom_id, ['id', 'name', 'deleted_at']) : null;

            if ($classroom !== null && $classroom->trashed()) {
                return RestoreFromTrash::classFirst($classroom->name);
            }
        }

        return null;
    }

    /**
     * View model of the student dialog: null while it is closed or when its class was deleted meanwhile. Adds the
     * listed ids to $shownStudents; counts only the ticks on listed rows.
     *
     * @return array{name: string, count: int, active: int, rows: list<array{id: int, name: string, nisn: ?string, state: string, status: string, tone: string}>, hasMore: bool, capped: bool, selected: int, allSelected: bool}|null
     */
    private function studentsDialog(): ?array
    {
        if ($this->viewingId === null) {
            return null;
        }

        $classroom = Classroom::query()->find($this->viewingId);

        if ($classroom === null) {
            $this->reset('selectedStudents');

            return null;
        }

        $inClass = fn (): Builder => $this->studentsInClass((string) $classroom->getKey());
        $users = $inClass()
            ->with('student:id,user_id,nisn')
            ->orderBy('name')
            ->orderBy('id')
            ->limit($this->studentLimit + 1)
            ->get(['id', 'name', 'status', 'must_change_password', 'initial_password_expires_at', 'frozen_at', 'frozen_reason']);
        $hasMore = $users->count() > $this->studentLimit;

        $rows = $users->take($this->studentLimit)->map(function (User $user): array {
            $state = AccountControls::state($user);

            return [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'nisn' => $user->student?->nisn,
                'state' => $state,
                'status' => AccountControls::stateLabel($state),
                'tone' => AccountControls::stateTone($state),
            ];
        })->values()->all();

        $this->shownStudents = array_values(array_unique([...$this->shownStudents, ...array_column($rows, 'id')]));

        // Only ticks on rows the list shows count; askStatusSelected() / askTrashSelected() refuse any other id (404) or
        // drop a student who left the class meanwhile (toast).
        $listed = array_map(strval(...), array_column($rows, 'id'));
        $selected = array_values(array_intersect(array_map(strval(...), $this->selectedStudents), $listed));

        return [
            'name' => $classroom->name,
            'count' => $inClass()->count(),
            'active' => $inClass()->canLogIn()->count(),
            'rows' => $rows,
            'hasMore' => $hasMore,
            'capped' => $hasMore && $this->studentLimit >= self::STUDENTS_MAX,
            'selected' => count($selected),
            'allSelected' => $listed !== [] && array_diff($listed, $selected) === [],
        ];
    }

    /**
     * The ticked rows of the student dialog when every one is a listed student still in the open class. A tick this
     * visit never listed (or a malformed one) is refused with 404; nothing ticked or a student who left the class meanwhile
     * gets a warning toast and null.
     *
     * @return list<int>|null
     */
    private function selectedInClass(): ?array
    {
        abort_if($this->viewingId === null, 404);
        Gate::authorize('viewAny', Classroom::class);
        Gate::authorize('viewAny', User::class);

        $ids = [];

        foreach ($this->selectedStudents as $value) {
            abort_unless(is_int($value) || (is_string($value) && ctype_digit($value)), 404);
            $ids[] = (int) $value;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            $this->dispatch('sth-toast', message: self::MSG_NONE_SELECTED, tone: 'warning');

            return null;
        }

        // A tick on a row this visit never listed is forged.
        abort_if(array_diff($ids, $this->shownStudents) !== [], 404);

        $inClass = $this->studentsInClass($this->viewingId)
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($inClass) !== count($ids)) {
            $this->selectedStudents = array_map(strval(...), array_values(array_intersect($ids, $inClass)));
            $this->stale(self::MSG_STUDENTS_MOVED);

            return null;
        }

        return $ids;
    }

    /**
     * A student account the student dialog listed and that is still in the open class (and not in the trash). An id
     * this visit never listed is refused (404); a listed student who left the class gets a warning toast and null.
     */
    private function listedStudent(int $userId): ?User
    {
        abort_unless($this->viewingId !== null && in_array($userId, $this->shownStudents, true), 404);
        Gate::authorize('viewAny', Classroom::class);
        Gate::authorize('viewAny', User::class);

        /** @var User|null $user */
        $user = User::query()->whereHas('student')->with('student:id,user_id,classroom_id')->find($userId);

        if ($user === null || $user->student === null || (string) $user->student->classroom_id !== $this->viewingId) {
            $this->stale(self::MSG_STUDENT_MOVED);

            return null;
        }

        return $user;
    }

    /** @return Builder<User> the accounts of the students of one class (Student and User exclude the trash) */
    private function studentsInClass(string $classroomId): Builder
    {
        return User::query()->whereHas('student', fn (Builder $q) => $q->where('classroom_id', $classroomId));
    }

    /** @param  list<int>  $ids */
    private function openStatusDialog(array $ids): void
    {
        $this->dispatch(UbahStatusSiswa::OPEN_EVENT, ids: $ids)->to(UbahStatusSiswa::class);
    }

    /** @param  list<int>  $ids */
    private function openTrashStudents(array $ids): void
    {
        Gate::authorize('create', TrashEntry::class);
        $this->forgetTrashStudents();

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->trashingStudents = $ids;
        $this->trashingClassId = $this->viewingId;
        $this->dispatch('sth-modal-open', id: self::TRASH_STUDENTS_DIALOG);
    }

    /** Student rows of the class that are not in the trash, any status (what TrashClassroom takes along). */
    private static function studentsIn(Classroom $classroom): int
    {
        return Student::query()->where('classroom_id', $classroom->getKey())->count();
    }

    private function closeRename(): void
    {
        $this->reset('renamingId', 'renamingName', 'renameSuffix');
        $this->resetErrorBag();
        $this->dispatch('sth-modal-close', id: self::RENAME_DIALOG);
    }

    private function closeDelete(): void
    {
        $this->forgetDelete();
        $this->dispatch('sth-modal-close', id: self::DELETE_DIALOG);
    }

    private function closeTrashStudents(): void
    {
        $this->forgetTrashStudents();
        $this->dispatch('sth-modal-close', id: self::TRASH_STUDENTS_DIALOG);
    }

    private function closeRestore(): void
    {
        $this->forgetRestore();
        $this->dispatch('sth-modal-close', id: self::RESTORE_DIALOG);
    }

    private function stale(string $message): void
    {
        $this->dispatch('sth-toast', message: $message, tone: 'warning');
    }

    protected function returnUrl(): string
    {
        return route('admin.kelas.index');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
