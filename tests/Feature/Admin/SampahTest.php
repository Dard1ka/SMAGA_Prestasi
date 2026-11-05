<?php

declare(strict_types=1);

use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\StudentImportReport;
use App\Actions\Classrooms\ClassroomName;
use App\Actions\Classrooms\CreateClassroom;
use App\Actions\Classrooms\NumberClassrooms;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Students\PurgeStudents;
use App\Actions\Trash\PurgeTrash;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashClassroom;
use App\Actions\Trash\TrashStudents;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\HapusSiswa;
use App\Livewire\Admin\Kelas;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 * smaga_server, the lead's decision of 5 Nov 2026 (replaces the "only an empty class" rule of D-166 here): the Admin
 * deletes classes and students into the trash of Admin › Kelas. For 7 days they are hidden everywhere and the students
 * cannot log in; "Pulihkan" brings them back unchanged; then `sth:sampah:hapus-permanen` deletes them for good
 * (the data itself: App\Actions\Students\PurgeStudents, tested in tests/Feature/Students/PurgeStudentsTest.php; here it is
 * replaced by a fake that deletes the two rows). Fictional people only; names and NISN are only compared.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->admin = A::admin(['name' => 'Admin Sekolah Contoh']);
    $this->x4 = Classroom::factory()->create(['name' => 'X-4', 'level' => 10]);
    $this->x5 = Classroom::factory()->create(['name' => 'X-5', 'level' => 10]);
});

/** An activated student (password "password") of $classroom named $name. */
function sampahSiswa(Classroom $classroom, string $name, array $user = []): Student
{
    $student = Student::factory()->activated()->create(['classroom_id' => $classroom->getKey()]);
    $student->user->forceFill(['name' => $name, ...$user])->saveQuietly();

    return $student->fresh();
}

/** The Admin on the Kelas screen with a password confirmation from just now. */
function sampahScreen(): Testable
{
    test()->actingAs(test()->admin);
    session(A::passwordConfirmed());

    return Livewire::test(Kelas::class);
}

/**
 * Binds a stand-in for App\Actions\Students\PurgeStudents: records the ids and, when $deletes, removes the students'
 * rows and accounts the way the real purge ends. A call that holds $refuse throws the purge's DomainException and
 * writes nothing.
 */
function sampahFakePurge(bool $deletes = true, ?string $refuse = null): object
{
    $fake = new class($deletes, $refuse)
    {
        /** @var list<list<string>> */
        public array $calls = [];

        public function __construct(private readonly bool $deletes, private readonly ?string $refuse) {}

        /** @param list<string> $studentIds */
        public function handle(array $studentIds): array
        {
            $this->calls[] = $studentIds;

            if ($this->refuse !== null && in_array($this->refuse, $studentIds, true)) {
                throw new DomainException('1 pendaftaran kehilangan ketua tanpa anggota lain yang bisa menggantikannya. Tidak ada yang dihapus.');
            }

            if (! $this->deletes) {
                return ['students' => 0];
            }

            $users = DB::table('students')->whereIn('id', $studentIds)->pluck('user_id')->all();
            DB::table('model_has_roles')->whereIn('model_id', $users)->delete();
            DB::table('students')->whereIn('id', $studentIds)->delete();
            DB::table('users')->whereIn('id', $users)->delete();

            return ['students' => count($studentIds), 'users' => count($users)];
        }
    };

    app()->instance(PurgeStudents::class, $fake);

    return $fake;
}

function sampahCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'sth-sampah-');
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas', 'No HP'], ',', '"', '');

    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }

    fclose($handle);

    return $path;
}

// --- Who may ------------------------------------------------------------------------------------------------------

it('refuses a class, students and restore to roles without classroom.manage or student.trash', function (RoleName $role): void {
    $student = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $entry = app(TrashStudents::class)->handle($this->admin, [$student->user_id], 'Data ganda dari import.')[0];
    $other = sampahSiswa($this->x5, 'Bima Satria Nugraha');
    $actor = A::staff($role);

    expect(fn () => app(TrashStudents::class)->handle($actor, [$other->user_id], 'Data ganda dari import.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TrashClassroom::class)->handle($actor, $this->x5, 'Kelas digabung ke kelas lain.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RestoreFromTrash::class)->handle($actor, $entry))->toThrow(AuthorizationException::class)
        ->and(Student::query()->whereKey($other->getKey())->exists())->toBeTrue()
        ->and(Classroom::query()->whereKey($this->x5->getKey())->exists())->toBeTrue()
        ->and($entry->fresh()->restored_at)->toBeNull();
})->with([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

it('lets Kesiswaan and Tata Usaha put students and a class into the trash, see the Sampah and restore (2026-11-05)', function (RoleName $role): void {
    $staff = A::staff($role);
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');

    expect($staff->can('trashStudents', TrashEntry::class))->toBeTrue()
        ->and($staff->can('create', TrashEntry::class))->toBeTrue()
        ->and($staff->can('viewAny', TrashEntry::class))->toBeTrue();

    $entry = app(TrashStudents::class)->handle($staff, [$raka->user_id], 'Data ganda dari import.')[0];

    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse()
        ->and($entry->deleted_by)->toBe((int) $staff->getKey())
        ->and(Activity::query()->where('event', TrashStudents::EVENT)->sole()->causer_id)->toBe($staff->getKey());

    app(RestoreFromTrash::class)->handle($staff, $entry);
    $class = app(TrashClassroom::class)->handle($staff, $this->x5, 'Kelas digabung ke kelas lain.');

    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue()
        ->and(Classroom::query()->whereKey($this->x5->getKey())->exists())->toBeFalse()
        ->and($class->deleted_by)->toBe((int) $staff->getKey());
})->with([RoleName::Kesiswaan, RoleName::TataUsaha]);

it('refuses students to a Kesiswaan account whose role lost student.trash and classroom.manage, and still lets an Admin without student.trash (classroom.manage)', function (): void {
    $kesiswaan = A::staff(RoleName::Kesiswaan);
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $bima = sampahSiswa($this->x4, 'Bima Satria Nugraha');
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(['student.trash', 'classroom.manage']);
    Role::findByName(RoleName::Admin->value)->revokePermissionTo('student.trash');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => app(TrashStudents::class)->handle($kesiswaan->fresh(), [$raka->user_id], 'Data ganda dari import.'))->toThrow(AuthorizationException::class)
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue();

    app(TrashStudents::class)->handle($this->admin->fresh(), [$bima->user_id], 'Data ganda dari import.');
    expect(Student::query()->whereKey($bima->getKey())->exists())->toBeFalse();
});

it('refuses the trash to an Admin who can no longer log in', function (): void {
    $student = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $disabled = A::admin(['status' => UserStatus::Disabled, 'username' => 'operator.lama']);

    expect(fn () => app(TrashStudents::class)->handle($disabled, [$student->user_id], 'Data ganda dari import.'))->toThrow(AuthorizationException::class);
});

it('sends a guest from Admin › Kelas to the login page and refuses the screen to Humas', function (): void {
    $this->get(route('admin.kelas.index'))->assertRedirect(route('login'));

    $this->actingAs(A::staff(RoleName::Humas));
    Livewire::test(Kelas::class)->assertForbidden();
});

// --- TrashStudents ------------------------------------------------------------------------------------------------

it('puts students into the trash: rows hidden, sessions ended, one entry each, one audit row without names', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $sinta = sampahSiswa($this->x4, 'Sinta Ayu Lestari', ['status' => UserStatus::Archived]);
    $stays = sampahSiswa($this->x4, 'Bima Satria Nugraha');
    H::fakeSessions($raka->user);
    $logged = Activity::query()->count();

    $entries = app(TrashStudents::class)->handle($this->admin, [$sinta->user_id, (string) $raka->user_id], '  Data ganda dari import.  ');

    expect($entries)->toHaveCount(2)
        ->and(Student::query()->whereKey([$raka->getKey(), $sinta->getKey()])->count())->toBe(0)
        ->and(User::query()->whereKey([$raka->user_id, $sinta->user_id])->count())->toBe(0)
        ->and(Student::withTrashed()->whereKey([$raka->getKey(), $sinta->getKey()])->whereNotNull('deleted_at')->count())->toBe(2)
        ->and(User::withTrashed()->find($sinta->user_id)->status)->toBe(UserStatus::Archived)
        ->and(Student::query()->whereKey($stays->getKey())->exists())->toBeTrue()
        ->and(H::sessionCount($raka->user))->toBe(0);

    foreach ($entries as $entry) {
        expect($entry->fresh())
            ->kind->toBe(TrashEntry::KIND_STUDENT)
            ->parent_id->toBeNull()
            ->classroom_id->toBe((string) $this->x4->getKey())
            ->deleted_by->toBe((int) $this->admin->getKey())
            ->reason->toBe('Data ganda dari import.')
            ->and($entry->purge_after->format('Y-m-d H:i'))->toBe('2026-11-13 02:00');
    }

    $rows = Activity::query()->orderBy('id')->get()->slice($logged)->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->event)->toBe(TrashStudents::EVENT)
        ->and($rows[0]->log_name)->toBe('account')
        ->and($rows[0]->causer_id)->toBe($this->admin->getKey())
        ->and($rows[0]->properties['user_ids'])->toBe([(int) $raka->user_id, (int) $sinta->user_id])
        ->and($rows[0]->properties['count'])->toBe(2)
        ->and($rows[0]->properties['reason'])->toBe('Data ganda dari import.');

    $text = (string) json_encode($rows[0]->toArray());

    foreach ([$raka, $sinta] as $student) {
        expect(str_contains($text, (string) $student->user->name))->toBeFalse()
            ->and(str_contains($text, (string) $student->nisn))->toBeFalse();
    }
});

it('refuses a short reason, an empty list, a student already in the trash and a staff account', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $trash = app(TrashStudents::class);

    $error = function (Closure $call): ?string {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    };

    expect($error(fn () => $trash->handle($this->admin, [$raka->user_id], 'Ganda')))->toBe('Alasan minimal 10 karakter.')
        ->and($error(fn () => $trash->handle($this->admin, [], 'Data ganda dari import.')))->toBe(TrashStudents::MSG_NONE)
        ->and($error(fn () => $trash->handle($this->admin, [$this->admin->getKey()], 'Data ganda dari import.')))->toBe(TrashStudents::MSG_CHANGED);

    $trash->handle($this->admin, [$raka->user_id], 'Data ganda dari import.');

    expect($error(fn () => $trash->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')))->toBe(TrashStudents::MSG_CHANGED)
        ->and(TrashEntry::query()->count())->toBe(1);
});

it('locks a trashed student out of the login and lets the same password in again after Pulihkan, status unchanged', function (): void {
    $student = H::activeStudent();
    $entry = app(TrashStudents::class)->handle($this->admin, [$student->user_id], 'Data ganda dari import.')[0];
    Auth::forgetGuards();

    $this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->from('/login')->post('/login', ['identifier' => 'nadia.anindya@example.test', 'password' => H::PASSWORD])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();

    $passwordBefore = User::withTrashed()->find($student->user_id)->password;
    app(RestoreFromTrash::class)->handle($this->admin, $entry);
    $restored = User::query()->findOrFail($student->user_id);

    expect($restored->status)->toBe(UserStatus::Active)
        ->and($restored->password)->toBe($passwordBefore)
        ->and($restored->email)->toBe('nadia.anindya@example.test');

    $this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($restored);
});

// --- TrashClassroom -----------------------------------------------------------------------------------------------

it('puts a class into the trash with every student of any status as child entries', function (): void {
    $alone = sampahSiswa($this->x4, 'Dewi Kartika Sari');
    $aloneEntry = app(TrashStudents::class)->handle($this->admin, [$alone->user_id], 'Siswa pindah sekolah lain.')[0];

    $students = [
        sampahSiswa($this->x4, 'Raka Pratama Wijaya'),
        sampahSiswa($this->x4, 'Sinta Ayu Lestari', ['status' => UserStatus::PendingActivation]),
        sampahSiswa($this->x4, 'Bima Satria Nugraha', ['status' => UserStatus::Archived]),
        sampahSiswa($this->x4, 'Galang Putra Mahendra', ['status' => UserStatus::Disabled]),
    ];
    $other = sampahSiswa($this->x5, 'Nina Larasati Putri');

    $entry = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    expect($entry->kind)->toBe(TrashEntry::KIND_CLASSROOM)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->children()->count())->toBe(4)
        ->and($entry->children()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect($students)->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->and($aloneEntry->fresh()->parent_id)->toBeNull()
        ->and(Classroom::query()->whereKey($this->x4->getKey())->exists())->toBeFalse()
        ->and(Classroom::withTrashed()->find($this->x4->getKey())->trashed())->toBeTrue()
        ->and(Student::query()->where('classroom_id', $this->x4->getKey())->count())->toBe(0)
        ->and(User::query()->whereKey(collect($students)->pluck('user_id'))->count())->toBe(0)
        ->and(Student::query()->whereKey($other->getKey())->exists())->toBeTrue();

    $row = Activity::query()->where('event', TrashClassroom::EVENT)->sole();

    expect($row->properties->only(['name', 'level', 'students', 'reason'])->all())
        ->toBe(['name' => 'X-4', 'level' => 10, 'students' => 4, 'reason' => 'Kelas digabung ke kelas X-5.'])
        ->and($row->causer_id)->toBe($this->admin->getKey());
});

it('puts an empty class into the trash the same way and keeps its name taken', function (): void {
    $entry = app(TrashClassroom::class)->handle($this->admin, $this->x5, 'Kelas ini tidak dipakai lagi.');

    expect($entry->children()->count())->toBe(0)
        ->and(Classroom::query()->whereKey($this->x5->getKey())->exists())->toBeFalse();

    try {
        app(CreateClassroom::class)->handle($this->admin, 10, '5');
        $message = null;
    } catch (ValidationException $e) {
        $message = $e->errors()['suffix'][0];
    }

    expect($message)->toBe(ClassroomName::inTrash('X-5'))
        ->and(Classroom::withTrashed()->where('name', 'X-5')->count())->toBe(1);
});

// --- RestoreFromTrash ---------------------------------------------------------------------------------------------

it('restores a class with its students: status, password and email unchanged, audited', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $bima = sampahSiswa($this->x4, 'Bima Satria Nugraha', ['status' => UserStatus::Disabled]);
    $before = User::query()->whereKey([$raka->user_id, $bima->user_id])->orderBy('id')->get(['id', 'status', 'password', 'email'])->toArray();
    $entry = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    expect(app(RestoreFromTrash::class)->handle($this->admin, $entry))->toBe(2);

    expect(Classroom::query()->whereKey($this->x4->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey([$raka->user_id, $bima->user_id])->orderBy('id')->get(['id', 'status', 'password', 'email'])->toArray())->toBe($before)
        ->and(Student::query()->where('classroom_id', $this->x4->getKey())->count())->toBe(2)
        ->and($entry->fresh()->restored_at)->not->toBeNull()
        ->and($entry->fresh()->restored_by)->toBe((int) $this->admin->getKey())
        ->and($entry->children()->whereNull('restored_at')->count())->toBe(0);

    $row = Activity::query()->where('event', RestoreFromTrash::EVENT_CLASSROOM)->sole();

    expect($row->properties->only(['name', 'level', 'students'])->all())->toBe(['name' => 'X-4', 'level' => 10, 'students' => 2]);
});

it('restores a student alone, but not while the class is in the trash', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Siswa salah kelas.')[0];
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    expect(fn () => app(RestoreFromTrash::class)->handle($this->admin, $entry))
        ->toThrow(ValidationException::class, 'Pulihkan kelas X-4 dulu.');

    app(RestoreFromTrash::class)->handle($this->admin, $class);

    // The student trashed alone stays in the trash with the class back, then comes back on its own.
    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse();

    expect(app(RestoreFromTrash::class)->handle($this->admin, $entry))->toBe(1)
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('event', RestoreFromTrash::EVENT_STUDENT)->sole()->properties['user_ids'])->toBe([(int) $raka->user_id]);
});

it('refuses a restore from purge_after on and of an entry already restored', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $late = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Siswa salah kelas.')[0];
    $bima = sampahSiswa($this->x5, 'Bima Satria Nugraha');
    $done = app(TrashStudents::class)->handle($this->admin, [$bima->user_id], 'Siswa salah kelas.')[0];
    app(RestoreFromTrash::class)->handle($this->admin, $done);

    expect(fn () => app(RestoreFromTrash::class)->handle($this->admin, $done))->toThrow(ValidationException::class, RestoreFromTrash::MSG_CLOSED);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:00', 'Asia/Jakarta'));

    expect(fn () => app(RestoreFromTrash::class)->handle($this->admin, $late))->toThrow(ValidationException::class, RestoreFromTrash::MSG_EXPIRED)
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse();
});

// --- Hidden everywhere --------------------------------------------------------------------------------------------

it('hides a trashed student from Status aktivasi, the Kelas counts, Peringkat publik, Database prestasi and Talenta until Pulihkan', function (): void {
    $name = 'Raka Pratama Wijaya';
    $raka = sampahSiswa($this->x4, $name);
    Achievement::factory()->create(['student_id' => $raka->getKey()]);
    TalentApplication::factory()->create(['student_id' => $raka->getKey()]);
    $kesiswaan = A::staff(RoleName::Kesiswaan);

    $visible = function (bool $expected) use ($raka, $name, $kesiswaan): void {
        $rows = app(ActivationReport::class)->rows();
        expect($rows->pluck('userId')->contains((int) $raka->user_id))->toBe($expected, 'Status aktivasi');

        $this->actingAs($this->admin);
        $groups = Livewire::test(Kelas::class)->viewData('groups');
        expect($groups[0]['rows'][0])->toMatchArray(['name' => 'X-4', 'active' => $expected ? 1 : 0, 'total' => $expected ? 1 : 0]);

        Auth::forgetGuards();
        $page = $this->get(route('public.peringkat'))->assertOk();
        $expected ? $page->assertSee($name) : $page->assertDontSee($name);

        $this->actingAs($kesiswaan);
        $prestasi = Livewire::test(DatabasePrestasi::class);
        $expected ? $prestasi->assertSee($name) : $prestasi->assertDontSee($name);

        $talenta = Livewire::test(Talenta::class);
        $expected ? $talenta->assertSee($name) : $talenta->assertDontSee($name);
    };

    $visible(true);

    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    $visible(false);

    app(RestoreFromTrash::class)->handle($this->admin, $entry);
    $visible(true);
});

it('skips a row of a student in the trash in the import, never creating a second account', function (): void {
    $raka = sampahSiswa($this->x5, 'Raka Pratama Wijaya');
    app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.');

    $report = app(ImportStudents::class)->handle($this->admin, sampahCsv([
        ['Raka Pratama Wijaya', $raka->nis, $raka->nisn, '02-03-2010', 'X-5', ''],
    ]), 'csv', '2026/2027', dryRun: false);

    expect($report->rows[0])->toMatchArray([
        'action' => StudentImportReport::SKIPPED,
        'errors' => [],
        'notes' => ['Siswa ini ada di Sampah. Pulihkan dulu atau tunggu terhapus permanen.'],
        'changes' => false,
    ])
        ->and($report->created)->toBe(0)
        ->and(Student::withTrashed()->where('nisn', $raka->nisn)->count())->toBe(1)
        ->and(Student::withTrashed()->find($raka->getKey())->trashed())->toBeTrue();
});

it('neither imports into nor recreates a class in the trash', function (): void {
    app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    $report = app(ImportStudents::class)->handle($this->admin, sampahCsv([
        ['Nina Larasati Putri', '225226299', '0098765499', '02-03-2010', 'X-4', ''],
    ]), 'csv', '2026/2027');

    expect($report->rows[0]['errors'])->toContain('Kelas tidak ditemukan.')
        ->and(app(NumberClassrooms::class)->preview()['created'])->not->toContain('X-4');

    app(NumberClassrooms::class)->handle();

    expect(Classroom::withTrashed()->where('name', 'X-4')->count())->toBe(1)
        ->and(Classroom::withTrashed()->where('name', 'X-4')->sole()->trashed())->toBeTrue()
        ->and(Classroom::query()->where('name', 'X-5')->exists())->toBeTrue();
});

// --- Admin › Kelas: a class ---------------------------------------------------------------------------------------

it('offers Hapus on every class row, also one with students', function (): void {
    sampahSiswa($this->x4, 'Raka Pratama Wijaya');

    sampahScreen()
        ->assertSeeHtml("wire:click=\"askDelete('{$this->x4->getKey()}')\"")
        ->assertSeeHtml("wire:click=\"askDelete('{$this->x5->getKey()}')\"");
});

it('asks before a class goes to the trash, naming its students and the purge date, and needs a reason', function (): void {
    sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    sampahSiswa($this->x4, 'Sinta Ayu Lestari', ['status' => UserStatus::Archived]);

    sampahScreen()
        ->call('askDelete', $this->x4->getKey())
        ->assertSet('deletingId', (string) $this->x4->getKey())
        ->assertSet('deletingStudents', 2)
        ->assertDispatched('sth-modal-open', id: Kelas::DELETE_DIALOG)
        ->assertSee('Hapus kelas X-4?')
        ->assertSee('Kelas ini berisi 2 siswa. Kelas dan semua siswanya dipindahkan ke Sampah: siswanya langsung tidak bisa masuk dan tidak tampil di mana pun.')
        ->assertSee('Selama 7 hari masih bisa dipulihkan. Mulai 13 November 2026 kelas dan seluruh data siswanya (akun, pendaftaran, surat tugas, laporan hasil, prestasi, talenta, file) terhapus permanen.')
        ->call('delete')
        ->assertHasErrors(['classReason'])
        ->assertSet('deletingId', (string) $this->x4->getKey())
        ->set('classReason', 'Kelas digabung ke kelas X-5.')
        ->call('delete')
        ->assertHasNoErrors()
        ->assertSet('deletingId', null)
        ->assertDispatched('sth-modal-close', id: Kelas::DELETE_DIALOG)
        ->assertDispatched('sth-toast', message: 'Kelas X-4 dan 2 siswanya dipindahkan ke Sampah.', tone: 'success')
        ->assertSee('2 siswa ikut dihapus')
        ->assertSee('Terhapus permanen 13 November 2026')
        ->assertSee('oleh Admin Sekolah Contoh');

    expect(Classroom::query()->whereKey($this->x4->getKey())->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(0);
});

it('uses the short sentence for an empty class', function (): void {
    sampahScreen()
        ->call('askDelete', $this->x5->getKey())
        ->assertSee('Kelas ini belum berisi siswa. Kelas dipindahkan ke Sampah dan tidak bisa dipakai import siswa.')
        ->assertSee('Mulai 13 November 2026 kelas ini terhapus permanen.')
        ->set('classReason', 'Kelas ini tidak dipakai lagi.')
        ->call('delete')
        ->assertDispatched('sth-toast', message: 'Kelas X-5 dipindahkan ke Sampah.', tone: 'success');
});

it('does nothing on delete, the shared "Hapus siswa" or restore without their question', function (): void {
    sampahSiswa($this->x4, 'Raka Pratama Wijaya');

    sampahScreen()
        ->set('classReason', 'Kelas digabung ke kelas X-5.')
        ->call('delete')
        ->call('restore')
        ->assertNotDispatched('sth-toast')
        ->assertHasNoErrors();

    Livewire::test(HapusSiswa::class)
        ->set('reason', 'Data ganda dari import.')
        ->call('trash')
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched(HapusSiswa::DONE_EVENT)
        ->assertHasNoErrors();

    expect(TrashEntry::query()->count())->toBe(0)
        ->and(Classroom::query()->count())->toBe(2);
});

it('sends the Admin to the password confirmation before asking and changes nothing', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->call('askDelete', $this->x4->getKey())
        ->assertRedirect(route('password.confirm'))
        ->assertSet('deletingId', null);

    expect(Classroom::query()->count())->toBe(2);
});

it('answers a class trashed, renamed or filled in another tab with a toast, never an error page', function (string $change): void {
    $screen = sampahScreen()->call('askDelete', $this->x4->getKey())->set('classReason', 'Kelas digabung ke kelas X-5.');

    match ($change) {
        'trashed' => app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung di tab lain.'),
        'renamed' => $this->x4->forceFill(['name' => 'X-14'])->save(),
        'filled' => sampahSiswa($this->x4, 'Raka Pratama Wijaya'),
    };

    $screen->call('delete')
        ->assertOk()
        ->assertSet('deletingId', null)
        ->assertDispatched('sth-toast', message: match ($change) {
            'trashed' => Kelas::MSG_GONE,
            'renamed' => Kelas::MSG_RENAMED,
            'filled' => Kelas::MSG_CLASS_CHANGED,
        }, tone: 'warning');

    expect(TrashEntry::query()->whereNull('parent_id')->count())->toBe($change === 'trashed' ? 1 : 0);
})->with(['trashed', 'renamed', 'filled']);

// --- Admin › Kelas: students --------------------------------------------------------------------------------------

it('puts one student or the ticked ones into the trash from Lihat siswa through the shared dialog (SV6)', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $sinta = sampahSiswa($this->x4, 'Sinta Ayu Lestari');
    $bima = sampahSiswa($this->x4, 'Bima Satria Nugraha');

    $screen = sampahScreen()
        ->call('showStudents', $this->x4->getKey())
        ->assertSeeHtml("wire:click=\"askTrashStudent({$raka->user_id})\"")
        ->call('askTrashStudent', $raka->user_id)
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $raka->user_id]);

    // The browser hands the event to the dialog.
    Livewire::test(HapusSiswa::class, ['returnTo' => route('admin.kelas.index')])
        ->call('open', [(int) $raka->user_id])
        ->assertSet('asked', [(int) $raka->user_id => (string) $this->x4->getKey()])
        ->assertDispatched('sth-modal-open', id: HapusSiswa::DIALOG)
        ->assertSee('Hapus 1 siswa?')
        ->assertSee('Kelas X-4')
        ->assertSee('Siswa ini dipindahkan ke Sampah: langsung tidak bisa masuk dan tidak tampil di mana pun.')
        ->assertSee('Selama 7 hari masih bisa dipulihkan. Mulai 13 November 2026 akun dan seluruh data siswanya')
        ->call('trash')
        ->assertHasErrors(['reason'])
        ->set('reason', 'Data ganda dari import.')
        ->call('trash')
        ->assertSet('asked', [])
        ->assertDispatched('sth-modal-close', id: HapusSiswa::DIALOG)
        ->assertDispatched(HapusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: '1 siswa dipindahkan ke Sampah.', tone: 'success');

    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse();

    $screen->dispatch(HapusSiswa::DONE_EVENT)
        ->assertSee('Raka Pratama Wijaya')   // now in the Sampah section
        ->set('selectedStudents', [(string) $sinta->user_id, (string) $bima->user_id])
        ->assertSee('Hapus terpilih')
        ->call('askTrashSelected')
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $sinta->user_id, (int) $bima->user_id]);

    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $sinta->user_id, (int) $bima->user_id])
        ->assertSee('Hapus 2 siswa?')
        ->assertSee('Bima Satria Nugraha, Sinta Ayu Lestari')
        ->set('reason', 'Siswa pindah sekolah lain.')
        ->call('trash')
        ->assertDispatched('sth-toast', message: '2 siswa dipindahkan ke Sampah.', tone: 'success');

    $screen->dispatch(HapusSiswa::DONE_EVENT)->assertSet('selectedStudents', []);

    expect(Student::query()->where('classroom_id', $this->x4->getKey())->count())->toBe(0)
        ->and(TrashEntry::query()->count())->toBe(3);
});

it('refuses a student id the list never showed and answers a student trashed elsewhere with a toast', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $other = sampahSiswa($this->x5, 'Bima Satria Nugraha');

    sampahScreen()
        ->call('showStudents', $this->x4->getKey())
        ->call('askTrashStudent', $other->user_id)
        ->assertStatus(404);

    $dialog = Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $raka->user_id])
        ->set('reason', 'Data ganda dari import.');

    app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Dihapus dari tab lain.');

    $dialog->call('trash')
        ->assertOk()
        ->assertSet('asked', [])
        ->assertDispatched(HapusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: HapusSiswa::MSG_CHANGED, tone: 'warning');

    expect(TrashEntry::query()->count())->toBe(1);
});

// --- Admin › Kelas: Sampah ----------------------------------------------------------------------------------------

it('shows an empty Sampah section', function (): void {
    sampahScreen()->assertSee('Sampah kosong');
});

it('lists a class with its count and a student trashed alone, not the class\'s students one by one, and restores with Pulihkan', function (): void {
    $alone = sampahSiswa($this->x5, 'Dewi Kartika Sari');
    sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $entry = app(TrashStudents::class)->handle($this->admin, [$alone->user_id], 'Siswa pindah sekolah lain.')[0];
    app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    $screen = sampahScreen()
        ->assertSee('Dewi Kartika Sari')
        ->assertSee('Kelas X-5')
        ->assertSee('Dihapus 5 Nov 2026, 10.00 oleh Admin Sekolah Contoh (Admin)')
        ->assertSee('Kelas X-4')
        ->assertSee('1 siswa ikut dihapus')
        ->assertDontSee('Raka Pratama Wijaya')
        ->assertSee('Terhapus permanen 13 November 2026');

    expect($screen->viewData('trash')['total'])->toBe(2);

    $screen->call('askRestore', $entry->getKey())
        ->assertSet('restoringId', (string) $entry->getKey())
        ->assertDispatched('sth-modal-open', id: Kelas::RESTORE_DIALOG)
        ->assertSee('Pulihkan Dewi Kartika Sari?')
        ->call('restore')
        ->assertSet('restoringId', null)
        ->assertDispatched('sth-toast', message: 'Dewi Kartika Sari dipulihkan.', tone: 'success');

    expect(Student::query()->whereKey($alone->getKey())->exists())->toBeTrue();
});

it('answers Pulihkan of a student whose class is in the trash, or of an entry restored elsewhere, with a toast', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Siswa salah kelas.')[0];
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');

    sampahScreen()
        ->call('askRestore', $entry->getKey())
        ->assertSet('restoringId', null)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Pulihkan kelas X-4 dulu.', tone: 'warning');

    $screen = sampahScreen()->call('askRestore', $class->getKey())->assertSee('Pulihkan kelas X-4?');
    app(RestoreFromTrash::class)->handle($this->admin, $class);

    $screen->call('restore')
        ->assertOk()
        ->assertDispatched('sth-toast', message: Kelas::MSG_ENTRY_CLOSED, tone: 'warning');
});

// --- Permanent deletion -------------------------------------------------------------------------------------------

it('schedules sth:sampah:hapus-permanen daily at 02:00 Asia/Jakarta without overlap', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e): bool => str_contains((string) $e->command, 'sth:sampah:hapus-permanen'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('names the first daily run after 7 full days as the purge moment', function (string $deleted, string $purge): void {
    expect(TrashEntry::purgeAfterFor(CarbonImmutable::parse($deleted, 'Asia/Jakarta'))->format('Y-m-d H:i'))->toBe($purge);
})->with([
    'in the morning' => ['2026-11-05 10:00:00', '2026-11-13 02:00'],
    'right after midnight' => ['2026-11-05 00:30:00', '2026-11-12 02:00'],
    'at the run' => ['2026-11-05 02:00:00', '2026-11-12 02:00'],
]);

it('purges nothing before purge_after', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.');
    $fake = sampahFakePurge();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 01:59:00', 'Asia/Jakarta'));

    $this->artisan('sth:sampah:hapus-permanen')
        ->expectsOutput('Tidak ada data di Sampah yang waktunya dihapus permanen.')
        ->assertSuccessful();

    expect($fake->calls)->toBe([])
        ->and(Student::withTrashed()->whereKey($raka->getKey())->exists())->toBeTrue();
});

it('after 7 days hands the trashed students to PurgeStudents, deletes the class and closes the entries with one audit row', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $sinta = sampahSiswa($this->x4, 'Sinta Ayu Lestari');
    $restored = sampahSiswa($this->x5, 'Bima Satria Nugraha');
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');
    $back = app(TrashStudents::class)->handle($this->admin, [$restored->user_id], 'Salah pilih siswa.')[0];
    app(RestoreFromTrash::class)->handle($this->admin, $back);

    $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00:00', 'Asia/Jakarta'));
    $later = sampahSiswa($this->x5, 'Dewi Kartika Sari');
    $laterEntry = app(TrashStudents::class)->handle($this->admin, [$later->user_id], 'Siswa pindah sekolah lain.')[0];

    $fake = sampahFakePurge();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:30', 'Asia/Jakarta'));
    $logged = Activity::query()->count();

    $this->artisan('sth:sampah:hapus-permanen')
        ->expectsOutput('Dihapus permanen: 1 kelas, 2 siswa.')
        ->assertSuccessful();

    expect($fake->calls)->toBe([collect([$raka, $sinta])->map(fn (Student $s): string => (string) $s->getKey())->sort()->values()->all()])
        ->and(Classroom::withTrashed()->find($this->x4->getKey()))->toBeNull()
        ->and($class->fresh()->purged_at)->not->toBeNull()
        ->and($class->children()->whereNull('purged_at')->count())->toBe(0)
        ->and($laterEntry->fresh()->purged_at)->toBeNull()
        ->and(Student::withTrashed()->find($later->getKey())->trashed())->toBeTrue()
        ->and(Student::query()->whereKey($restored->getKey())->exists())->toBeTrue()
        ->and($back->fresh()->purged_at)->toBeNull();

    $rows = Activity::query()->orderBy('id')->get()->slice($logged)->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->event)->toBe(PurgeTrash::EVENT)
        ->and($rows[0]->causer_id)->toBeNull()
        ->and($rows[0]->properties->only(['entries', 'kelas', 'siswa'])->all())->toBe(['entries' => 3, 'kelas' => 1, 'siswa' => 2])
        ->and($rows[0]->properties['data'])->toBe(['students' => 2, 'users' => 2]);

    $text = (string) json_encode($rows[0]->toArray());

    expect(str_contains($text, 'Raka'))->toBeFalse()
        ->and(str_contains($text, (string) $raka->nisn))->toBeFalse();

    // A second run finds nothing.
    $this->artisan('sth:sampah:hapus-permanen')->expectsOutput('Tidak ada data di Sampah yang waktunya dihapus permanen.')->assertSuccessful();
    expect($fake->calls)->toHaveCount(1);
});

it('keeps the class and the entries open when the students are still there, and closes them on the next run', function (): void {
    sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');
    sampahFakePurge(deletes: false);
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:30', 'Asia/Jakarta'));

    $this->artisan('sth:sampah:hapus-permanen')
        ->expectsOutput('Belum bisa dihapus: 1 kelas, 1 siswa. Dicoba lagi pada jadwal berikutnya.')
        ->assertFailed();

    expect(Classroom::withTrashed()->find($this->x4->getKey())?->trashed())->toBeTrue()
        ->and($class->fresh()->purged_at)->toBeNull()
        ->and(Activity::query()->where('event', PurgeTrash::EVENT)->exists())->toBeFalse();

    sampahFakePurge();

    $this->artisan('sth:sampah:hapus-permanen')->expectsOutput('Dihapus permanen: 1 kelas, 1 siswa.')->assertSuccessful();

    expect(Classroom::withTrashed()->find($this->x4->getKey()))->toBeNull()
        ->and($class->fresh()->purged_at)->not->toBeNull();
});

it('leaves a group PurgeStudents refuses in the trash, prints its sentence and goes on with the others', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    $refused = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    sampahSiswa($this->x5, 'Bima Satria Nugraha');
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x5, 'Kelas digabung ke kelas X-4.');
    $fake = sampahFakePurge(refuse: (string) $raka->getKey());
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:30', 'Asia/Jakarta'));

    $this->artisan('sth:sampah:hapus-permanen')
        ->expectsOutput('Ditolak: 1 pendaftaran kehilangan ketua tanpa anggota lain yang bisa menggantikannya. Tidak ada yang dihapus.')
        ->expectsOutput('Dihapus permanen: 1 kelas, 1 siswa.')
        ->expectsOutput('Belum bisa dihapus: 0 kelas, 1 siswa. Dicoba lagi pada jadwal berikutnya.')
        ->assertFailed();

    expect($fake->calls)->toHaveCount(2)
        ->and($refused->fresh()->purged_at)->toBeNull()
        ->and(Student::withTrashed()->find($raka->getKey())->trashed())->toBeTrue()
        ->and($class->fresh()->purged_at)->not->toBeNull()
        ->and(Classroom::withTrashed()->find($this->x5->getKey()))->toBeNull()
        ->and(Activity::query()->where('event', PurgeTrash::EVENT)->sole()->properties['ditolak'])->toBe(1);
});

it('deletes the data with the real PurgeStudents once the 7 days are over', function (): void {
    $raka = sampahSiswa($this->x4, 'Raka Pratama Wijaya');
    TalentApplication::factory()->create(['student_id' => $raka->getKey()]);
    $sinta = sampahSiswa($this->x5, 'Sinta Ayu Lestari');
    $class = app(TrashClassroom::class)->handle($this->admin, $this->x4, 'Kelas digabung ke kelas X-5.');
    $alone = app(TrashStudents::class)->handle($this->admin, [$sinta->user_id], 'Siswa pindah sekolah lain.')[0];
    $this->travelTo(CarbonImmutable::parse('2026-11-13 02:00:30', 'Asia/Jakarta'));

    $this->artisan('sth:sampah:hapus-permanen')->expectsOutput('Dihapus permanen: 1 kelas, 2 siswa.')->assertSuccessful();

    expect(Student::withTrashed()->whereKey([$raka->getKey(), $sinta->getKey()])->count())->toBe(0)
        ->and(User::withTrashed()->whereKey([$raka->user_id, $sinta->user_id])->count())->toBe(0)
        ->and(TalentApplication::withTrashed()->count())->toBe(0)
        ->and(Classroom::withTrashed()->find($this->x4->getKey()))->toBeNull()
        ->and(Classroom::query()->whereKey($this->x5->getKey())->exists())->toBeTrue()
        ->and($class->fresh()->purged_at)->not->toBeNull()
        ->and($alone->fresh()->purged_at)->not->toBeNull();
});
