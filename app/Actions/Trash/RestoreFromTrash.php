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
 * Admin › Kelas › Sampah › "Pulihkan" (the lead's decision of 5 Nov 2026, smaga_server only): brings an open trash
 * entry back before its `purge_after`.
 * - A class entry restores the class and every student trashed with it (its open children).
 * - A student entry restores that student; refused while the student's class is in the trash ("Pulihkan kelas X-4
 *   dulu.").
 * Only `deleted_at` is emptied: the account status, password, email, class and every other column stay as they were
 * (sessions ended by the trash stay ended). The entries get `restored_at` / `restored_by`.
 *
 * Authorised with `classroom.manage` (TrashEntryPolicy::restore), one transaction with the entry and the rows locked,
 * ONE audit row `classroom.restored` (log `classroom`: name, level, students) or `student.restored` (log `account`: user
 * ids, count): never a name or NISN. Query builder writes, so the User model's own activity log stays silent.
 */
final class RestoreFromTrash
{
    public const EVENT_CLASSROOM = 'classroom.restored';

    public const EVENT_STUDENT = 'student.restored';

    public const MSG_CLOSED = 'Data ini sudah dipulihkan atau sudah terhapus permanen.';

    public const MSG_EXPIRED = 'Batas 7 hari sudah lewat. Data ini terhapus permanen pada jadwal berikutnya.';

    public const MSG_GONE = 'Data ini sudah tidak ada.';

    private const CHUNK = 500;

    public static function classFirst(string $classroom): string
    {
        return "Pulihkan kelas {$classroom} dulu.";
    }

    /**
     * @return int the number of students brought back
     *
     * @throws ValidationException
     */
    public function handle(User $actor, TrashEntry $entry): int
    {
        Gate::forUser($actor)->authorize('restore', $entry);

        $restored = DB::transaction(function () use ($actor, $entry): int {
            /** @var TrashEntry|null $locked */
            $locked = TrashEntry::query()->lockForUpdate()->find($entry->getKey());
            $now = CarbonImmutable::now();

            if ($locked === null || ! $locked->isOpen()) {
                throw ValidationException::withMessages(['entry' => self::MSG_CLOSED]);
            }

            if (! $locked->isRestorable($now)) {
                throw ValidationException::withMessages(['entry' => self::MSG_EXPIRED]);
            }

            return $locked->isClassroom()
                ? $this->restoreClassroom($actor, $locked, $now)
                : $this->restoreStudent($actor, $locked, $now);
        });

        PublicAchievements::flush();

        return $restored;
    }

    private function restoreClassroom(User $actor, TrashEntry $entry, CarbonImmutable $now): int
    {
        /** @var Classroom|null $classroom */
        $classroom = Classroom::withTrashed()->lockForUpdate()->find($entry->classroom_id);

        if ($classroom === null) {
            throw ValidationException::withMessages(['entry' => self::MSG_GONE]);
        }

        DB::table('classrooms')->where('id', $classroom->getKey())->update(['deleted_at' => null]);

        $children = $entry->children()->open()->lockForUpdate()->get();
        $userIds = $children->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->values()->all();
        $restored = $this->restoreRows($userIds);

        foreach ($children as $child) {
            $this->close($child, $actor, $now);
        }

        $this->close($entry, $actor, $now);

        activity('classroom')
            ->performedOn($classroom)
            ->causedBy($actor)
            ->event(self::EVENT_CLASSROOM)
            ->withProperties([
                'name' => $classroom->name,
                'level' => $classroom->level,
                'students' => $restored,
            ])
            ->log(self::EVENT_CLASSROOM);

        return $restored;
    }

    private function restoreStudent(User $actor, TrashEntry $entry, CarbonImmutable $now): int
    {
        /** @var Student|null $student */
        $student = Student::withTrashed()->where('user_id', $entry->user_id)->lockForUpdate()->first(['id', 'user_id', 'classroom_id', 'deleted_at']);

        if ($student === null || ! User::withTrashed()->whereKey($entry->user_id)->exists()) {
            throw ValidationException::withMessages(['entry' => self::MSG_GONE]);
        }

        /** @var Classroom|null $classroom */
        $classroom = Classroom::withTrashed()->find($student->classroom_id, ['id', 'name', 'deleted_at']);

        if ($classroom !== null && $classroom->trashed()) {
            throw ValidationException::withMessages(['entry' => self::classFirst($classroom->name)]);
        }

        $restored = $this->restoreRows([(int) $entry->user_id]);
        $this->close($entry, $actor, $now);

        activity('account')
            ->causedBy($actor)
            ->event(self::EVENT_STUDENT)
            ->withProperties([
                'user_ids' => [(int) $entry->user_id],
                'count' => $restored,
            ])
            ->log(self::EVENT_STUDENT);

        return $restored;
    }

    /**
     * Empties `deleted_at` of these accounts and their student rows; nothing else changes.
     *
     * @param  list<int>  $userIds
     * @return int students brought back
     */
    private function restoreRows(array $userIds): int
    {
        $restored = 0;

        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            $restored += DB::table('students')->whereIn('user_id', $chunk)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
            DB::table('users')->whereIn('id', $chunk)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
        }

        return $restored;
    }

    private function close(TrashEntry $entry, User $actor, CarbonImmutable $now): void
    {
        $entry->forceFill(['restored_at' => $now, 'restored_by' => (int) $actor->getKey()])->save();
    }
}
