<?php

declare(strict_types=1);

namespace App\Actions\Trash;

use App\Actions\Classrooms\ClassroomName;
use App\Actions\Students\PurgeStudents;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SV6, D-186 (the lead, 5 Nov 2026): Admin › Kelas › Sampah › "Hapus permanen" deletes one open top-level entry for good
 * now, without waiting for `purge_after` (the counterpart of RestoreFromTrash; the 02:00 run, PurgeTrash, stays).
 * - A class entry takes the students trashed with it (its open children) AND the open entries of students of that class
 *   trashed alone (kind student, no parent, same classroom): without them the class row could not go (foreign key).
 *   The Admin types the class name (compared with ClassroomName::key(): case and spaces do not count).
 * - A student entry takes that student only; nothing is typed.
 *
 * Authorised with TrashEntryPolicy::forceDelete (`classroom.manage`: Admin, who can log in). One transaction through
 * PurgeStudents::handleWithin(): the entry is locked first (the lock order of RestoreFromTrash, so a "Pulihkan" at the
 * same moment waits or wins), re-checked open with every student still in the trash, the data deleted (mixed teams
 * kept, as at 02:00), the class force-deleted, every entry taken stamped `purged_at`, and ONE audit row
 * `trash.purged_by_admin` (log `account`, the Admin as causer; ids and counts only, never a name or NISN). File bytes
 * go after the commit. A refusal (DomainException of PurgeStudents) writes nothing: the entry stays restorable.
 */
final class PurgeFromTrash
{
    public const EVENT = 'trash.purged_by_admin';

    /** Error key of the typed class name (the Livewire property of the dialog). */
    public const CONFIRM_KEY = 'purgeConfirmName';

    public const MSG_CONFIRM_NAME = 'Ketik nama kelas persis seperti di judul.';

    private const CHUNK = 500;

    /**
     * The top-level entry with this id; a child entry or an unknown id is not found (404).
     *
     * @throws ModelNotFoundException
     */
    public static function entry(string $id): TrashEntry
    {
        /** @var TrashEntry */
        return TrashEntry::query()->whereNull('parent_id')->findOrFail($id);
    }

    /**
     * What a purge of this entry would do, writing nothing: the refusal sentence of PurgeStudents (null = it would go
     * through), the counts, the students it takes and how many of them were trashed alone.
     *
     * @return array{refusal: ?string, counts: array<string, int>, students: int, alone: int, purge_after: CarbonImmutable}
     */
    public function preview(User $actor, TrashEntry $entry): array
    {
        Gate::forUser($actor)->authorize('forceDelete', $entry);
        $top = self::entry((string) $entry->getKey());
        $taken = $this->taken($top);
        $preview = app(PurgeStudents::class)->preview($this->trashedStudentIds($taken));

        return [
            'refusal' => $preview['refusal'],
            'counts' => $preview['counts'],
            'students' => $taken->count(),
            'alone' => $taken->whereNull('parent_id')->count(),
            'purge_after' => $top->purge_after,
        ];
    }

    /**
     * @return array<string, int> PurgeStudents::COUNT_KEYS => count
     *
     * @throws ModelNotFoundException a child entry or an unknown one
     * @throws ValidationException `purgeConfirmName` (wrong class name) or `entry` (RestoreFromTrash::MSG_CLOSED / MSG_GONE)
     * @throws DomainException the purge was refused (PurgeStudents' sentence); nothing was written
     */
    public function handle(User $actor, TrashEntry $entry, ?string $typedClassName = null): array
    {
        Gate::forUser($actor)->authorize('forceDelete', $entry);
        $top = self::entry((string) $entry->getKey());

        if (! $top->isOpen()) {
            throw ValidationException::withMessages(['entry' => RestoreFromTrash::MSG_CLOSED]);
        }

        $className = null;

        if ($top->isClassroom()) {
            $className = Classroom::withTrashed()->whereKey($top->classroom_id)->value('name');

            if (! is_string($className)) {
                throw ValidationException::withMessages(['entry' => RestoreFromTrash::MSG_GONE]);
            }

            if (ClassroomName::key((string) $typedClassName) !== ClassroomName::key($className)) {
                throw ValidationException::withMessages([self::CONFIRM_KEY => self::MSG_CONFIRM_NAME]);
            }
        }

        $expectedEntries = $this->keys($this->taken($top));
        $studentIds = $this->trashedStudentIds($this->taken($top));
        $now = CarbonImmutable::now();
        /** @var Collection<int, TrashEntry> $locked */
        $locked = collect();

        $before = function (array $ids) use ($top, $expectedEntries, $studentIds, &$locked): void {
            /** @var TrashEntry|null $entry */
            $entry = TrashEntry::query()->lockForUpdate()->find($top->getKey());

            if ($entry === null || ! $entry->isOpen()) {
                throw ValidationException::withMessages(['entry' => RestoreFromTrash::MSG_CLOSED]);
            }

            $taken = $this->taken($entry, lock: true);
            $keys = $this->keys($taken);
            sort($keys);
            $expected = $expectedEntries;
            sort($expected);

            // Restored, purged or trashed meanwhile, or a student no longer in the trash: nothing goes.
            if ($keys !== $expected || $this->trashedStudentIds($taken) !== $studentIds || $ids !== $studentIds) {
                throw ValidationException::withMessages(['entry' => RestoreFromTrash::MSG_CLOSED]);
            }

            $locked = $entry->isClassroom() ? collect([$entry, ...$taken->all()]) : collect([$entry]);
        };

        $after = function (array $counts) use ($actor, $top, $className, $now, &$locked): void {
            if ($top->isClassroom()) {
                /** @var Classroom|null $classroom */
                $classroom = Classroom::withTrashed()->lockForUpdate()->find($top->classroom_id);

                if ($classroom !== null) {
                    $left = DB::table('students')->where('classroom_id', $classroom->getKey())->count();

                    if ($left > 0) {
                        throw new DomainException("{$left} siswa masih tercatat di kelas ini. Tidak ada yang dihapus.");
                    }

                    $classroom->forceDelete();
                }
            }

            foreach ($locked as $entry) {
                $entry->forceFill(['purged_at' => $now])->save();
            }

            activity('account')
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(array_filter([
                    'via' => 'web',
                    'entry' => (string) $top->getKey(),
                    'kind' => (string) $top->kind,
                    'name' => $className,
                    'students' => $counts['students'] ?? 0,
                    'purge_after' => $top->purge_after->toIso8601String(),
                    'data' => $counts,
                ], fn (mixed $value): bool => $value !== null))
                ->log(self::EVENT);
        };

        if ($studentIds === []) {
            // An empty class (or students already gone): no purge, only the class and the entries, in a small transaction.
            $counts = array_fill_keys(PurgeStudents::COUNT_KEYS, 0);
            DB::transaction(function () use ($before, $after, $counts): void {
                $before([]);
                $after($counts);
            });
        } else {
            // Resolved at call time, not injected: the nightly tests bind a stand-in for this final class.
            $counts = app(PurgeStudents::class)->handleWithin($studentIds, $before, $after);
        }

        PublicAchievements::flush();

        return $counts;
    }

    /**
     * The student entries the purge of $top takes: itself for a student entry; for a class entry its open children and
     * the open entries of the class's students trashed alone.
     *
     * @return Collection<int, TrashEntry>
     */
    private function taken(TrashEntry $top, bool $lock = false): Collection
    {
        if (! $top->isClassroom()) {
            return collect([$top]);
        }

        $query = TrashEntry::query()
            ->open()
            ->where(fn ($query) => $query
                ->where('parent_id', $top->getKey())
                ->orWhere(fn ($alone) => $alone
                    ->whereNull('parent_id')
                    ->where('kind', TrashEntry::KIND_STUDENT)
                    ->where('classroom_id', $top->classroom_id)))
            ->orderBy('user_id')
            ->orderBy('id');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /**
     * @param  Collection<int, TrashEntry>  $entries
     * @return list<string>
     */
    private function keys(Collection $entries): array
    {
        return $entries->map(fn (TrashEntry $entry): string => (string) $entry->getKey())->values()->all();
    }

    /**
     * `students.id` of the entries' students whose row AND account are still soft-deleted, sorted.
     *
     * @param  Collection<int, TrashEntry>  $entries
     * @return list<string>
     */
    private function trashedStudentIds(Collection $entries): array
    {
        $userIds = $entries->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $ids = [];

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $rows = DB::table('students')
                ->join('users', 'users.id', '=', 'students.user_id')
                ->whereIn('students.user_id', $chunk)
                ->whereNotNull('students.deleted_at')
                ->whereNotNull('users.deleted_at')
                ->pluck('students.id');

            foreach ($rows as $id) {
                $ids[] = (string) $id;
            }
        }

        sort($ids);

        return $ids;
    }
}
