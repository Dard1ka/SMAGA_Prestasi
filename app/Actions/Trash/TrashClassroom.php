<?php

declare(strict_types=1);

namespace App\Actions\Trash;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Kelas › "Hapus" on a class (the lead's decision of 5 Nov 2026, smaga_server only; replaces the "only an empty
 * class" rule of D-166 here): the class and EVERY student row in it (any status: aktif, belum aktivasi, nonaktif,
 * alumni) go to the trash together. The class is soft-deleted (gone from every list, select and the import; its name
 * stays taken), the students as in TrashStudents (row + account, sessions ended), one class entry with one child entry
 * per student (`parent_id`), all with the same `purge_after`. An empty class goes the same way, with no children.
 * Students already in the trash on their own keep their own entry.
 *
 * Authorised with `classroom.manage` (TrashEntryPolicy::create + ClassroomPolicy::delete), one transaction with the
 * class and its students locked, ONE audit row `classroom.trashed` (log `classroom`) with the class name, level,
 * student count, reason and purge_after: no student data.
 */
final class TrashClassroom
{
    public const EVENT = 'classroom.trashed';

    public const MSG_GONE = 'Kelas ini sudah dihapus.';

    public function __construct(private readonly TrashStudents $students) {}

    /**
     * @return TrashEntry the class entry (its children are the students)
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Classroom $classroom, string $reason): TrashEntry
    {
        Gate::forUser($actor)->authorize('create', TrashEntry::class);
        Gate::forUser($actor)->authorize('delete', $classroom);

        $reason = TrashReason::validated($reason);

        /** @var array{0: TrashEntry, 1: list<int>} $outcome */
        $outcome = DB::transaction(function () use ($actor, $classroom, $reason): array {
            /** @var Classroom|null $locked */
            $locked = Classroom::query()->lockForUpdate()->find($classroom->getKey());

            if ($locked === null) {
                throw ValidationException::withMessages(['classroom' => self::MSG_GONE]);
            }

            $students = Student::query()
                ->where('classroom_id', $locked->getKey())
                ->lockForUpdate()
                ->get(['id', 'user_id', 'classroom_id']);

            $now = CarbonImmutable::now();

            DB::table('classrooms')->where('id', $locked->getKey())->update(['deleted_at' => $now]);

            $entry = TrashEntry::query()->forceCreate([
                'kind' => TrashEntry::KIND_CLASSROOM,
                'classroom_id' => $locked->getKey(),
                'user_id' => null,
                'parent_id' => null,
                'deleted_by' => (int) $actor->getKey(),
                'deleted_at' => $now,
                'purge_after' => TrashEntry::purgeAfterFor($now),
                'reason' => $reason,
            ]);

            TrashStudents::trashRows($students, $now, $actor, $reason, $entry);

            activity('classroom')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'name' => $locked->name,
                    'level' => $locked->level,
                    'students' => $students->count(),
                    'reason' => $reason,
                    'purge_after' => $entry->purge_after->toIso8601String(),
                ])
                ->log(self::EVENT);

            return [$entry, $students->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all()];
        });

        [$entry, $userIds] = $outcome;

        $this->students->endSessions($userIds);
        PublicAchievements::flush();

        return $entry;
    }
}
