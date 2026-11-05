<?php

declare(strict_types=1);

namespace App\Actions\Trash;

use App\Actions\Students\PurgeStudents;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\TrashEntry;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The permanent deletion of the trash (`php artisan sth:sampah:hapus-permanen`, daily 02:00 Asia/Jakarta; the lead's
 * decision of 5 Nov 2026, smaga_server only). Takes every open entry whose `purge_after` has come (TrashEntry::due()),
 * in groups: a class entry with the students trashed with it, and each student trashed alone.
 *
 * 1. Per group, the students that are still in the trash (both the `students` row and the account soft-deleted: a
 *    restored student is never purged) go to App\Actions\Students\PurgeStudents::handle(), which deletes their data with
 *    the mixed-team rules (its own transaction, file bytes after its commit). A group it refuses (DomainException,
 *    nothing written, the message holds counts only) is printed and logged and stays in the trash; the other groups go
 *    on.
 * 2. Then, in one transaction: a student entry whose student row is gone gets `purged_at`; the class of a due class
 *    entry is force-deleted once no student row points at it any more, and the entry gets `purged_at`; an entry whose
 *    rows are still there stays open and is tried again on the next run.
 * 3. ONE audit row `trash.purged` (log `account`, no causer) per run that closed anything: counts only, never a name.
 *
 * Idempotent: a run after a crash between 1 and 2 finds the students gone and only closes the entries.
 */
final class PurgeTrash
{
    public const EVENT = 'trash.purged';

    private const CHUNK = 500;

    /**
     * @return array{entries: int, kelas: int, siswa: int, kelas_tertunda: int, siswa_tertunda: int, data: array<string, int>, refusals: list<string>}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = ['entries' => 0, 'kelas' => 0, 'siswa' => 0, 'kelas_tertunda' => 0, 'siswa_tertunda' => 0, 'data' => [], 'refusals' => []];

        $due = TrashEntry::query()->due($now)->orderBy('purge_after')->orderBy('id')->get();

        if ($due->isEmpty()) {
            return $result;
        }

        $groups = $this->groups($due);
        $purged = false;

        foreach ($groups as $group) {
            $studentIds = $this->trashedStudentIds($group['students']);

            if ($studentIds === []) {
                continue;
            }

            try {
                /** @var mixed $counts */
                $counts = app(PurgeStudents::class)->handle($studentIds);
            } catch (DomainException $e) {
                $result['refusals'][] = $e->getMessage();
                Log::warning('trash.purge_refused', ['message' => $e->getMessage(), 'students' => count($studentIds)]);

                continue;
            }

            $purged = true;

            foreach (is_array($counts) ? $counts : [] as $key => $count) {
                if (is_int($count)) {
                    $result['data'][(string) $key] = ($result['data'][(string) $key] ?? 0) + $count;
                }
            }
        }

        $studentEntries = collect($groups)->flatMap(fn (array $group): Collection => $group['students'])->values();
        $classEntries = collect($groups)->pluck('class')->filter()->values();

        $closed = DB::transaction(function () use ($studentEntries, $classEntries, $now, &$result): int {
            $closed = 0;

            foreach ($studentEntries as $entry) {
                $entry = $this->stillOpen($entry);

                if ($entry === null) {
                    continue;
                }

                $row = DB::table('students')->where('user_id', $entry->user_id)->first(['deleted_at']);

                if ($row !== null) {
                    // Still in the trash: the purge refused or failed for it. Out of the trash (never by the app): left alone.
                    if ($row->deleted_at !== null) {
                        $result['siswa_tertunda']++;
                    }

                    continue;
                }

                $entry->forceFill(['purged_at' => $now])->save();
                $result['siswa']++;
                $closed++;
            }

            foreach ($classEntries as $entry) {
                $entry = $this->stillOpen($entry);

                if ($entry === null) {
                    continue;
                }

                /** @var Classroom|null $classroom */
                $classroom = Classroom::withTrashed()->lockForUpdate()->find($entry->classroom_id);

                if ($classroom !== null && ! $classroom->trashed()) {
                    // Restored outside the entry (never by the app): nothing to delete.
                    continue;
                }

                if ($classroom !== null) {
                    if (DB::table('students')->where('classroom_id', $classroom->getKey())->exists()) {
                        $result['kelas_tertunda']++;

                        continue;
                    }

                    $classroom->forceDelete();
                    $result['kelas']++;
                }

                $entry->forceFill(['purged_at' => $now])->save();
                $closed++;
            }

            if ($closed > 0) {
                activity('account')
                    ->event(self::EVENT)
                    ->withProperties([
                        'via' => 'cli',
                        'entries' => $closed,
                        'kelas' => $result['kelas'],
                        'siswa' => $result['siswa'],
                        'kelas_tertunda' => $result['kelas_tertunda'],
                        'siswa_tertunda' => $result['siswa_tertunda'],
                        'ditolak' => count($result['refusals']),
                        'data' => $result['data'],
                    ])
                    ->log(self::EVENT);
            }

            return $closed;
        });

        $result['entries'] = $closed;

        if ($closed > 0 || $purged) {
            PublicAchievements::flush();
        }

        return $result;
    }

    /**
     * SV6, D-186: the entry read again under a lock, or null when it was closed meanwhile (an Admin's "Hapus permanen",
     * App\Actions\Trash\PurgeFromTrash, at the same moment): never stamped or counted twice.
     */
    private function stillOpen(TrashEntry $entry): ?TrashEntry
    {
        /** @var TrashEntry|null $locked */
        $locked = TrashEntry::query()->lockForUpdate()->find($entry->getKey());

        return $locked !== null && $locked->isOpen() ? $locked : null;
    }

    /**
     * The due entries in purge groups: a class entry with its open children (the students trashed with it go with it,
     * whatever their own entry says), and every other student entry on its own.
     *
     * @param  Collection<int, TrashEntry>  $due
     * @return list<array{class: ?TrashEntry, students: Collection<int, TrashEntry>}>
     */
    private function groups(Collection $due): array
    {
        $classes = $due->where('kind', TrashEntry::KIND_CLASSROOM)->values();
        $children = $classes->isEmpty()
            ? collect()
            : TrashEntry::query()->open()->whereIn('parent_id', $classes->modelKeys())->orderBy('user_id')->get()->groupBy('parent_id');
        $groups = [];

        foreach ($classes as $class) {
            $groups[] = ['class' => $class, 'students' => $children->get($class->getKey(), collect())->values()];
        }

        $inClassGroup = $children->flatten(1)->map(fn (TrashEntry $entry): string => (string) $entry->getKey())->all();

        foreach ($due->where('kind', TrashEntry::KIND_STUDENT) as $entry) {
            if (! in_array($entry->getKey(), $inClassGroup, true)) {
                $groups[] = ['class' => null, 'students' => collect([$entry])];
            }
        }

        return $groups;
    }

    /**
     * `students.id` of the entries' students whose row AND account are still soft-deleted.
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
