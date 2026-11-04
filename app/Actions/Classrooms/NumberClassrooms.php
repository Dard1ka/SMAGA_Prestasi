<?php

declare(strict_types=1);

namespace App\Actions\Classrooms;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use Database\Seeders\ClassroomSeeder;
use Illuminate\Support\Facades\DB;

/**
 * D-175 (smaga_server, lead 4 Nov 2026): brings an existing database to the numbered classes X-1…X-12, XI-1…XI-12,
 * XII-1…XII-12 (ClassroomSeeder::names()). Per tingkat and number n, the lettered class of D-165 (A = 1 … L = 12):
 * - only the lettered class exists → renamed in place, so its students keep the row (classroom_id does not change);
 * - both exist (the Admin had already added the numbered one) → the students of the lettered class move to the
 *   numbered one and the lettered row is deleted: one class, never two;
 * - neither exists → the numbered class is created.
 * Every other class (outside these 36 and not a lettered one) is deleted when no student row points at it (soft-deleted
 * and archived rows count, as in DeleteClassroom) and kept otherwise; the lead chose to drop the empty ones.
 * Names are compared like the import does (capitals, no spaces). One audit row `classroom.numbered` with the class
 * names and counts (no student data); the cached public pages are flushed after the commit. Safe to run again: a second
 * run changes nothing.
 */
final class NumberClassrooms
{
    public const EVENT = 'classroom.numbered';

    /**
     * What a run would do, without writing.
     *
     * @return array{renamed: list<string>, merged: list<string>, created: list<string>, deleted: list<string>, kept: list<string>}
     */
    public function preview(): array
    {
        return $this->plan()['summary'];
    }

    /** @return array{renamed: list<string>, merged: list<string>, created: list<string>, deleted: list<string>, kept: list<string>} */
    public function handle(): array
    {
        $summary = DB::transaction(function (): array {
            Classroom::query()->lockForUpdate()->get(['id']);
            $plan = $this->plan();

            foreach ($plan['merge'] as [$source, $target]) {
                DB::table('students')->where('classroom_id', $source->getKey())->update(['classroom_id' => $target->getKey()]);
                $source->delete();
            }

            foreach ($plan['rename'] as [$classroom, $name, $level]) {
                $classroom->forceFill(['name' => $name, 'level' => $level])->save();
            }

            foreach ($plan['create'] as [$name, $level]) {
                Classroom::query()->create(['name' => $name, 'level' => $level, 'active' => true]);
            }

            foreach ($plan['delete'] as $classroom) {
                $classroom->delete();
            }

            if ($plan['changed']) {
                activity('classroom')
                    ->event(self::EVENT)
                    ->withProperties($plan['summary'])
                    ->log(self::EVENT);
            }

            return $plan['summary'] + ['changed' => $plan['changed']];
        });

        if ($summary['changed']) {
            PublicAchievements::flush();
        }

        unset($summary['changed']);

        return $summary;
    }

    /**
     * @return array{
     *     merge: list<array{0: Classroom, 1: Classroom}>,
     *     rename: list<array{0: Classroom, 1: string, 2: int}>,
     *     create: list<array{0: string, 1: int}>,
     *     delete: list<Classroom>,
     *     changed: bool,
     *     summary: array{renamed: list<string>, merged: list<string>, created: list<string>, deleted: list<string>, kept: list<string>},
     * }
     */
    private function plan(): array
    {
        $byKey = Classroom::query()->ordered()->get()->keyBy(fn (Classroom $c): string => ClassroomName::key($c->name));
        $handled = [];
        $plan = ['merge' => [], 'rename' => [], 'create' => [], 'delete' => []];
        $summary = ['renamed' => [], 'merged' => [], 'created' => [], 'deleted' => [], 'kept' => []];

        foreach (ClassroomSeeder::names() as $name => $level) {
            $n = (int) substr($name, strrpos($name, '-') + 1);
            $lettered = ClassroomSeeder::LEVELS[$level].'-'.chr(64 + $n);
            $target = $byKey->get(ClassroomName::key($name));
            $source = $byKey->get(ClassroomName::key($lettered));

            if ($target !== null) {
                $handled[$target->getKey()] = true;
            }

            if ($source !== null) {
                $handled[$source->getKey()] = true;
            }

            if ($source !== null && $target !== null) {
                $students = self::studentRows($source);
                $plan['merge'][] = [$source, $target];
                $summary['merged'][] = "{$source->name} → {$target->name} ({$students} siswa dipindah)";
            } elseif ($source !== null) {
                $plan['rename'][] = [$source, $name, $level];
                $summary['renamed'][] = "{$source->name} → {$name}";
            } elseif ($target === null) {
                $plan['create'][] = [$name, $level];
                $summary['created'][] = $name;
            }
        }

        foreach ($byKey as $classroom) {
            if (isset($handled[$classroom->getKey()])) {
                continue;
            }

            $students = self::studentRows($classroom);

            if ($students === 0) {
                $plan['delete'][] = $classroom;
                $summary['deleted'][] = $classroom->name;
            } else {
                $summary['kept'][] = "{$classroom->name} ({$students} siswa)";
            }
        }

        $plan['changed'] = $plan['merge'] !== [] || $plan['rename'] !== [] || $plan['create'] !== [] || $plan['delete'] !== [];
        $plan['summary'] = $summary;

        return $plan;
    }

    /** Rows of `students` that point at the class, soft-deleted ones too (what DeleteClassroom checks). */
    private static function studentRows(Classroom $classroom): int
    {
        return DB::table('students')->where('classroom_id', $classroom->getKey())->count();
    }
}
