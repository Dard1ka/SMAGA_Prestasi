<?php

declare(strict_types=1);

use App\Actions\Classrooms\NumberClassrooms;
use App\Livewire\Admin\AuditLog;
use App\Models\Classroom;
use App\Models\Student;
use Database\Seeders\ClassroomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

/*
| D-175 (smaga_server, lead 4 Nov 2026): the classes are X-1…X-12, XI-1…XI-12, XII-1…XII-12 again. ClassroomSeeder
| seeds them; `sth:kelas:nomor` (NumberClassrooms) brings an existing database there: lettered classes renamed in place,
| a lettered class merged into a numbered one the Admin had already added, missing ones created, empty other classes
| deleted, classes with students kept.
*/

uses(RefreshDatabase::class);

it('seeds 36 numbered classes, twelve per tingkat, in natural order', function (): void {
    $this->seed(ClassroomSeeder::class);

    $names = Classroom::query()->ordered()->pluck('name')->all();

    expect($names)->toHaveCount(36)
        ->and(array_slice($names, 0, 12))->toBe(array_map(fn (int $n): string => "X-{$n}", range(1, 12)))
        ->and(array_slice($names, 12, 12))->toBe(array_map(fn (int $n): string => "XI-{$n}", range(1, 12)))
        ->and(array_slice($names, 24))->toBe(array_map(fn (int $n): string => "XII-{$n}", range(1, 12)))
        ->and(preg_grep('/-[A-Z]$/', $names))->toBe([])
        ->and(Classroom::levelFromName('XII-3'))->toBe(12)
        ->and(Classroom::levelFromName('X-12'))->toBe(10);
});

it('orders a longer class name after the numbers of its tingkat', function (): void {
    $this->seed(ClassroomSeeder::class);
    Classroom::factory()->create(['name' => 'X-IPA 1', 'level' => 10]);

    $names = Classroom::query()->ordered()->pluck('name')->all();

    expect($names[12])->toBe('X-IPA 1')
        ->and($names[13])->toBe('XI-1');
});

it('renames lettered classes in place, merges into a numbered class, creates the rest and drops empty others', function (): void {
    // A database of D-165: lettered classes, plus X-4 the Admin added by hand and two other classes.
    $xa = Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);
    $xd = Classroom::factory()->create(['name' => 'X-D', 'level' => 10]);
    $x4 = Classroom::factory()->create(['name' => 'X-4', 'level' => 10]);
    $xil = Classroom::factory()->level(11)->create(['name' => 'XI-L']);
    $xiic = Classroom::factory()->level(12)->create(['name' => 'XII-C']);
    $empty = Classroom::factory()->create(['name' => 'X-KHUSUS', 'level' => 10]);
    $busy = Classroom::factory()->level(11)->create(['name' => 'XI-IPA 1']);

    $inXa = Student::factory()->create(['classroom_id' => $xa->getKey()]);
    $inXd = Student::factory()->create(['classroom_id' => $xd->getKey()]);
    $inX4 = Student::factory()->create(['classroom_id' => $x4->getKey()]);
    $trashed = Student::factory()->create(['classroom_id' => $xiic->getKey()]);
    $trashed->delete();
    Student::factory()->create(['classroom_id' => $busy->getKey()]);

    $preview = app(NumberClassrooms::class)->preview();

    expect($preview['renamed'])->toContain('X-A → X-1', 'XI-L → XI-12', 'XII-C → XII-3')
        ->and($preview['merged'])->toBe(['X-D → X-4 (1 siswa dipindah)'])
        ->and($preview['deleted'])->toBe(['X-KHUSUS'])
        ->and($preview['kept'])->toBe(['XI-IPA 1 (1 siswa)'])
        ->and(Classroom::query()->count())->toBe(7);

    $this->artisan('sth:kelas:nomor', ['--terapkan' => true, '--force' => true])->assertSuccessful();

    $names = Classroom::query()->ordered()->pluck('name')->all();

    expect($names)->toHaveCount(37)
        ->and($names)->toContain('XI-IPA 1')
        ->and($names)->not->toContain('X-KHUSUS', 'X-A', 'X-D', 'XI-L', 'XII-C')
        ->and($xa->fresh()->name)->toBe('X-1')
        ->and($xil->fresh()->name)->toBe('XI-12')
        ->and($xd->fresh())->toBeNull()
        ->and($empty->fresh())->toBeNull()
        // Students keep their row, or move to the class the lettered one merged into.
        ->and($inXa->fresh()->classroom_id)->toBe($xa->getKey())
        ->and($inXd->fresh()->classroom_id)->toBe($x4->getKey())
        ->and($inX4->fresh()->classroom_id)->toBe($x4->getKey())
        ->and(Student::withTrashed()->find($trashed->getKey())->classroom_id)->toBe($xiic->getKey())
        ->and(Activity::query()->where('event', NumberClassrooms::EVENT)->count())->toBe(1)
        ->and(AuditLog::EVENT_LABELS)->toHaveKey(NumberClassrooms::EVENT);

    // A second run finds nothing to do and writes no audit row.
    $this->artisan('sth:kelas:nomor', ['--terapkan' => true, '--force' => true])
        ->expectsOutputToContain('Tidak ada yang diubah')
        ->assertSuccessful();

    expect(Classroom::query()->count())->toBe(37)
        ->and(Activity::query()->where('event', NumberClassrooms::EVENT)->count())->toBe(1);
});

it('changes nothing without --terapkan', function (): void {
    Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);

    $this->artisan('sth:kelas:nomor')
        ->expectsOutputToContain('Pratinjau, tidak ada yang diubah')
        ->expectsOutputToContain('X-A → X-1')
        ->assertSuccessful();

    expect(Classroom::query()->pluck('name')->all())->toBe(['X-A'])
        ->and(Activity::query()->where('event', NumberClassrooms::EVENT)->exists())->toBeFalse();
});

it('refuses --terapkan outside APP_ENV=local without --force', function (): void {
    Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);

    $this->artisan('sth:kelas:nomor', ['--terapkan' => true])
        ->expectsOutputToContain('hanya berjalan bersama --force')
        ->assertFailed();

    expect(Classroom::query()->pluck('name')->all())->toBe(['X-A']);
});

it('applies with --terapkan alone in APP_ENV=local (the school server today)', function (): void {
    Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);
    $this->app['env'] = 'local';

    $this->artisan('sth:kelas:nomor', ['--terapkan' => true])->assertSuccessful();

    expect(Classroom::query()->where('name', 'X-1')->exists())->toBeTrue();
});
