<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Classroom;
use Illuminate\Database\Seeder;

/**
 * X-1…X-10, X-TAHFIDZ, X-OLIMP, XI-1…XI-12, XII-1…XII-12 (docs/TALENT-FORM.md, mockup Kesiswaan-Talenta).
 * Idempotent: safe in production.
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

        foreach (range(1, 10) as $n) {
            $names["X-{$n}"] = 10;
        }

        $names['X-TAHFIDZ'] = 10;
        $names['X-OLIMP'] = 10;

        foreach (range(1, 12) as $n) {
            $names["XI-{$n}"] = 11;
        }

        foreach (range(1, 12) as $n) {
            $names["XII-{$n}"] = 12;
        }

        return $names;
    }
}
