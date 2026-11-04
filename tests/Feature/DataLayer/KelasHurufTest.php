<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The migration of D-165, loaded from its file (it returns an anonymous class). */
function kelasHurufMigration(): object
{
    static $migration = null;

    return $migration ??= require database_path('migrations/2026_10_21_100000_rename_classrooms_to_letters.php');
}

/** The migration that removes X-TAHFIDZ and X-OLIMP (lead, 21 Okt 2026). */
function kelasTahfidzOlimpMigration(): object
{
    static $migration = null;

    return $migration ??= require database_path('migrations/2026_10_21_100100_remove_tahfidz_olimp_classrooms.php');
}

// The seeder of today names the classes with numbers again (D-175): tests/Feature/DataLayer/KelasNomorTest.php. The
// tests below keep checking the two migrations of 21 Okt 2026, which stay in the migration history.

it('renames numbered classes in place so every student keeps the row (D-165)', function (): void {
    $x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $x10 = Classroom::factory()->create(['name' => 'X-10', 'level' => 10]);
    $xi12 = Classroom::factory()->level(11)->create(['name' => 'XI-12']);
    $xii3 = Classroom::factory()->level(12)->create(['name' => 'XII-3']);
    $other = Classroom::factory()->create(['name' => 'X-KHUSUS', 'level' => 10]);
    $student = Student::factory()->create(['classroom_id' => $xii3->getKey()]);

    kelasHurufMigration()->up();

    expect($x1->fresh()->name)->toBe('X-A')
        ->and($x10->fresh()->name)->toBe('X-J')
        ->and($xi12->fresh()->name)->toBe('XI-L')
        ->and($xii3->fresh()->name)->toBe('XII-C')
        ->and($other->fresh()->name)->toBe('X-KHUSUS')
        ->and($student->fresh()->classroom->name)->toBe('XII-C');

    // A second run changes nothing: no duplicate rows.
    kelasHurufMigration()->up();

    expect(Classroom::query()->count())->toBe(5)
        ->and(Classroom::query()->where('name', 'like', '%-1%')->exists())->toBeFalse()
        ->and($student->fresh()->classroom_id)->toBe($xii3->getKey());
});

it('leaves a numbered class alone when its lettered name already exists, and goes back on down()', function (): void {
    $old = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $taken = Classroom::factory()->create(['name' => 'X-B', 'level' => 10]);
    $other = Classroom::factory()->level(11)->create(['name' => 'XI-4']);

    kelasHurufMigration()->up();

    expect($old->fresh()->name)->toBe('X-2')
        ->and($taken->fresh()->name)->toBe('X-B')
        ->and($other->fresh()->name)->toBe('XI-D');

    kelasHurufMigration()->down();

    expect($other->fresh()->name)->toBe('XI-4')
        ->and($old->fresh()->name)->toBe('X-2')
        ->and($taken->fresh()->name)->toBe('X-B');
});

it('deletes X-TAHFIDZ and X-OLIMP when no student row points at them, and keeps a row that has students', function (): void {
    $tahfidz = Classroom::factory()->create(['name' => 'X-TAHFIDZ', 'level' => 10]);
    $olimp = Classroom::factory()->create(['name' => 'X-OLIMP', 'level' => 10]);
    $xa = Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);
    $student = Student::factory()->create(['classroom_id' => $olimp->getKey()]);

    kelasTahfidzOlimpMigration()->up();

    expect(Classroom::query()->whereKey($tahfidz->getKey())->exists())->toBeFalse()
        ->and($olimp->fresh()?->name)->toBe('X-OLIMP')
        ->and($xa->fresh()?->name)->toBe('X-A')
        ->and($student->fresh()->classroom_id)->toBe($olimp->getKey());
});

it('keeps X-TAHFIDZ while an archived or soft-deleted student still points at it', function (string $kind): void {
    $tahfidz = Classroom::factory()->create(['name' => 'X-TAHFIDZ', 'level' => 10]);
    $student = Student::factory()->create(['classroom_id' => $tahfidz->getKey()]);

    if ($kind === 'archived') {
        User::query()->whereKey($student->user_id)->update(['status' => UserStatus::Archived->value]);
    } else {
        $student->delete();
    }

    kelasTahfidzOlimpMigration()->up();

    expect(Classroom::query()->whereKey($tahfidz->getKey())->exists())->toBeTrue();
})->with(['archived', 'soft-deleted']);

it('puts X-TAHFIDZ and X-OLIMP back on down() when they are missing, and never twice', function (): void {
    $olimp = Classroom::factory()->create(['name' => 'X-OLIMP', 'level' => 10]);

    kelasTahfidzOlimpMigration()->down();
    kelasTahfidzOlimpMigration()->down();

    $tahfidz = Classroom::query()->where('name', 'X-TAHFIDZ')->sole();

    expect(Classroom::query()->where('name', 'X-OLIMP')->sole()->getKey())->toBe($olimp->getKey())
        ->and($tahfidz->level)->toBe(10)
        ->and($tahfidz->active)->toBeTrue()
        ->and(strlen((string) $tahfidz->getKey()))->toBe(26);
});
