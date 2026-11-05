<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Actions\Accounts\AccountControls;
use App\Enums\Permission;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tata Usaha › Data siswa (SV6, D-187, route `tu.siswa.index`): every student who is not in the Sampah, any account
 * status, by name; search by name or NISN, filter by class, PER_PAGE rows a page. Columns Nama, NISN, NIS, Kelas,
 * Status. "Ubah data" on a row opens the shared dialog App\Livewire\Akun\UbahDataSiswa (rendered once on this page) for
 * that student after checking the id is one of the rows this page showed (#[Locked] $shownIds; another id → 404, a
 * student moved to the Sampah meanwhile → a warning toast and a fresh list). The page and every action need
 * `student.update`; the dialog re-checks it with StudentPolicy::update. The list redraws on UbahDataSiswa::DONE_EVENT.
 */
#[Title('Data siswa')]
// One search box per screen (D-90): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Perbaiki nama, NIS, NISN, tanggal lahir, atau kelas siswa yang salah.', 'search' => false])]
final class DataSiswa extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /** The search reads at most this many characters (the box keeps what was typed, the query trims). */
    public const SEARCH_MAX = 100;

    public const MSG_GONE = 'Siswa ini sudah dipindahkan ke Sampah atau dihapus. Daftar sudah diperbarui.';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** Classroom id of the class filter; '' = every class. */
    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    /** @var list<int> the student accounts the last render listed ("Ubah data" accepts only these) */
    #[Locked]
    public array $shownIds = [];

    public function mount(): void
    {
        Gate::authorize(Permission::StudentUpdate->value);
        $this->clampSearch();
        $this->kelas = self::knownClass($this->kelas) ? $this->kelas : '';
    }

    /** A new search starts on page 1; the typed text is kept as typed (render() trims it, SV1). */
    public function updatedQ(): void
    {
        $this->clampSearch();
        $this->resetPage();
    }

    public function updatedKelas(): void
    {
        $this->kelas = self::knownClass($this->kelas) ? $this->kelas : '';
        $this->resetPage();
    }

    /** "Ubah data" on a row: the shared dialog for this student, only when this page listed it. */
    public function edit(int $userId): void
    {
        Gate::authorize(Permission::StudentUpdate->value);
        abort_unless(in_array($userId, $this->shownIds, true), 404);

        if (! User::query()->whereKey($userId)->whereHas('student')->exists()) {
            $this->dispatch('sth-toast', message: self::MSG_GONE, tone: 'warning');

            return;
        }

        $this->dispatch(UbahDataSiswa::OPEN_EVENT, userId: $userId)->to(UbahDataSiswa::class);
    }

    /** The dialog saved or found the student changed or gone: redraw the rows. */
    #[On(UbahDataSiswa::DONE_EVENT)]
    public function refreshList(): void
    {
        // Re-render only.
    }

    public function render(): View
    {
        Gate::authorize(Permission::StudentUpdate->value);

        $term = mb_substr(trim($this->q), 0, self::SEARCH_MAX);
        // Wildcards are dropped, not escaped: the escape character differs between MySQL and SQLite, and no name has one.
        $like = '%'.str_replace(['%', '_', '\\'], '', mb_strtolower($term)).'%';
        $byNisn = $term !== '' && ctype_digit($term);

        // User and Student both leave the Sampah out (soft deletes).
        $students = User::query()
            ->whereHas('student', fn (Builder $q) => $q
                ->when($this->kelas !== '', fn (Builder $c) => $c->where('classroom_id', $this->kelas))
                ->when($byNisn, fn (Builder $n) => $n->where('nisn', 'like', $term.'%')))
            ->when($term !== '' && ! $byNisn, fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]))
            ->with('student.classroom:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        $this->shownIds = array_map('intval', $students->getCollection()->modelKeys());

        return view('livewire.tata-usaha.data-siswa', [
            'students' => $students,
            'rows' => $students->getCollection()->map(fn (User $user): array => self::row($user))->all(),
            'classOptions' => ['' => 'Semua kelas'] + self::classGroups(),
            'filtered' => $term !== '' || $this->kelas !== '',
            'canEdit' => Gate::allows(Permission::StudentUpdate->value),
        ]);
    }

    /** @return array{id: int, name: string, nisn: string, nis: string, classroom: string, stateLabel: string, stateTone: string} */
    private static function row(User $user): array
    {
        $state = AccountControls::state($user);

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'nisn' => (string) $user->student?->nisn,
            'nis' => (string) $user->student?->nis,
            'classroom' => (string) ($user->student?->classroom?->name ?? '–'),
            'stateLabel' => AccountControls::stateLabel($state),
            'stateTone' => AccountControls::stateTone($state),
        ];
    }

    /** @return array<string, array<string, string>> classes not in the Sampah by tingkat ("Kelas X" => [id => name]) */
    private static function classGroups(): array
    {
        $groups = [];

        foreach (Classroom::query()->ordered()->get(['id', 'name', 'level']) as $classroom) {
            $label = match ((int) $classroom->level) {
                10 => 'Kelas X',
                11 => 'Kelas XI',
                12 => 'Kelas XII',
                default => 'Kelas lain',
            };
            $groups[$label][(string) $classroom->getKey()] = (string) $classroom->name;
        }

        return $groups;
    }

    private static function knownClass(string $id): bool
    {
        return $id !== '' && Classroom::query()->whereKey($id)->exists();
    }

    /** Only a text longer than the search reads is cut; the box keeps everything else as typed, spaces included. */
    private function clampSearch(): void
    {
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }
    }
}
