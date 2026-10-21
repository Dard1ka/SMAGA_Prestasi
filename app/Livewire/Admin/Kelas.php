<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

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
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Kelas (`admin.kelas.index`, ClassroomPolicy / `classroom.manage`, Admin only): the classes per tingkat with
 * their active and total students, "Tambah kelas" (CreateClassroom), "Ganti nama" in a dialog (RenameClassroom, only
 * the part after the dash) and "Hapus" behind a question (DeleteClassroom, only a class without any student row). The
 * student import puts students only into the classes listed here; it never adds or deletes one.
 *
 * Stale screens (CLAUDE.md §6): a class another tab renamed or deleted meanwhile answers with a warning toast and the
 * redrawn list, never a 403/404 page. The dialogs keep what they ask in #[Locked] properties; the confirm methods do
 * nothing without them (D-128 pattern).
 */
#[Title('Kelas')]
#[Layout('layouts::app', ['subtitle' => 'Daftar kelas yang dipakai import siswa.'])]
final class Kelas extends Component
{
    public const RENAME_DIALOG = 'kelas-ganti-nama';

    public const DELETE_DIALOG = 'kelas-hapus';

    public const MSG_GONE = 'Kelas ini sudah dihapus. Daftar sudah diperbarui.';

    public const MSG_RENAMED = 'Nama kelas ini sudah diganti. Daftar sudah diperbarui.';

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

        return view('livewire.admin.kelas', [
            'groups' => array_values($groups),
            'levelOptions' => array_map(strval(...), ClassroomName::TINGKAT),
            'renamingPrefix' => $renaming !== null ? (ClassroomName::TINGKAT[$renaming->level] ?? null) : null,
            'renamingName' => $renaming?->name,
            'deletingName' => $this->deletingId !== null ? $this->deletingName : null,
        ]);
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
