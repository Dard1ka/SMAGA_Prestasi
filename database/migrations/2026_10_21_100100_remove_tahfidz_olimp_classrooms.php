<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lead, 21 Okt 2026 (after D-165): X-TAHFIDZ and X-OLIMP are not classes of the school. Deletes those two rows when
 * no student row references them (every row of `students` counts: active, pending, archived, disabled and
 * soft-deleted, because `students.classroom_id` is a foreign key). A row that still has students stays; the Admin
 * deletes it on Admin › Kelas once its students moved to another class through the student import. Data only.
 * down() puts the two rows back (level 10, active) when they are missing.
 */
return new class extends Migration
{
    public const NAMES = ['X-TAHFIDZ', 'X-OLIMP'];

    public function up(): void
    {
        foreach (self::NAMES as $name) {
            $id = DB::table('classrooms')->where('name', $name)->value('id');

            if ($id === null || DB::table('students')->where('classroom_id', $id)->exists()) {
                continue;
            }

            DB::table('classrooms')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $name) {
            if (DB::table('classrooms')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('classrooms')->insert([
                'id' => strtolower((string) Str::ulid()), // as HasUlids makes them
                'name' => $name,
                'level' => 10,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
