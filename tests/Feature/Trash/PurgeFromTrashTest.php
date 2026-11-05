<?php

declare(strict_types=1);

use App\Actions\Students\PurgeStudents;
use App\Actions\Trash\PurgeFromTrash;
use App\Actions\Trash\PurgeTrash;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashClassroom;
use App\Actions\Trash\TrashStudents;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV6, D-186 (the lead, 5 Nov 2026): Admin › Kelas › Sampah › "Hapus permanen" deletes one entry for good now
 * (App\Actions\Trash\PurgeFromTrash), with the real PurgeStudents. Fictional people only; names and NISN are only compared.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->admin = A::admin(['name' => 'Admin Sekolah Contoh']);
    $this->x4 = Classroom::factory()->create(['name' => 'X-4', 'level' => 10]);
    $this->x5 = Classroom::factory()->create(['name' => 'X-5', 'level' => 10]);
});

function pftSiswa(Classroom $classroom, string $name): Student
{
    $student = Student::factory()->activated()->create(['classroom_id' => $classroom->getKey()]);
    $student->user->forceFill(['name' => $name])->saveQuietly();

    return $student->fresh();
}

/** X-4 with Raka (trashed alone first) and Sinta + Bima (trashed with the class); Dewi of X-5 trashed alone. */
function pftScenario(): array
{
    $t = test();
    $raka = pftSiswa($t->x4, 'Raka Pratama Wijaya');
    $sinta = pftSiswa($t->x4, 'Sinta Ayu Lestari');
    $bima = pftSiswa($t->x4, 'Bima Satria Nugraha');
    $dewi = pftSiswa($t->x5, 'Dewi Kartika Sari');
    Achievement::factory()->create(['student_id' => $sinta->getKey()]);

    $alone = app(TrashStudents::class)->handle($t->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    $other = app(TrashStudents::class)->handle($t->admin, [$dewi->user_id], 'Data ganda dari import.')[0];
    $class = app(TrashClassroom::class)->handle($t->admin, $t->x4, 'Kelas digabung ke kelas lain.');

    return compact('raka', 'sinta', 'bima', 'dewi', 'alone', 'other', 'class');
}

/** Row counts of the tables a purge touches, the open entries and the audit rows: proves that nothing was written. */
function pftSnapshot(): array
{
    $snapshot = [];

    foreach (['users', 'students', 'classrooms', 'achievements', 'activity_log'] as $table) {
        $snapshot[$table] = DB::table($table)->count();
    }

    $snapshot['open'] = TrashEntry::query()->open()->orderBy('id')->pluck('id')->all();

    return $snapshot;
}

function pftAuditRows(): array
{
    return Activity::query()->where('event', PurgeFromTrash::EVENT)->get()->all();
}

// --- Who may ------------------------------------------------------------------------------------------------------

it('refuses "Hapus permanen" to every role but Admin, Kesiswaan with student.trash included', function (RoleName $role): void {
    $s = pftScenario();
    $actor = A::staff($role);
    $before = pftSnapshot();

    if ($role === RoleName::Kesiswaan) {
        expect($actor->can(Permission::StudentTrash->value))->toBeTrue();
    }

    expect(fn () => app(PurgeFromTrash::class)->handle($actor, $s['alone']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PurgeFromTrash::class)->handle($actor, $s['class'], 'X-4'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PurgeFromTrash::class)->preview($actor, $s['class']))->toThrow(AuthorizationException::class)
        ->and(pftSnapshot())->toBe($before);
})->with([RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

it('refuses an Admin who can no longer log in', function (): void {
    $s = pftScenario();
    $disabled = A::admin(['status' => UserStatus::Disabled, 'username' => 'operator.lama']);
    $before = pftSnapshot();

    expect(fn () => app(PurgeFromTrash::class)->handle($disabled, $s['alone']))->toThrow(AuthorizationException::class)
        ->and(pftSnapshot())->toBe($before);
});

// --- A class entry ------------------------------------------------------------------------------------------------

it('purges a class entry with its students and the class\'s students trashed alone, the class row, one audit row without names', function (): void {
    $s = pftScenario();
    $logged = Activity::query()->count();
    $secrets = [];

    foreach ([$s['raka'], $s['sinta'], $s['bima']] as $student) {
        $secrets[] = (string) $student->user->name;
        $secrets[] = (string) $student->nisn;
    }

    $preview = app(PurgeFromTrash::class)->preview($this->admin, $s['class']);

    expect($preview['refusal'])->toBeNull()
        ->and($preview['students'])->toBe(3)
        ->and($preview['alone'])->toBe(1)
        ->and($preview['counts']['students'])->toBe(3)
        ->and($preview['counts']['achievements'])->toBe(1)
        ->and(Activity::query()->count())->toBe($logged);

    $counts = app(PurgeFromTrash::class)->handle($this->admin, $s['class'], '  x - 4 ');

    expect($counts['students'])->toBe(3)
        ->and($counts['users'])->toBe(3)
        ->and($counts['achievements'])->toBe(1)
        ->and(Classroom::withTrashed()->whereKey($this->x4->getKey())->exists())->toBeFalse()
        ->and(Student::withTrashed()->whereKey([$s['raka']->getKey(), $s['sinta']->getKey(), $s['bima']->getKey()])->count())->toBe(0)
        ->and(User::withTrashed()->whereKey([$s['raka']->user_id, $s['sinta']->user_id, $s['bima']->user_id])->count())->toBe(0)
        // Dewi of X-5, trashed alone, stays in the trash and can still be restored.
        ->and(Student::withTrashed()->whereKey($s['dewi']->getKey())->exists())->toBeTrue()
        ->and($s['other']->fresh()->isRestorable())->toBeTrue()
        ->and(Classroom::query()->whereKey($this->x5->getKey())->exists())->toBeTrue();

    $taken = TrashEntry::query()->whereKeyNot($s['other']->getKey())->get();

    expect($taken)->toHaveCount(4);

    foreach ($taken as $entry) {
        expect($entry->purged_at?->toIso8601String())->toBe(now()->toImmutable()->toIso8601String())
            ->and($entry->restored_at)->toBeNull();
    }

    $rows = pftAuditRows();

    expect($rows)->toHaveCount(1)
        ->and(Activity::query()->count())->toBe($logged + 1)
        ->and($rows[0]->log_name)->toBe('account')
        ->and($rows[0]->causer_id)->toBe($this->admin->getKey())
        ->and($rows[0]->properties['via'])->toBe('web')
        ->and($rows[0]->properties['entry'])->toBe((string) $s['class']->getKey())
        ->and($rows[0]->properties['kind'])->toBe(TrashEntry::KIND_CLASSROOM)
        ->and($rows[0]->properties['name'])->toBe('X-4')
        ->and($rows[0]->properties['students'])->toBe(3)
        ->and($rows[0]->properties['purge_after'])->toBe($s['class']->purge_after->toIso8601String())
        ->and($rows[0]->properties['data'])->toBe($counts);

    $text = (string) json_encode($rows[0]->toArray());

    foreach ($secrets as $secret) {
        expect(str_contains($text, $secret))->toBeFalse();
    }

    // "Pulihkan" afterwards finds the entry closed.
    expect(fn () => app(RestoreFromTrash::class)->handle($this->admin, $s['class']->fresh()))
        ->toThrow(ValidationException::class, RestoreFromTrash::MSG_CLOSED);
});

it('purges an empty class entry in its own transaction', function (): void {
    $entry = app(TrashClassroom::class)->handle($this->admin, $this->x5, 'Kelas tidak dipakai lagi.');

    $counts = app(PurgeFromTrash::class)->handle($this->admin, $entry, 'X-5');

    expect($counts)->toBe(array_fill_keys(PurgeStudents::COUNT_KEYS, 0))
        ->and(Classroom::withTrashed()->whereKey($this->x5->getKey())->exists())->toBeFalse()
        ->and($entry->fresh()->purged_at)->not->toBeNull()
        ->and(pftAuditRows())->toHaveCount(1);
});

it('refuses a wrongly typed class name and writes nothing; case and spaces do not count', function (?string $typed): void {
    $s = pftScenario();
    $before = pftSnapshot();

    try {
        app(PurgeFromTrash::class)->handle($this->admin, $s['class'], $typed);
        $this->fail('The purge went through.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([PurgeFromTrash::CONFIRM_KEY => [PurgeFromTrash::MSG_CONFIRM_NAME]]);
    }

    expect(pftSnapshot())->toBe($before);
})->with([[null], [''], ['X-5'], ['X4'], ['XI-4']]);

// --- A student entry ----------------------------------------------------------------------------------------------

it('purges a student entry alone without a typed name and leaves the class and the other students', function (): void {
    $raka = pftSiswa($this->x4, 'Raka Pratama Wijaya');
    $sinta = pftSiswa($this->x4, 'Sinta Ayu Lestari');
    [$rakaEntry, $sintaEntry] = app(TrashStudents::class)->handle($this->admin, [$raka->user_id, $sinta->user_id], 'Data ganda dari import.');

    $counts = app(PurgeFromTrash::class)->handle($this->admin, $rakaEntry);

    expect($counts['students'])->toBe(1)
        ->and(Student::withTrashed()->whereKey($raka->getKey())->exists())->toBeFalse()
        ->and(Student::withTrashed()->whereKey($sinta->getKey())->exists())->toBeTrue()
        ->and(Classroom::query()->whereKey($this->x4->getKey())->exists())->toBeTrue()
        ->and($rakaEntry->fresh()->purged_at)->not->toBeNull()
        ->and($sintaEntry->fresh()->isRestorable())->toBeTrue();

    $rows = pftAuditRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->properties['kind'])->toBe(TrashEntry::KIND_STUDENT)
        ->and($rows[0]->properties['name'] ?? null)->toBeNull()
        ->and($rows[0]->properties['students'])->toBe(1);
});

// --- Refused, closed, not found -----------------------------------------------------------------------------------

it('rethrows a refusal of the purge, writes nothing and leaves the entry restorable', function (): void {
    $s = pftScenario();
    DB::table('role_change_requests')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $s['sinta']->user_id,
        'from_roles' => json_encode(['siswa']),
        'to_role' => RoleName::Guru->value,
        'requested_by' => $this->admin->getKey(),
        'reason' => 'Contoh perubahan peran.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $purgeAfter = $s['class']->purge_after->toIso8601String();
    $before = pftSnapshot();

    expect(app(PurgeFromTrash::class)->preview($this->admin, $s['class'])['refusal'])->toStartWith('1 baris riwayat');

    expect(fn () => app(PurgeFromTrash::class)->handle($this->admin, $s['class'], 'X-4'))
        ->toThrow(DomainException::class, '1 baris riwayat');

    expect(pftSnapshot())->toBe($before)
        ->and($s['class']->fresh()->purge_after->toIso8601String())->toBe($purgeAfter)
        ->and($s['class']->fresh()->isRestorable())->toBeTrue()
        ->and(app(RestoreFromTrash::class)->handle($this->admin, $s['class']->fresh()))->toBe(2);
});

it('answers an entry restored or purged elsewhere with the closed message and purges nothing', function (): void {
    $s = pftScenario();
    $stale = TrashEntry::query()->findOrFail($s['class']->getKey());
    app(RestoreFromTrash::class)->handle($this->admin, $s['class']);
    $before = pftSnapshot();

    expect(fn () => app(PurgeFromTrash::class)->handle($this->admin, $stale, 'X-4'))
        ->toThrow(ValidationException::class, RestoreFromTrash::MSG_CLOSED)
        ->and(pftSnapshot())->toBe($before);

    $staleOther = TrashEntry::query()->findOrFail($s['other']->getKey());
    app(PurgeFromTrash::class)->handle($this->admin, $s['other']);
    $before = pftSnapshot();

    expect(fn () => app(PurgeFromTrash::class)->handle($this->admin, $staleOther))
        ->toThrow(ValidationException::class, RestoreFromTrash::MSG_CLOSED)
        ->and(pftSnapshot())->toBe($before)
        ->and(pftAuditRows())->toHaveCount(1);
});

it('answers the closed message when a student left the trash between the read and the lock, and purges nothing', function (): void {
    $s = pftScenario();
    $bima = $s['bima'];

    // A stand-in that lets another run purge Bima just before the real purge takes its locks.
    app()->instance(PurgeStudents::class, new class($bima)
    {
        public function __construct(private readonly Student $bima) {}

        public function handleWithin(array $ids, Closure $before, Closure $after): array
        {
            DB::table('model_has_roles')->where('model_id', $this->bima->user_id)->delete();
            DB::table('students')->where('id', $this->bima->getKey())->delete();
            DB::table('users')->where('id', $this->bima->user_id)->delete();

            return (new PurgeStudents)->handleWithin($ids, $before, $after);
        }
    });
    $logged = Activity::query()->count();

    expect(fn () => app(PurgeFromTrash::class)->handle($this->admin, $s['class'], 'X-4'))
        ->toThrow(ValidationException::class, RestoreFromTrash::MSG_CLOSED);

    expect(Student::withTrashed()->whereKey([$s['raka']->getKey(), $s['sinta']->getKey()])->count())->toBe(2)
        ->and(Classroom::withTrashed()->whereKey($this->x4->getKey())->exists())->toBeTrue()
        ->and(TrashEntry::query()->open()->count())->toBe(5)
        ->and(Activity::query()->count())->toBe($logged);
});

it('answers 404 for a child entry and an unknown id', function (): void {
    $s = pftScenario();
    $child = TrashEntry::query()->where('parent_id', $s['class']->getKey())->firstOrFail();
    $before = pftSnapshot();

    expect(fn () => PurgeFromTrash::entry((string) $child->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => PurgeFromTrash::entry((string) Str::ulid()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(PurgeFromTrash::class)->handle($this->admin, $child))->toThrow(ModelNotFoundException::class)
        ->and(PurgeFromTrash::entry((string) $s['class']->getKey())->getKey())->toBe($s['class']->getKey())
        ->and(pftSnapshot())->toBe($before);
});

// --- The 02:00 run ------------------------------------------------------------------------------------------------

it('leaves nothing for the 02:00 run afterwards and no second audit row', function (): void {
    $s = pftScenario();
    app(PurgeFromTrash::class)->handle($this->admin, $s['class'], 'X-4');
    app(PurgeFromTrash::class)->handle($this->admin, $s['other']);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 02:00:00', 'Asia/Jakarta'));
    $result = app(PurgeTrash::class)->handle();

    expect($result['entries'])->toBe(0)
        ->and($result['siswa'])->toBe(0)
        ->and($result['kelas'])->toBe(0)
        ->and(Activity::query()->where('event', PurgeTrash::EVENT)->count())->toBe(0)
        ->and(pftAuditRows())->toHaveCount(2);
});

it('makes the 02:00 run skip an entry an Admin purged at the same moment', function (): void {
    $raka = pftSiswa($this->x4, 'Raka Pratama Wijaya');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:00', 'Asia/Jakarta'));
    $manual = CarbonImmutable::parse('2026-11-13 01:59:59', 'Asia/Jakarta');

    // The run read the entry as due; the Admin's purge commits before the run closes it.
    app()->instance(PurgeStudents::class, new class($entry, $manual)
    {
        public function __construct(private readonly TrashEntry $entry, private readonly CarbonImmutable $at) {}

        public function handle(array $studentIds): array
        {
            $users = DB::table('students')->whereIn('id', $studentIds)->pluck('user_id')->all();
            DB::table('model_has_roles')->whereIn('model_id', $users)->delete();
            DB::table('students')->whereIn('id', $studentIds)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
            DB::table('trash_entries')->where('id', $this->entry->getKey())->update(['purged_at' => $this->at]);

            return ['students' => count($studentIds)];
        }
    });

    $result = app(PurgeTrash::class)->handle();

    expect($result['entries'])->toBe(0)
        ->and($result['siswa'])->toBe(0)
        ->and(Activity::query()->where('event', PurgeTrash::EVENT)->count())->toBe(0)
        ->and($entry->fresh()->purged_at->toIso8601String())->toBe($manual->toIso8601String());
});
