<?php

declare(strict_types=1);

namespace App\Actions\Classrooms;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Kelas › "Tambah kelas": a new, active class "<TINGKAT>-<PART>" (rules: ClassroomName). From then on the
 * student import accepts it in the "Kelas" column. Audited as `classroom.created` with name and level.
 */
final class CreateClassroom
{
    public const EVENT = 'classroom.created';

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, int $level, string $suffix): Classroom
    {
        Gate::forUser($actor)->authorize('create', Classroom::class);

        ClassroomName::assertValid($level, $suffix);
        $name = ClassroomName::compose($level, $suffix);

        try {
            return DB::transaction(function () use ($actor, $level, $name): Classroom {
                ClassroomName::lockAll();
                ClassroomName::assertUnique($name);

                $classroom = new Classroom;
                $classroom->forceFill(['name' => $name, 'level' => $level, 'active' => true])->save();

                activity('classroom')
                    ->performedOn($classroom)
                    ->causedBy($actor)
                    ->event(self::EVENT)
                    ->withProperties(['name' => $name, 'level' => $level])
                    ->log(self::EVENT);

                return $classroom;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['suffix' => "Kelas {$name} sudah ada."]);
        }
    }
}
