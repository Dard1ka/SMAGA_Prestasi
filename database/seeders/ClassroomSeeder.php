<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Classroom;
use Illuminate\Database\Seeder;

/**
 * X-1…X-12, XI-1…XI-12, XII-1…XII-12: 36 classes (smaga_server, D-175, lead 4 Nov 2026: numbers again, twelve per
 * tingkat; D-165 had letters X-A…X-J, XI-A…XI-L, XII-A…XII-L). An existing database is brought to these names by
 * `php artisan sth:kelas:nomor --terapkan` (App\Actions\Classrooms\NumberClassrooms), which renames in place, so every
 * student keeps the row. Other classes are added, renamed and deleted by the Admin on Admin › Kelas
 * (App\Livewire\Admin\Kelas). Idempotent: safe in production; it never deletes or renames a class.
 */
class ClassroomSeeder extends Seeder
{
    /** Classes per tingkat (D-175). */
    public const PER_LEVEL = 12;

    /** level => tingkat prefix. */
    public const LEVELS = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    public function run(): void
    {
        foreach (self::names() as $name => $level) {
            Classroom::query()->updateOrCreate(['name' => $name], ['level' => $level, 'active' => true]);
        }
    }

    /** @return array<string, int> name => level, in the order of Classroom::ordered() */
    public static function names(): array
    {
        $names = [];

        foreach (self::LEVELS as $level => $prefix) {
            foreach (range(1, self::PER_LEVEL) as $n) {
                $names["{$prefix}-{$n}"] = $level;
            }
        }

        return $names;
    }
}
