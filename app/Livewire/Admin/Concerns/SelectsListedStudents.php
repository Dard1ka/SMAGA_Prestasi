<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

use App\Actions\Trash\TrashStudents;
use Livewire\Attributes\Locked;

/**
 * Ticks on a list of students (SV5 Admin › Kelas › Lihat siswa; SV6, D-185 also both lists of Status aktivasi, so the
 * two pages offer the same student actions): "Pilih semua", "Ubah status terpilih (N)", "Hapus terpilih (N)".
 *
 * - render() hands every student row it draws to rememberShown(): the #[Locked] $shownStudents of this visit. An id
 *   outside it never was on this screen, so a call with it is forged and answers 404; an id inside it that changed
 *   since (another tab, the import, a colleague) gets a warning toast from the host.
 * - $selectedStudents holds the checkbox values (strings); tickedIds() turns them into ids: malformed or never listed
 *   = 404, none = a toast, more than MAX_SELECTED = a toast (the shared dialogs and TrashStudents take at most 400).
 * - "Pilih semua" (toggleTicks()) ticks the rows the list shows now, at most MAX_SELECTED, or clears them when they are
 *   all ticked already.
 */
trait SelectsListedStudents
{
    /** One selection never holds more (UbahStatusSiswa::MAX_STUDENTS and TrashStudents::MAX_STUDENTS are 400 too). */
    public const MAX_SELECTED = TrashStudents::MAX_STUDENTS;

    public const MSG_NONE_SELECTED = 'Pilih siswa dulu.';

    /**
     * @var list<int> student user ids the lists of this screen showed during this visit (filled in render()): an id
     *                among them that changed since gets a toast, any other id is refused (a forged call)
     */
    #[Locked]
    public array $shownStudents = [];

    /**
     * @var list<string> ticked rows (checkbox values, so strings); render() counts only the rows it shows, the ask methods
     *                   check them against $shownStudents
     */
    public array $selectedStudents = [];

    public static function tooManySelected(): string
    {
        return 'Paling banyak '.self::MAX_SELECTED.' siswa sekaligus.';
    }

    /** @param  list<int>  $ids  student user ids a list just drew */
    protected function rememberShown(array $ids): void
    {
        $this->shownStudents = array_values(array_unique([...$this->shownStudents, ...$ids]));
    }

    /**
     * The ticks as ids, each once. A malformed tick or one on a row this visit never listed is refused with 404;
     * nothing ticked or more than MAX_SELECTED gets a warning toast and null.
     *
     * @return list<int>|null
     */
    protected function tickedIds(): ?array
    {
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

        if (count($ids) > self::MAX_SELECTED) {
            $this->dispatch('sth-toast', message: self::tooManySelected(), tone: 'warning');

            return null;
        }

        return $ids;
    }

    /**
     * "Pilih semua": ticks these listed rows (the first MAX_SELECTED of them), or clears the ticks when they are all
     * ticked already. A row this visit never listed is left out.
     *
     * @param  list<int>  $listed  the ids of the rows the list shows now, in their order
     */
    protected function toggleTicks(array $listed): void
    {
        $ids = array_map(strval(...), self::capped(array_values(array_filter(
            $listed,
            fn (int $id): bool => in_array($id, $this->shownStudents, true),
        ))));

        $this->selectedStudents = $ids !== [] && array_diff($ids, $this->selectedStudents) === [] ? [] : $ids;

        if ($this->selectedStudents !== [] && count($listed) > self::MAX_SELECTED) {
            $this->dispatch('sth-toast', message: self::tooManySelected().' '.self::MAX_SELECTED.' siswa pertama dipilih.', tone: 'warning');
        }
    }

    /**
     * For the view: how many of these listed rows are ticked, and whether "Pilih semua" stands checked (every row it
     * would tick is ticked).
     *
     * @param  list<int>  $listed
     * @return array{selected: int, allSelected: bool}
     */
    protected function tickState(array $listed): array
    {
        $rows = array_map(strval(...), $listed);
        $ticked = array_values(array_intersect(array_map(strval(...), $this->selectedStudents), $rows));
        $all = array_map(strval(...), self::capped($listed));

        return [
            'selected' => count($ticked),
            'allSelected' => $all !== [] && array_diff($all, $ticked) === [],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function capped(array $ids): array
    {
        return array_slice($ids, 0, self::MAX_SELECTED);
    }
}
