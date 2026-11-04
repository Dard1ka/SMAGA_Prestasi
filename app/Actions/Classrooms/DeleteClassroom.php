<?php

declare(strict_types=1);

namespace App\Actions\Classrooms;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The immediate, permanent delete of a class that no student row references (D-166). Every row of `students` counts
 * (active, pending, archived, disabled and soft-deleted: `students.classroom_id` is a foreign key, and an alumnus keeps
 * the last class on the public pages). Audited as `classroom.deleted` with the name and level; the cached public pages
 * are flushed after the commit.
 *
 * smaga_server since the lead's decision of 5 Nov 2026: Admin › Kelas › "Hapus" puts a class into the trash instead
 * (App\Actions\Trash\TrashClassroom, also an empty one), so no screen calls this Action; it stays for its audit event
 * key. It deletes the row for good (forceDelete: Classroom is soft-deletable now).
 */
final class DeleteClassroom
{
    public const EVENT = 'classroom.deleted';

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Classroom $classroom): void
    {
        Gate::forUser($actor)->authorize('delete', $classroom);

        DB::transaction(function () use ($actor, $classroom): void {
            /** @var Classroom $locked */
            $locked = Classroom::query()->lockForUpdate()->findOrFail($classroom->getKey());

            // The query builder, not the Student model: soft-deleted rows count too.
            $students = DB::table('students')->where('classroom_id', $locked->getKey())->lockForUpdate()->count();

            if ($students > 0) {
                throw ValidationException::withMessages(['classroom' => self::refusal($students)]);
            }

            activity('classroom')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(['name' => $locked->name, 'level' => $locked->level])
                ->log(self::EVENT);

            $locked->forceDelete();
        });

        PublicAchievements::flush();
    }

    public static function refusal(int $students): string
    {
        return "Kelas ini masih berisi {$students} siswa. Pindahkan siswanya lewat import siswa dulu.";
    }
}
