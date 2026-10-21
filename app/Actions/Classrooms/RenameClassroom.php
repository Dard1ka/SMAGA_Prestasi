<?php

declare(strict_types=1);

namespace App\Actions\Classrooms;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Kelas › "Ganti nama": changes only the part after the dash. The tingkat stays, because the archive dates of
 * the class's students were computed from its level (App\Domain\Archive). Every student keeps the same class row;
 * letters already issued keep what their PDF printed. Audited as `classroom.renamed` with the old and new names; the
 * cached public pages (ranking, winners, student pages print the class) are flushed after the commit.
 */
final class RenameClassroom
{
    public const EVENT = 'classroom.renamed';

    public const MSG_SAME = 'Nama kelas belum diubah.';

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Classroom $classroom, string $suffix): Classroom
    {
        Gate::forUser($actor)->authorize('update', $classroom);

        try {
            $renamed = DB::transaction(function () use ($actor, $classroom, $suffix): Classroom {
                ClassroomName::lockAll();

                /** @var Classroom $locked */
                $locked = Classroom::query()->lockForUpdate()->findOrFail($classroom->getKey());

                ClassroomName::assertValid($locked->level, $suffix);
                $from = $locked->name;
                $to = ClassroomName::compose($locked->level, $suffix);

                if ($to === $from) {
                    throw ValidationException::withMessages(['suffix' => self::MSG_SAME]);
                }

                ClassroomName::assertUnique($to, (string) $locked->getKey());

                $locked->forceFill(['name' => $to])->save();

                activity('classroom')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event(self::EVENT)
                    ->withProperties(['from' => $from, 'to' => $to])
                    ->log(self::EVENT);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['suffix' => 'Nama kelas itu sudah dipakai kelas lain.']);
        }

        PublicAchievements::flush();

        return $renamed;
    }
}
