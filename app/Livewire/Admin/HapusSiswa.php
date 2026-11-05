<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Trash\TrashReason;
use App\Actions\Trash\TrashStudents;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Hapus siswa?" (SV6, D-185; the lead, 5 Nov 2026: "bisa jg menghapus siswa sama seperti logic tab kelas"): ONE dialog,
 * rendered once inside Admin › Kelas (rows of "Lihat siswa", one or many ticked) and inside Status aktivasi (both lists,
 * one or many ticked), built like UbahStatusSiswa. The students go to the Sampah of Admin › Kelas (TrashStudents).
 *
 * - Who: TrashEntryPolicy::trashStudents(), so `student.trash` (Kesiswaan, Admin) or `classroom.manage` (Admin). The
 *   Sampah itself and "Pulihkan" stay Admin only; after a delete by Kesiswaan the toast says that the Admin can restore
 *   the students in Admin › Kelas within TrashEntry::KEEP_DAYS days.
 * - The host page checks the ids against the rows it showed and sends OPEN_EVENT; open() checks them again (every id a
 *   student account that is not in the trash, else 404; at most TrashStudents::MAX_STUDENTS), asks for a password from
 *   the last 15 minutes (SECURITY §3m-9, again on "Hapus siswa"), and keeps the ids with the class each student was in
 *   (#[Locked] $asked).
 * - The dialog names up to three students and their class, or "dari N kelas" when the ticks come from several classes.
 * - Stale screens (CLAUDE.md §6): a student who went to the trash or changed class meanwhile closes the dialog with a
 *   warning toast and DONE_EVENT, never a 403/404 page.
 * - D-128 pattern: trash() acts only on what open() stored and is a silent no-op without it. DONE_EVENT tells the host
 *   to redraw its lists and counts and clear its ticks.
 */
final class HapusSiswa extends Component
{
    use RequiresRecentPassword;

    public const DIALOG = 'hapus-siswa';

    /** Host → this component: `ids` (student user ids). */
    public const OPEN_EVENT = 'hapus-siswa-buka';

    /** This component → host: students may have gone to the trash, redraw. */
    public const DONE_EVENT = 'hapus-siswa-selesai';

    public const MSG_CHANGED = 'Sebagian siswa yang dipilih sudah dihapus atau pindah kelas. Daftar dan pilihan sudah diperbarui.';

    /**
     * @var array<int, string> the students open() asked about: user id => the class they were in (classroom id, '' when
     *                         none); empty = no question
     */
    #[Locked]
    public array $asked = [];

    /** Page to come back to after the password confirmation (set by the host at mount). */
    #[Locked]
    public string $returnTo = '';

    public string $reason = '';

    public function mount(?string $returnTo = null): void
    {
        Gate::authorize('trashStudents', TrashEntry::class);
        $this->returnTo = $returnTo ?? url()->current();
    }

    /**
     * Ask step: "Hapus N siswa?" for these student accounts.
     *
     * @param  array<int|string, mixed>  $ids
     */
    #[On(self::OPEN_EVENT)]
    public function open(array $ids = []): void
    {
        Gate::authorize('trashStudents', TrashEntry::class);
        $this->forget();

        $userIds = [];

        foreach ($ids as $id) {
            abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);
            $userIds[] = (int) $id;
        }

        $userIds = array_values(array_unique($userIds));
        abort_if($userIds === [] || count($userIds) > TrashStudents::MAX_STUDENTS, 404);

        $classes = $this->classesOf($userIds);
        abort_unless(count($classes) === count($userIds), 404);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        ksort($classes);
        $this->asked = $classes;
        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    /** The dialog closed (Batal, X, Esc, a click outside): forget the question and the reason. */
    public function forget(): void
    {
        $this->reset('asked', 'reason');
        $this->resetErrorBag();
    }

    /**
     * "Hapus siswa". Without a question asked by open() there is nothing to confirm: it returns without a word, so a
     * second click after the delete does nothing.
     */
    public function trash(TrashStudents $trash): void
    {
        if ($this->asked === []) {
            return;
        }

        Gate::authorize('trashStudents', TrashEntry::class);

        $ids = array_map(intval(...), array_keys($this->asked));

        // Every asked student still a student, not in the trash and in the class the dialog named.
        if ($this->classesOf($ids) != $this->asked) {
            $this->finish();
            $this->dispatch('sth-toast', message: self::MSG_CHANGED, tone: 'warning');

            return;
        }

        // Under the field; the dialog stays open.
        $reason = TrashReason::validated($this->reason, 'reason');

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $entries = $trash->handle($this->actor(), $ids, $reason);
        } catch (ValidationException $e) {
            $this->finish();
            $this->dispatch('sth-toast', message: (string) (collect($e->errors())->flatten()->first() ?? self::MSG_CHANGED), tone: 'warning');

            return;
        }

        $this->finish();
        $this->dispatch('sth-toast', message: self::doneMessage(count($entries), Gate::allows('viewAny', TrashEntry::class)), tone: 'success');
    }

    /** The toast after "Hapus siswa"; who cannot restore is told who can (SV6, D-185). */
    public static function doneMessage(int $count, bool $canRestore): string
    {
        $message = $count.' siswa dipindahkan ke Sampah.';

        return $canRestore
            ? $message
            : $message.' Admin masih bisa memulihkannya di menu Kelas selama '.TrashEntry::KEEP_DAYS.' hari.';
    }

    public function render(): View
    {
        return view('livewire.admin.hapus-siswa', [
            'dialog' => $this->asked === [] ? null : $this->dialog(),
            'reasonMin' => TrashReason::MIN,
            'reasonMax' => TrashReason::MAX,
        ]);
    }

    /**
     * View model of the question.
     *
     * @return array{title: string, names: string, where: ?string, lines: list<string>}
     */
    private function dialog(): array
    {
        $count = count($this->asked);
        $names = User::query()->whereKey(array_keys($this->asked))->orderBy('name')->orderBy('id')->limit(3)->pluck('name')->implode(', ')
            .($count > 3 ? ', dan '.($count - 3).' lainnya' : '');
        $classIds = array_values(array_unique(array_filter($this->asked, fn (string $id): bool => $id !== '')));
        $purgeDate = TrashEntry::purgeAfterFor(CarbonImmutable::now())->translatedFormat('j F Y');
        $canRestore = Gate::allows('viewAny', TrashEntry::class);
        $className = count($classIds) === 1 ? Classroom::query()->whereKey($classIds[0])->value('name') : null;

        return [
            'title' => 'Hapus '.$count.' siswa?',
            'names' => $names,
            'where' => match (true) {
                count($classIds) > 1 => 'dari '.count($classIds).' kelas',
                $className !== null => 'Kelas '.$className,
                default => null,
            },
            'lines' => [
                ($count === 1 ? 'Siswa ini' : 'Semua siswa yang dipilih').' dipindahkan ke Sampah: langsung tidak bisa masuk dan tidak tampil di mana pun.',
                ($canRestore
                    ? 'Selama '.TrashEntry::KEEP_DAYS.' hari masih bisa dipulihkan.'
                    : 'Selama '.TrashEntry::KEEP_DAYS.' hari Admin masih bisa memulihkannya di menu Kelas.')
                .' Mulai '.$purgeDate.' akun dan seluruh data siswanya (pendaftaran, surat tugas, laporan hasil, prestasi, talenta, file) terhapus permanen.',
            ],
        ];
    }

    /**
     * The class of each of these students as it is now (a staff account, an unknown id or a student in the trash is
     * left out: Student and User exclude the trash).
     *
     * @param  list<int>  $userIds
     * @return array<int, string> user id => classroom id ('' when none), by user id
     */
    private function classesOf(array $userIds): array
    {
        $classes = Student::query()
            ->whereIn('user_id', $userIds)
            ->whereHas('user')
            ->get(['user_id', 'classroom_id'])
            ->mapWithKeys(fn (Student $student): array => [(int) $student->user_id => (string) $student->classroom_id])
            ->all();
        ksort($classes);

        return $classes;
    }

    /** Close the dialog, forget the question and tell the host to redraw. */
    private function finish(): void
    {
        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch(self::DONE_EVENT);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** Back from the password confirmation (RequiresRecentPassword): the host page. */
    protected function returnUrl(): string
    {
        return $this->returnTo;
    }
}
