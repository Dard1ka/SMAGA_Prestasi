<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Archive\ChangeArchiveDate;
use App\Actions\Archive\UnarchiveStudent;
use App\Domain\Archive\GraduationCalculator;
use App\Enums\UserStatus;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin · Arsip otomatis (UI-MAP §5 `admin.arsip.index`, backlog #8, owner B5; SECURITY §3m-8, D-13, D-29):
 * tab "Segera" (not archived yet, archive date within the chosen window, incl. overdue = archived tonight),
 * tab "Diarsipkan" (alumni, newest first) and tab "Riwayat" (automatic runs per day + manual changes from the
 * audit log). Actions: change a student's archive date (ChangeArchiveDate) and restore an archived account
 * (UnarchiveStudent), both with a reason, audited, re-authorized in the Action and behind a password
 * confirmation from the last 15 minutes (SECURITY §3m-9 "unarchive"). No NIS/NISN in the lists; search by
 * name or an exact NIS/NISN.
 */
#[Title('Arsip otomatis')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Akun siswa diarsipkan 18 bulan setelah mulai kelas XII.', 'search' => false])]
final class ArsipOtomatis extends Component
{
    use RequiresRecentPassword;
    use WithPagination;

    public const TABS = ['segera', 'arsip', 'riwayat'];

    public const WINDOWS = [30, 60, 90, 180];

    public const EVENTS = ['account.archived', 'account.unarchived', 'student.archive_date_changed'];

    #[Url(as: 'tab', except: 'segera')]
    public string $tab = 'segera';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'hari', except: '30')]
    public string $hari = '30';

    /** Student (ULID) the open dialog acts on; set only by openDate()/openRestore(). */
    #[Locked]
    public ?string $studentId = null;

    public string $archiveDate = '';

    public string $archiveReason = '';

    public function mount(): void
    {
        Gate::authorize('viewArchive', User::class);
        $this->normalize();
    }

    public function updated(string $property): void
    {
        $this->normalize();

        if (in_array($property, ['tab', 'q', 'hari'], true)) {
            $this->resetPage('segera');
            $this->resetPage('arsip');
        }
    }

    public function openDate(string $studentId): void
    {
        $student = $this->student($studentId);
        Gate::authorize('changeArchiveDate', $this->owner($student));

        $this->resetErrorBag();
        $this->studentId = (string) $student->getKey();
        $this->archiveDate = $student->archive_at?->toDateString() ?? '';
        $this->archiveReason = '';
        $this->dispatch('sth-modal-open', id: 'arsip-tanggal');
    }

    public function saveDate(ChangeArchiveDate $change): void
    {
        $student = $this->student((string) $this->studentId);
        Gate::authorize('changeArchiveDate', $this->owner($student));

        $this->validate(
            ['archiveDate' => ['required', 'date_format:Y-m-d'], 'archiveReason' => ['required', 'string', 'max:500']],
            [],
            ['archiveDate' => 'tanggal arsip', 'archiveReason' => 'alasan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $change->handle($this->actor(), $student, $this->archiveDate, $this->archiveReason);
        $this->closeDialog('arsip-tanggal', 'Tanggal arsip diubah.');
    }

    public function openRestore(string $studentId): void
    {
        $student = $this->student($studentId);
        Gate::authorize('unarchive', $this->owner($student));

        $this->resetErrorBag();
        $this->studentId = (string) $student->getKey();
        $this->archiveDate = '';
        $this->archiveReason = '';
        $this->dispatch('sth-modal-open', id: 'arsip-pulihkan');
    }

    public function restore(UnarchiveStudent $unarchive): void
    {
        $student = $this->student((string) $this->studentId);
        $owner = $this->owner($student);
        Gate::authorize('unarchive', $owner);

        $this->validate(
            ['archiveDate' => ['required', 'date_format:Y-m-d'], 'archiveReason' => ['required', 'string', 'max:500']],
            [],
            ['archiveDate' => 'tanggal arsip baru', 'archiveReason' => 'alasan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $restored = $unarchive->handle($this->actor(), $owner, $this->archiveDate, $this->archiveReason);
        $this->closeDialog('arsip-pulihkan', $restored->status === UserStatus::Active
            ? 'Akun dipulihkan. Siswa bisa login lagi.'
            : 'Akun dipulihkan. Siswa perlu aktivasi saat login.');
    }

    public function render(): View
    {
        Gate::authorize('viewArchive', User::class);

        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $notArchived = fn (Builder $q) => $q->where('status', '!=', UserStatus::Archived);

        $selected = $this->studentId !== null
            ? Student::query()->with(['user:id,name,status,deleted_at', 'classroom:id,name'])->find($this->studentId)
            : null;

        return view('livewire.admin.arsip-otomatis', [
            'today' => $today,
            'stats' => [
                'tonight' => Student::query()->whereNotNull('archive_at')->whereDate('archive_at', '<=', $today)->whereHas('user', $notArchived)->count(),
                'soon' => Student::query()->whereDate('archive_at', '>', $today)->whereDate('archive_at', '<=', $today->addDays(30))->whereHas('user', $notArchived)->count(),
                'archived' => Student::query()->alumni()->count(),
                'months' => app(GraduationCalculator::class)->archiveMonths(),
            ],
            'tabs' => $this->tabItems(),
            'due' => $this->tab === 'segera' ? $this->dueQuery($today)->paginate(20, pageName: 'segera') : null,
            'archived' => $this->tab === 'arsip' ? $this->archivedQuery()->paginate(20, pageName: 'arsip') : null,
            'runs' => $this->tab === 'riwayat' ? $this->automaticRuns() : collect(),
            'history' => $this->tab === 'riwayat' ? $this->manualHistory() : collect(),
            'selected' => $selected,
            'windowOptions' => collect(self::WINDOWS)->mapWithKeys(fn (int $d) => [(string) $d => $d.' hari'])->all(),
            'minDate' => $today->addDay()->toDateString(),
        ]);
    }

    /** @return Builder<Student> */
    private function dueQuery(CarbonImmutable $today): Builder
    {
        return $this->search(Student::query())
            ->whereNotNull('archive_at')
            ->whereDate('archive_at', '<=', $today->addDays((int) $this->hari))
            ->whereHas('user', fn (Builder $q) => $q->where('status', '!=', UserStatus::Archived))
            ->with(['user:id,name,status,deleted_at', 'classroom:id,name'])
            ->orderBy('archive_at')
            ->orderBy('id');
    }

    /** @return Builder<Student> */
    private function archivedQuery(): Builder
    {
        return $this->search(Student::query()->alumni())
            ->with(['user:id,name,status,deleted_at', 'classroom:id,name'])
            ->withCount('achievements')
            ->orderByDesc('archive_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    private function search(Builder $query): Builder
    {
        $term = trim($this->q);

        if ($term === '') {
            return $query;
        }

        // Exact NIS/NISN only (no prefix search, SECURITY §3m-3); otherwise the name.
        if (preg_match('/^\d{9,10}$/', $term) === 1) {
            return $query->where(fn (Builder $w) => $w->where('nis', $term)->orWhere('nisn', $term));
        }

        return $query->whereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'));
    }

    /**
     * Automatic runs of `sth:arsip-otomatis` in the last year, one row per day (newest first).
     *
     * @return Collection<int, array{date: string, count: int}>
     */
    private function automaticRuns(): Collection
    {
        return Activity::query()
            ->where('event', 'account.archived')
            ->whereNull('causer_id')
            ->where('created_at', '>=', now()->subYear())
            ->orderByDesc('id')
            ->get(['id', 'created_at'])
            ->groupBy(fn (Activity $a) => $a->created_at?->timezone(config('app.timezone'))->toDateString())
            ->map(fn (Collection $rows, string $date): array => ['date' => $date, 'count' => $rows->count()])
            ->values()
            ->take(12);
    }

    /**
     * Manual archive changes (Admin): restore, archive-date change, manual archive. Names resolved in one query.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function manualHistory(): Collection
    {
        $rows = Activity::query()
            ->whereIn('event', self::EVENTS)
            ->whereNotNull('causer_id')
            ->with('causer')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $userIds = $rows->map(fn (Activity $a) => $a->subject_type === (new User)->getMorphClass()
            ? (int) $a->subject_id
            : (int) ($a->properties['user_id'] ?? 0))->filter()->unique()->values();
        $names = User::withTrashed()->whereKey($userIds)->pluck('name', 'id');

        return $rows->map(function (Activity $a) use ($names): array {
            $userId = $a->subject_type === (new User)->getMorphClass() ? (int) $a->subject_id : (int) ($a->properties['user_id'] ?? 0);

            return [
                'id' => $a->id,
                'at' => $a->created_at,
                'event' => $a->event,
                'label' => match ($a->event) {
                    'account.unarchived' => 'Dipulihkan',
                    'student.archive_date_changed' => 'Tanggal arsip diubah',
                    default => 'Diarsipkan manual',
                },
                'tone' => match ($a->event) {
                    'account.unarchived' => 'green',
                    'student.archive_date_changed' => 'blue',
                    default => 'gray',
                },
                'student' => (string) ($names[$userId] ?? '-'),
                'actor' => (string) ($a->causer?->name ?? '-'),
                'reason' => (string) ($a->properties['reason'] ?? ''),
                'to' => match ($a->event) {
                    'student.archive_date_changed' => data_get($a->properties, 'to.archive_at'),
                    'account.unarchived' => data_get($a->properties, 'dates.to.archive_at'),
                    default => null,
                },
            ];
        });
    }

    /** @return list<array{key: string, label: string, href: string, count?: int}> */
    private function tabItems(): array
    {
        $base = array_filter(['q' => $this->q !== '' ? $this->q : null, 'hari' => $this->hari !== '30' ? $this->hari : null]);

        return [
            ['key' => 'segera', 'label' => 'Segera diarsipkan', 'href' => route('admin.arsip.index', $base)],
            ['key' => 'arsip', 'label' => 'Sudah diarsipkan', 'href' => route('admin.arsip.index', ['tab' => 'arsip'] + $base)],
            ['key' => 'riwayat', 'label' => 'Riwayat', 'href' => route('admin.arsip.index', ['tab' => 'riwayat'])],
        ];
    }

    private function normalize(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'segera';
        $this->hari = in_array((int) $this->hari, self::WINDOWS, true) ? (string) (int) $this->hari : '30';
        $this->q = mb_substr(trim($this->q), 0, 100);
    }

    private function student(string $id): Student
    {
        /** @var Student $student */
        $student = Student::query()->findOrFail($id);

        return $student;
    }

    private function owner(Student $student): User
    {
        /** @var User $user */
        $user = User::withTrashed()->findOrFail($student->user_id);

        return $user;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function closeDialog(string $id, string $message): void
    {
        $this->reset('studentId', 'archiveDate', 'archiveReason');
        $this->dispatch('sth-modal-close', id: $id);
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    protected function returnUrl(): string
    {
        return route('admin.arsip.index', array_filter(['tab' => $this->tab !== 'segera' ? $this->tab : null]));
    }
}
