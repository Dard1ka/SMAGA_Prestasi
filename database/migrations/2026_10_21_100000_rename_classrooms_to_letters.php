<?php

declare(strict_types=1);

use App\Livewire\Publik\Data\PublicAchievements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-165 (lead, 21 Okt 2026): classes are named with a letter, X-1 → X-A … X-10 → X-J, XI-1 → XI-A … XI-12 → XI-L,
 * XII-1 → XII-A … XII-12 → XII-L. Renames the rows of an existing database in place, so every student keeps the
 * same classroom row (classroom_id does not change). X-TAHFIDZ, X-OLIMP and any other name stay. A row is left as
 * it is when the lettered name already exists (unique index). A fresh database has no rows here: ClassroomSeeder
 * creates the lettered names. Data only, no schema change. Letters already issued keep what their PDF printed
 * (`letters.printed`), audit rows keep the old names.
 */
return new class extends Migration
{
    private const PREFIXES = ['X', 'XI', 'XII'];

    private const MAX = 12;

    public function up(): void
    {
        $this->rename(fn (string $prefix, int $n): array => ["{$prefix}-{$n}", $prefix.'-'.chr(64 + $n)]);
    }

    public function down(): void
    {
        $this->rename(fn (string $prefix, int $n): array => [$prefix.'-'.chr(64 + $n), "{$prefix}-{$n}"]);
    }

    /** @param  Closure(string, int): array{0: string, 1: string}  $pair  [from, to] */
    private function rename(Closure $pair): void
    {
        $renamed = 0;

        foreach (self::PREFIXES as $prefix) {
            foreach (range(1, self::MAX) as $n) {
                [$from, $to] = $pair($prefix, $n);

                if (DB::table('classrooms')->where('name', $to)->exists()) {
                    continue;
                }

                $renamed += DB::table('classrooms')->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
            }
        }

        // Class names are part of the cached public pages (ranking, winners, student pages).
        if ($renamed > 0 && class_exists(PublicAchievements::class)) {
            PublicAchievements::flush();
        }
    }
};
