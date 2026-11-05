<?php

declare(strict_types=1);

use App\Actions\Students\UpdateStudentData;
use App\Domain\Accounts\InitialPassword;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit\SecurityEventSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | SV6, D-187 "Ubah data siswa": App\Actions\Students\UpdateStudentData, StudentPolicy::update and the two new grants of
 | the role matrix (student.update for Kesiswaan and Tata Usaha, student.trash for Kesiswaan). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->xi1 = Classroom::factory()->create(['name' => 'XI-1', 'level' => 11]);
    $this->xii1 = Classroom::factory()->create(['name' => 'XII-1', 'level' => 12]);
    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->tu = A::staff(RoleName::TataUsaha);
});

/**
 * A pending student of X-1 born 15-05-2010 whose password is still the D-173 birth-date password (NIS 225226201, NISN
 * 0012345670 unless given), or the given state.
 */
function udsStudent(array $student = [], array $user = []): User
{
    $birthDate = $student['birth_date'] ?? '2010-05-15';
    $row = Student::factory()->create(array_merge([
        'classroom_id' => test()->x1->getKey(),
        'nis' => '225226201',
        'nisn' => '0012345670',
        'birth_date' => $birthDate,
        'import_school_year' => '2026/2027',
    ], $student));
    $row->user->forceFill(array_merge([
        'name' => 'Nadia Putri Anindya',
        'password' => Hash::make(InitialPassword::forBirthDate($birthDate)),
    ], $user))->saveQuietly();

    return User::query()->with('student')->findOrFail($row->user_id);
}

/** The dialog's input for this account, with some fields changed. */
function udsInput(User $account, array $changes = []): array
{
    $student = Student::query()->where('user_id', $account->getKey())->firstOrFail();

    return array_merge([
        'name' => (string) $account->name,
        'nis' => (string) $student->nis,
        'nisn' => (string) $student->nisn,
        'birth_date' => $student->birth_date->format('d-m-Y'),
        'classroom_id' => (string) $student->classroom_id,
    ], $changes);
}

/** @return array<string, string> the field errors of a refused save */
function udsRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_map(fn (array $m): string => $m[0], $e->errors());
    }

    throw new RuntimeException('The save was not refused.');
}

function udsAudit(): Collection
{
    return Activity::query()->where('event', UpdateStudentData::EVENT)->get();
}

// --- Role matrix and policy -------------------------------------------------------------------------------------------

it('grants student.update to Admin, Kesiswaan and Tata Usaha only, and student.trash to Admin and Kesiswaan only', function (RoleName $role): void {
    $update = in_array($role, [RoleName::Admin, RoleName::Kesiswaan, RoleName::TataUsaha], true);
    $trash = in_array($role, [RoleName::Admin, RoleName::Kesiswaan], true);
    $seeded = Role::findByName($role->value, 'web');

    expect(in_array(Permission::StudentUpdate, $role->defaultPermissions(), true))->toBe($update)
        ->and(in_array(Permission::StudentTrash, $role->defaultPermissions(), true))->toBe($trash)
        ->and($seeded->hasPermissionTo(Permission::StudentUpdate->value))->toBe($update)
        ->and($seeded->hasPermissionTo(Permission::StudentTrash->value))->toBe($trash);
})->with(RoleName::cases());

it('lets Admin, Kesiswaan and Tata Usaha update a student, nobody else, and nobody one in the Sampah', function (): void {
    $account = udsStudent();
    $student = $account->student;

    foreach ([$this->admin, $this->kesiswaan, $this->tu] as $staff) {
        expect(Gate::forUser($staff)->allows('update', $student))->toBeTrue();
    }

    foreach ([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru] as $role) {
        expect(Gate::forUser(A::staff($role))->allows('update', $student))->toBeFalse($role->value);
    }

    // A student never edits these fields, not even the own record.
    expect(Gate::forUser($account)->allows('update', $student))->toBeFalse();

    // A disabled actor (StudentPolicy::before) and a student in the Sampah.
    $disabled = A::staff(RoleName::TataUsaha, ['status' => UserStatus::Disabled]);
    expect(Gate::forUser($disabled)->allows('update', $student))->toBeFalse();

    $student->delete();
    expect(Gate::forUser($this->admin)->allows('update', $student->fresh()))->toBeFalse();
});

it('refuses the Action for roles without student.update', function (RoleName $role): void {
    $account = udsStudent();

    expect(fn () => app(UpdateStudentData::class)->handle(A::staff($role), $account, udsInput($account, ['name' => 'Nadia Putri']), 'lainnya'))
        ->toThrow(AuthorizationException::class);

    expect($account->fresh()->name)->toBe('Nadia Putri Anindya')
        ->and(udsAudit())->toHaveCount(0);
})->with([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

it('refuses a student who edits the own data through the Action', function (): void {
    $account = udsStudent([], ['status' => UserStatus::Active, 'must_change_password' => false]);

    expect(fn () => app(UpdateStudentData::class)->handle($account, $account, udsInput($account, ['name' => 'Nadia']), 'lainnya'))
        ->toThrow(AuthorizationException::class);
});

// --- Saving ----------------------------------------------------------------------------------------------------------

it('saves name, NIS, NISN, birth date and a class of the same tingkat, with one audit row of field names only', function (): void {
    $account = udsStudent();
    $before = $account->student->only(['import_school_year', 'grade12_start_date', 'archive_at']);
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);
    $userLog = Activity::query()->where('log_name', 'user')->count();

    $result = app(UpdateStudentData::class)->handle($this->tu, $account, udsInput($account, [
        'name' => '  Nadia   Putri  Anindita ',
        'nis' => '225226299',
        'nisn' => '0012345679',
        'birth_date' => '16-05-2010',
        'classroom_id' => (string) $this->x2->getKey(),
    ]), 'data_siswa');

    $student = $account->student->fresh();

    expect($result['fields'])->toBe(['name', 'nis', 'nisn', 'birth_date', 'classroom_id'])
        ->and($account->fresh()->name)->toBe('Nadia Putri Anindita')
        ->and($student->nis)->toBe('225226299')
        ->and($student->nisn)->toBe('0012345679')
        ->and($student->birth_date->format('Y-m-d'))->toBe('2010-05-16')
        ->and((string) $student->classroom_id)->toBe((string) $this->x2->getKey())
        // The same tingkat keeps the archive dates.
        ->and($student->only(['import_school_year', 'grade12_start_date', 'archive_at']))->toEqual($before)
        // Name or class changed: the public pages drop their cache.
        ->and((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);

    $audit = udsAudit();
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->log_name)->toBe('student')
        ->and((int) $audit->first()->causer_id)->toBe((int) $this->tu->getKey())
        ->and($audit->first()->properties->all())->toBe([
            'fields' => ['name', 'nis', 'nisn', 'birth_date', 'classroom_id'],
            'source' => 'data_siswa',
            'initial_password_rehashed' => true,
            'archive_dates_changed' => false,
        ]);

    // No personal value in the audit row, and the User model's own log (it stores the name) stays silent.
    $row = $audit->first()->toJson();
    foreach (['Anindita', 'Anindya', '225226299', '225226201', '0012345679', '0012345670', '2010-05-16', '16-05-2010', '2010-05-15', 'SMAGA_'] as $value) {
        expect($row)->not->toContain($value);
    }

    expect(Activity::query()->where('log_name', 'user')->count())->toBe($userLog)
        ->and(Activity::query()->get()->toJson())->not->toContain('Anindita');
    Notification::assertNothingSent();
});

it('writes nothing and no audit row when nothing changed (spaces around values do not count)', function (): void {
    $account = udsStudent();

    $result = app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, [
        'name' => ' Nadia  Putri Anindya ',
        'nis' => ' 225226201 ',
        'birth_date' => '15-5-2010',
    ]), 'kelas');

    expect($result)->toBe(['fields' => [], 'initial_password_rehashed' => false])
        ->and(udsAudit())->toHaveCount(0)
        ->and(app(UpdateStudentData::class)->changedFields($account, udsInput($account)))->toBe([]);
});

it('accepts a NIS that equals another student\'s NISN (D-137: each number is unique in its own column)', function (): void {
    $account = udsStudent();
    udsStudent(['nis' => '225226202', 'nisn' => '0012345671'], ['name' => 'Raka Pratama']);

    app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['nis' => '0012345671']), 'kelas');

    expect($account->student->fresh()->nis)->toBe('0012345671');
});

// --- The initial password (D-173, CLAUDE.md rule 3) ------------------------------------------------------------------

it('re-hashes the initial password of a pending student who still has the birth-date password', function (): void {
    $account = udsStudent([], ['initial_password_expires_at' => now()->addDays(5)]);
    $expires = $account->initial_password_expires_at;

    expect(UpdateStudentData::onBirthDatePassword($account))->toBeTrue();

    $result = app(UpdateStudentData::class)->handle($this->kesiswaan, $account, udsInput($account, ['birth_date' => '01-06-2010']), 'aktivasi');
    $fresh = $account->fresh();

    expect($result)->toBe(['fields' => ['birth_date'], 'initial_password_rehashed' => true])
        ->and(Hash::check(InitialPassword::forBirthDate('2010-06-01'), $fresh->password))->toBeTrue()
        ->and(Hash::check(InitialPassword::forBirthDate('2010-05-15'), $fresh->password))->toBeFalse()
        // The activation window, the status and the email stay.
        ->and($fresh->initial_password_expires_at?->toIso8601String())->toBe($expires?->toIso8601String())
        ->and($fresh->status)->toBe(UserStatus::PendingActivation)
        ->and($fresh->must_change_password)->toBeTrue()
        ->and(udsAudit()->first()->properties['initial_password_rehashed'])->toBeTrue();
});

it('leaves every other password alone when the birth date changes', function (array $user, string $password): void {
    $account = udsStudent([], array_merge($user, ['password' => Hash::make($password)]));
    $hash = $account->password;

    expect(UpdateStudentData::onBirthDatePassword($account))->toBeFalse();

    $result = app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['birth_date' => '01-06-2010']), 'pengguna');

    expect($result['initial_password_rehashed'])->toBeFalse()
        ->and($account->fresh()->password)->toBe($hash)
        ->and($account->student->fresh()->birth_date->format('Y-m-d'))->toBe('2010-06-01')
        ->and(udsAudit()->first()->properties['initial_password_rehashed'])->toBeFalse();
})->with([
    'an active student (own password)' => [['status' => UserStatus::Active, 'must_change_password' => false, 'email' => 'nadia.anindya@example.test'], 'Rahasia-Nadia-2026'],
    'an active student still on the birth-date password' => [['status' => UserStatus::Active, 'must_change_password' => false], 'SMAGA_15052010'],
    'a temporary password of an Admin' => [['temporary_password_issued_at' => now()], 'SMAGA_15052010'],
    'the configured value of D-138' => [[], '12345'],
    'a password of the student\'s own while pending' => [[], 'Rahasia-Nadia-2026'],
    'pending without must_change_password' => [['must_change_password' => false], 'SMAGA_15052010'],
]);

// --- Rules -----------------------------------------------------------------------------------------------------------

it('refuses invalid values with staff wording and writes nothing', function (array $changes, string $field, string $message): void {
    $changes = array_map(fn ($v) => $v instanceof Closure ? $v() : $v, $changes);
    $account = udsStudent();

    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, $changes), 'kelas'));

    expect($errors)->toHaveKey($field)
        ->and($errors[$field])->toBe($message)
        ->and(udsAudit())->toHaveCount(0)
        ->and($account->fresh()->name)->toBe('Nadia Putri Anindya')
        ->and($account->student->fresh()->nisn)->toBe('0012345670');
})->with([
    'empty name' => [['name' => '  '], 'name', 'Nama lengkap wajib diisi.'],
    'name too long' => [['name' => str_repeat('a', 151)], 'name', 'Nama lengkap maksimal 150 karakter.'],
    'name with digits' => [['name' => 'Nadia 2'], 'name', 'Nama lengkap hanya boleh berisi huruf.'],
    'short NIS' => [['nis' => '123'], 'nis', 'NIS harus 4–20 angka.'],
    'NIS with letters' => [['nis' => '22522620A'], 'nis', 'NIS harus 4–20 angka.'],
    'NISN of 9 digits' => [['nisn' => '001234567'], 'nisn', 'NISN harus 10 angka.'],
    'empty birth date' => [['birth_date' => ''], 'birth_date', 'Tanggal lahir wajib diisi.'],
    'no such day' => [['birth_date' => '31-02-2010'], 'birth_date', 'Tanggal lahir tidak valid (pakai DD-MM-YYYY).'],
    'age 12' => [['birth_date' => '06-11-2013'], 'birth_date', 'Usia harus 13–21 tahun.'],
    'age 22' => [['birth_date' => '04-11-2004'], 'birth_date', 'Usia harus 13–21 tahun.'],
    'in the future' => [['birth_date' => '01-01-2027'], 'birth_date', 'Usia harus 13–21 tahun.'],
    'no class' => [['classroom_id' => ''], 'classroom_id', 'Pilih kelas.'],
    'unknown class' => [['classroom_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'classroom_id', 'Kelas tidak ditemukan.'],
    'inactive class' => [['classroom_id' => fn () => (string) Classroom::factory()->create(['name' => 'X-9', 'level' => 10, 'active' => false])->getKey()], 'classroom_id', 'Kelas tidak ditemukan.'],
    'class in the Sampah' => [['classroom_id' => function () {
        $class = Classroom::factory()->create(['name' => 'X-8', 'level' => 10]);
        $class->delete();

        return (string) $class->getKey();
    }], 'classroom_id', 'Kelas tidak ditemukan.'],
]);

it('shows every field error at once', function (): void {
    $account = udsStudent();

    $errors = app(UpdateStudentData::class)->errors($account, udsInput($account, ['name' => '', 'nis' => '1', 'nisn' => '2', 'birth_date' => 'x', 'classroom_id' => '']));

    expect(array_keys($errors))->toBe(['name', 'nis', 'nisn', 'birth_date', 'classroom_id']);
});

it('keeps NIS and NISN unique against every student, the Sampah included', function (string $field, string $value, bool $trashed, string $message): void {
    $account = udsStudent();
    $other = udsStudent(['nis' => '225226202', 'nisn' => '0012345671'], ['name' => 'Raka Pratama']);

    if ($trashed) {
        $other->student->delete();
        $other->delete();
    }

    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, [$field => $value]), 'kelas'));

    expect($errors)->toBe([$field => $message]);
})->with([
    'NIS of another student' => ['nis', '225226202', false, 'NIS sudah dipakai siswa lain.'],
    'NISN of another student' => ['nisn', '0012345671', false, 'NISN sudah dipakai siswa lain.'],
    'NIS of a student in the Sampah' => ['nis', '225226202', true, 'NIS ini milik siswa di Sampah.'],
    'NISN of a student in the Sampah' => ['nisn', '0012345671', true, 'NISN ini milik siswa di Sampah.'],
]);

it('answers a NISN taken between the check and the write with the same field error', function (): void {
    $account = udsStudent();

    // Another tab registers the number just before this save writes it.
    Student::updating(function (Student $student): void {
        if ($student->isDirty('nisn') && ! Student::withTrashed()->where('nisn', $student->nisn)->exists()) {
            Student::factory()->create(['nisn' => $student->nisn, 'classroom_id' => test()->x1->getKey()]);
        }
    });

    try {
        $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['nisn' => '0012345699']), 'kelas'));
    } finally {
        Student::flushEventListeners();
    }

    expect($errors)->toBe(['nisn' => 'NISN sudah dipakai siswa lain.'])
        ->and($account->student->fresh()->nisn)->toBe('0012345670')
        ->and(udsAudit())->toHaveCount(0);
});

it('recomputes the archive dates like the import with the current school year when the tingkat changes', function (): void {
    // Registered as X in 2024/2025 (archive January 2028), found to be in XI now.
    $account = udsStudent(['import_school_year' => '2024/2025']);
    expect($account->student->archive_at->format('Y-m-d'))->toBe('2028-01-01');

    expect(app(UpdateStudentData::class)->newArchiveDate($account->student, (string) $this->xi1->getKey())?->format('Y-m-d'))->toBe('2029-01-01')
        ->and(app(UpdateStudentData::class)->newArchiveDate($account->student, (string) $this->x2->getKey()))->toBeNull();

    app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['classroom_id' => (string) $this->xi1->getKey()]), 'kelas');
    $student = $account->student->fresh();

    // XI in 2026/2027 → XII from 1 July 2027 → archived 18 months later.
    expect($student->import_school_year)->toBe('2026/2027')
        ->and($student->grade12_start_date->format('Y-m-d'))->toBe('2027-07-01')
        ->and($student->archive_at->format('Y-m-d'))->toBe('2029-01-01')
        ->and(udsAudit()->first()->properties->all())->toBe([
            'fields' => ['classroom_id'],
            'source' => 'kelas',
            'initial_password_rehashed' => false,
            'archive_dates_changed' => true,
        ]);
});

it('refuses a class of another tingkat whose archive date has passed', function (): void {
    Setting::put(SettingKey::ArchiveMonthsAfterGrade12, 1);
    $account = udsStudent();

    // XII in 2026/2027 → XII from 1 July 2026 → archived 1 August 2026, before today.
    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['classroom_id' => (string) $this->xii1->getKey()]), 'kelas'));

    expect($errors)->toBe(['classroom_id' => 'Tanggal arsip dari kelas ini sudah lewat.'])
        ->and((string) $account->student->fresh()->classroom_id)->toBe((string) $this->x1->getKey());
});

it('keeps the class of an archived student and still corrects the name', function (): void {
    $account = udsStudent([], ['status' => UserStatus::Archived, 'must_change_password' => false]);

    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['classroom_id' => (string) $this->x2->getKey()]), 'kelas'));
    expect($errors)->toBe(['classroom_id' => 'Kelas siswa alumni tidak bisa diubah.']);

    app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['name' => 'Nadia Putri Anindita']), 'kelas');
    expect($account->fresh()->name)->toBe('Nadia Putri Anindita');
});

// --- Stale screens ---------------------------------------------------------------------------------------------------

it('refuses a save on data that changed since the dialog showed it, and on a student in the Sampah', function (): void {
    $account = udsStudent();
    $shown = UpdateStudentData::snapshot($account);

    // A login or an email does not count; the shown fields do.
    $account->forceFill(['last_login_at' => now(), 'email' => 'nadia.anindya@example.test'])->saveQuietly();
    expect(UpdateStudentData::snapshot($account->fresh()->load('student')))->toBe($shown);

    Student::query()->whereKey($account->student->getKey())->update(['nisn' => '0012345678']);
    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, udsInput($account, ['name' => 'Nadia Putri']), 'kelas', $shown));
    expect($errors)->toBe([UpdateStudentData::KEY_STUDENT => UpdateStudentData::MSG_CHANGED])
        ->and($account->fresh()->name)->toBe('Nadia Putri Anindya');

    $input = udsInput($account, ['name' => 'Nadia Putri']);
    $account->student->delete();
    $account->delete();
    $errors = udsRefusal(fn () => app(UpdateStudentData::class)->handle($this->admin, $account, $input, 'kelas'));
    expect($errors)->toBe([UpdateStudentData::KEY_STUDENT => UpdateStudentData::MSG_GONE])
        ->and(udsAudit())->toHaveCount(0);
});

// --- Audit wiring ----------------------------------------------------------------------------------------------------

it('labels the event in Log audit and sends it to the security log', function (): void {
    expect(AuditLog::EVENT_LABELS[UpdateStudentData::EVENT])->toBe('Data siswa diubah')
        ->and(SecurityEventSubscriber::isAuditedEvent(UpdateStudentData::EVENT))->toBeTrue();
});
