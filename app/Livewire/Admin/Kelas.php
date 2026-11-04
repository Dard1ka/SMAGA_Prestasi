<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountControls;
use App\Actions\Classrooms\ClassroomName;
use App\Actions\Classrooms\CreateClassroom;
use App\Actions\Classrooms\DeleteClassroom;
use App\Actions\Classrooms\RenameClassroom;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Kelas (`admin.kelas.index`, ClassroomPolicy / `classroom.manage`, Admin only): the classes per tingkat with
 * their active and total students, "Tambah kelas" (CreateClassroom), "Ganti nama" in a dialog (RenameClassroom, only
 * the part after the dash) and "Hapus" behind a question (DeleteClassroom, only a class without any student row). The
 * student import puts students only into the classes listed here; it never adds or deletes one.
 *
 * "Lihat siswa" (and the class name itself; the lead, 2026-11-04) opens the dialog `kelas-siswa` with the students of
 * that class: name, NISN and account state (K9, AccountControls::state()), by name, STUDENTS_STEP rows at a time.
 * SV5 (the lead, 2026-11-04: "bisa memilih siswa untuk diubah statusnya, apapun, semuanya bisa"): every row has a
 * checkbox and "Ubah status", the head of the list "Pilih semua" (the rows shown) and "Ubah status terpilih (N)". Both
 * open the shared dialog UbahStatusSiswa (Aktif kembali / Nonaktif / Arsip (alumni), one reason, a new archive date when
 * needed, password confirmation; it replaces SV4's "Nonaktifkan" dialog), after askStatus() / askStatusSelected() checked
 * that every id is among the #[Locked] $shownStudents and still in this class. After a change the dialog sends
 * UbahStatusSiswa::DONE_EVENT and this page redraws the list, the chips, the counts and clears the selection.
 *
 * Stale screens (CLAUDE.md §6): a class another tab renamed or deleted meanwhile answers with a warning toast and the
 * redrawn list, never a 403/404 page; so does a listed student who moved to another class meanwhile (a student whose
 * state changed elsewhere is handled by the dialog, which reads every student again). A student id this visit never
 * listed (a staff account, a student of another class) is refused with 404. The dialogs keep what they ask in #[Locked]
 * properties; the confirm methods do nothing without them (D-128 pattern).
 */
#[Title('Kelas')]
#[Layout('layouts::app', ['subtitle' => 'Daftar kelas yang dipakai import siswa.'])]
final class Kelas extends Component
{
    public const RENAME_DIALOG = 'kelas-ganti-nama';

    public const DELETE_DIALOG = 'kelas-hapus';

    public const STUDENTS_DIALOG = 'kelas-siswa';

    /** Students of the open class before the first "Tampilkan lebih banyak" (about one class), and the step after. */
    public const STUDENTS_STEP = 40;

    /** Upper bound of the student list (alumni stay in their last class, so a class can collect rows over the years). */
    public const STUDENTS_MAX = 400;

    public const MSG_GONE = 'Kelas ini sudah dihapus. Daftar sudah diperbarui.';

    public const MSG_RENAMED = 'Nama kelas ini sudah diganti. Daftar sudah diperbarui.';

    public const MSG_STUDENT_MOVED = 'Siswa ini sudah tidak ada di kelas ini. Daftar sudah diperbarui.';

    public const MSG_STUDENTS_MOVED = 'Sebagian siswa yang dipilih sudah tidak ada di kelas ini. Daftar dan pilihan sudah diperbarui.';

    public const MSG_NONE_SELECTED = 'Pilih siswa dulu.';

    /** "Tambah kelas": tingkat (level 10/11/12) and the part after the dash. Names match the Action's error keys. */
    public string $level = '10';

    public string $suffix = '';

    /** Class in the rename dialog and the name the dialog showed; set only by askRename(). */
    #[Locked]
    public ?string $renamingId = null;

    #[Locked]
    public ?string $renamingName = null;

    public string $renameSuffix = '';

    /** Class in the delete dialog and the name it showed; set only by askDelete(). */
    #[Locked]
    public ?string $deletingId = null;

    #[Locked]
    public ?string $deletingName = null;

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
     *                   shows, askStatusSelected() checks them against $shownStudents and the class
     */
    public array $selectedStudents = [];

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

    public function askDelete(string $id): void
    {
        $this->reset('deletingId', 'deletingName');
        $classroom = Classroom::query()->find($id);

        if ($classroom === null) {
            $this->stale(self::MSG_GONE);

            return;
        }

        Gate::authorize('delete', $classroom);

        $students = self::studentRows($classroom);

        // The button is disabled for such a class; a list drawn before the import filled it says so here.
        if ($students > 0) {
            $this->dispatch('sth-toast', message: DeleteClassroom::refusal($students), tone: 'warning');

            return;
        }

        $this->deletingId = (string) $classroom->getKey();
        $this->deletingName = $classroom->name;
        $this->dispatch('sth-modal-open', id: self::DELETE_DIALOG);
    }

    /**
     * "Hapus kelas" in the dialog. Without a question asked by askDelete() there is nothing to confirm: it returns
     * without a word, so a second click after the delete changes and says nothing.
     */
    public function delete(DeleteClassroom $delete): void
    {
        $id = $this->deletingId;
        $shown = $this->deletingName;
        $this->reset('deletingId', 'deletingName');
        $this->dispatch('sth-modal-close', id: self::DELETE_DIALOG);

        if ($id === null) {
            return;
        }

        $classroom = Classroom::query()->find($id);

        if ($classroom === null || $classroom->name !== $shown) {
            $this->stale($classroom === null ? self::MSG_GONE : self::MSG_RENAMED);

            return;
        }

        Gate::authorize('delete', $classroom);

        try {
            $delete->handle($this->actor(), $classroom);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: collect($e->errors())->flatten()->first() ?? 'Kelas tidak bisa dihapus.', tone: 'warning');

            return;
        }

        $this->dispatch('sth-toast', message: "Kelas {$shown} dihapus.", tone: 'success');
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

            return;
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

            return;
        }

        $this->openStatusDialog($ids);
    }

    /** The shared dialog changed something (or found nothing left to change): redraw and clear the ticks. */
    #[On(UbahStatusSiswa::DONE_EVENT)]
    public function statusChanged(): void
    {
        $this->reset('selectedStudents');
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Classroom::class);

        $classes = Classroom::query()
            ->ordered()
            ->withCount([
                // Every student row (archived and soft-deleted too): what blocks a delete.
                'students as total_students' => fn (Builder $q) => $q->withTrashed(),
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
        $studentsDialog = $this->studentsDialog();

        return view('livewire.admin.kelas', [
            'groups' => array_values($groups),
            'levelOptions' => array_map(strval(...), ClassroomName::TINGKAT),
            'renamingPrefix' => $renaming !== null ? (ClassroomName::TINGKAT[$renaming->level] ?? null) : null,
            'renamingName' => $renaming?->name,
            'deletingName' => $this->deletingId !== null ? $this->deletingName : null,
            'studentsDialog' => $studentsDialog,
        ]);
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

        // Only ticks on rows the list shows count; askStatusSelected() refuses any other id (404) or drops a student who
        // left the class meanwhile (toast).
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
     * A student account the student dialog listed and that is still in the open class. An id this visit never listed is
     * refused (404); a listed student who left the class gets a warning toast and null.
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

    /** @return Builder<User> the accounts of the students of one class */
    private function studentsInClass(string $classroomId): Builder
    {
        return User::query()->whereHas('student', fn (Builder $q) => $q->where('classroom_id', $classroomId));
    }

    /** @param  list<int>  $ids */
    private function openStatusDialog(array $ids): void
    {
        $this->dispatch(UbahStatusSiswa::OPEN_EVENT, ids: $ids)->to(UbahStatusSiswa::class);
    }

    /** Rows of `students` that point at the class, soft-deleted ones too (the same count DeleteClassroom checks). */
    private static function studentRows(Classroom $classroom): int
    {
        return DB::table('students')->where('classroom_id', $classroom->getKey())->count();
    }

    private function closeRename(): void
    {
        $this->reset('renamingId', 'renamingName', 'renameSuffix');
        $this->resetErrorBag();
        $this->dispatch('sth-modal-close', id: self::RENAME_DIALOG);
    }

    private function stale(string $message): void
    {
        $this->dispatch('sth-toast', message: $message, tone: 'warning');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
