<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Classroom;
use Illuminate\Database\Seeder;

/**
 * X-A…X-J, XI-A…XI-L, XII-A…XII-L: 34 classes (letters since D-165, before that X-1…X-10 and so on: the migration
 * rename_classrooms_to_letters renames the rows of an existing database). X-TAHFIDZ and X-OLIMP are not classes of
 * the school (lead, 21 Okt 2026): the migration remove_tahfidz_olimp_classrooms deletes their empty rows. Other
 * classes are added, renamed and deleted by the Admin on Admin › Kelas (App\Livewire\Admin\Kelas).
 * Idempotent: safe in production; it never deletes or renames a class.
 */
class ClassroomSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::names() as $name => $level) {
            Classroom::query()->updateOrCreate(['name' => $name], ['level' => $level, 'active' => true]);
        }
    }

    /** @return array<string, int> name => level */
    public static function names(): array
    {
        $names = [];

        foreach (self::letters(10) as $letter) {
            $names["X-{$letter}"] = 10;
        }

        foreach (self::letters(12) as $letter) {
            $names["XI-{$letter}"] = 11;
        }

        foreach (self::letters(12) as $letter) {
            $names["XII-{$letter}"] = 12;
        }

        return $names;
    }

    /** The first $count letters of the alphabet: A, B, C, … (class 1 = A, 10 = J, 12 = L; D-165). @return list<string> */
    public static function letters(int $count): array
    {
        return array_map(static fn (int $n): string => chr(64 + $n), range(1, $count));
    }
}
