<?php

declare(strict_types=1);

use App\Actions\Accounts\ApplyBirthDateInitialPasswords;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DataUjiSekolahSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 * D-173 (smaga_server, the lead's request of 2 Nov 2026): `php artisan sth:siswa:kata-sandi-awal` moves the students who
 * have not activated and are still on the configured initial password of D-138 to "SMAGA_" + their birth date as
 * ddmmyyyy; DataUjiSekolahSeeder gives its pending trial students that password. Fictional people only; expected
 * passwords are built here and only compared through Hash::check(), never printed.
 */

uses(RefreshDatabase::class);

const D173_COMMAND = 'sth:siswa:kata-sandi-awal';

beforeEach(function (): void {
    H::seedRoles();
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(9, 0));
    config(['sth.initial_student_password' => 'Awal-Uji-7319']);

    $this->classes = [
        'X-1' => Classroom::factory()->create(['name' => 'X-1', 'level' => 10])->getKey(),
        'XI-4' => Classroom::factory()->level(11)->create(['name' => 'XI-4'])->getKey(),
    ];
});

/** A student of the given class and birth date whose stored password is $password (default: the configured value). */
function d173Student(string $class, string $birthDate, array $user = [], ?string $password = null): Student
{
    $student = Student::factory()->create(['birth_date' => $birthDate, 'classroom_id' => test()->classes[$class]]);
    $student->user->forceFill([
        'password' => Hash::make($password ?? app(InitialPassword::class)->make()),
        'initial_password_expires_at' => now()->addDays(14),
        ...$user,
    ])->save();

    return $student->refresh();
}

/**
 * Three students to move (two in X-1, one in XI-4) and everybody the rule must leave alone.
 *
 * @return array{move: list<Student>, keep: array<string, User>}
 */
function d173Population(): array
{
    $move = [
        d173Student('X-1', '2010-02-01'),
        d173Student('X-1', '2010-02-01'), // the same birth date: the same password (accepted by the lead)
        d173Student('XI-4', '2009-07-19'),
    ];

    $keep = [
        // Already on the birth-date password (imported after D-173): counted as "kata sandi lain".
        'birth-date password' => d173Student('X-1', '2010-05-05', [], InitialPassword::forBirthDate('2010-05-05'))->user,
        // Activated: never a candidate, even with the configured value as its hash.
        'active' => d173Student('X-1', '2010-06-06', ['status' => UserStatus::Active, 'must_change_password' => false, 'email' => 'aktif.d173@example.test', 'email_verified_at' => now()])->user,
        // A temporary password of an Admin reset that happens to equal the configured value.
        'temporary password' => d173Student('X-1', '2010-07-07', ['temporary_password_issued_at' => now()])->user,
        // Pending but not forced to change (not the shape of an import).
        'not forced' => d173Student('XI-4', '2009-08-08', ['must_change_password' => false])->user,
        // Disabled before the activation.
        'disabled' => d173Student('XI-4', '2009-09-09', ['status' => UserStatus::Disabled])->user,
    ];

    // A guru account with the configured value: not a student.
    $guru = User::factory()->pendingActivation()->create(['password' => Hash::make(app(InitialPassword::class)->make())]);
    $guru->assignRole(RoleName::Guru->value);
    $keep['guru'] = $guru;

    // A siswa account without a student row (no birth date to build from).
    $orphan = User::factory()->pendingActivation()->create(['password' => Hash::make(app(InitialPassword::class)->make())]);
    $orphan->assignRole(RoleName::Siswa->value);
    $keep['no student row'] = $orphan;

    return ['move' => $move, 'keep' => $keep];
}

/** @return array<int, string> user id => stored hash */
function d173Hashes(): array
{
    return User::query()->pluck('password', 'id')->all();
}

/** Runs the command and returns [exit code, output]. */
function d173Run(array $options = []): array
{
    $code = Artisan::call(D173_COMMAND, $options);

    return [$code, Artisan::output()];
}

/**
 * Puts a hasher in front of the real one that calls $beforeMake(n) before its n-th make(). The Action makes the new
 * hashes of a chunk before the chunk's transaction, so $beforeMake can change a row between the check and the write
 * (or stop the run there). Everything else goes to the real hasher.
 */
function d173HasherBeforeMake(Closure $beforeMake): void
{
    Hash::swap(new class(app('hash'), $beforeMake)
    {
        private int $calls = 0;

        public function __construct(private readonly HashManager $inner, private readonly Closure $beforeMake) {}

        public function make(string $value, array $options = []): string
        {
            ($this->beforeMake)(++$this->calls);

            return $this->inner->make($value, $options);
        }

        public function __call(string $method, array $arguments): mixed
        {
            return $this->inner->{$method}(...$arguments);
        }
    });
}

/** $count students of X-1 born 2010-02-01 on the configured value (one shared hash: bcrypt per row is not needed here). */
function d173ManyStudents(int $count): array
{
    $students = Student::factory()->count($count)->create(['birth_date' => '2010-02-01', 'classroom_id' => test()->classes['X-1']]);
    User::query()->whereIn('id', $students->pluck('user_id'))->update(['password' => Hash::make(app(InitialPassword::class)->make())]);

    return $students->sortBy('user_id')->values()->all();
}

/** Every value the command output and the audit row must never carry: names, NISN, NIS, any password. */
function d173Secrets(array $students): array
{
    // The rule itself ("SMAGA_ + tanggal lahir") may be named; a student's own value or birth date may not.
    $secrets = [app(InitialPassword::class)->make()];

    foreach ($students as $student) {
        $secrets[] = $student->user->name;
        $secrets[] = $student->nisn;
        $secrets[] = $student->nis;
        $secrets[] = $student->birth_date->format('dmY');
        $secrets[] = InitialPassword::forBirthDate($student->birth_date->format('Y-m-d'));
    }

    return $secrets;
}

it('previews the students still on the configured value per class and writes nothing (dry run)', function (): void {
    ['move' => $move] = d173Population();
    $before = d173Hashes();
    $logged = Activity::query()->count();

    [$code, $output] = d173Run();

    expect($code)->toBe(0)
        // The pending siswa accounts it reads: the three to move, the birth-date one and the one without a student row.
        ->and($output)->toContain('Memeriksa 5 akun siswa belum aktivasi.')
        // One chunk only: no progress lines.
        ->and($output)->not->toContain('akun diperiksa')
        ->and($output)->toContain('Pratinjau, tidak ada yang diubah: 3 siswa belum aktivasi masih memakai kata sandi awal lama.')
        ->and($output)->toContain('X-1: 2 siswa')
        ->and($output)->toContain('XI-4: 1 siswa')
        ->and($output)->toContain('Tidak diubah (siswa belum aktivasi): 1 tanpa tanggal lahir, 1 dengan kata sandi lain.')
        ->and($output)->toContain('Jalankan lagi dengan --terapkan')
        ->and(d173Hashes())->toBe($before)
        ->and(Activity::query()->count())->toBe($logged);

    foreach (d173Secrets($move) as $secret) {
        expect(str_contains($output, $secret))->toBeFalse();
    }
});

it('moves only pending students on the configured value to SMAGA_ + their birth date, and nothing else (--terapkan)', function (): void {
    ['move' => $move, 'keep' => $keep] = d173Population();
    $before = d173Hashes();
    $accounts = collect($move)->mapWithKeys(fn (Student $s): array => [$s->user_id => $s->user->only(['status', 'email', 'must_change_password', 'initial_password_expires_at', 'temporary_password_issued_at'])])->all();
    $userRows = Activity::query()->where('log_name', 'user')->count();

    [$code, $output] = d173Run(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Kata sandi awal 3 siswa diganti ke pola SMAGA_ + tanggal lahir (ddmmyyyy).')
        ->and($output)->toContain('X-1: 2 siswa')
        ->and($output)->toContain('XI-4: 1 siswa');

    foreach ($move as $student) {
        $user = User::query()->findOrFail($student->user_id);

        expect(Hash::check(InitialPassword::forBirthDate($student->birth_date->format('Y-m-d')), $user->password))->toBeTrue()
            ->and(Hash::check(app(InitialPassword::class)->make(), $user->password))->toBeFalse()
            // Only the password: the activation window, the email and the status stay.
            ->and($user->only(['status', 'email', 'must_change_password', 'initial_password_expires_at', 'temporary_password_issued_at']))->toEqual($accounts[$user->getKey()]);
    }

    // The two students born on one day share the password.
    expect(Hash::check('SMAGA_01022010', User::query()->findOrFail($move[0]->user_id)->password))->toBeTrue()
        ->and(Hash::check('SMAGA_01022010', User::query()->findOrFail($move[1]->user_id)->password))->toBeTrue();

    foreach ($keep as $label => $user) {
        expect(User::query()->findOrFail($user->getKey())->password)->toBe($before[$user->getKey()], $label);
    }

    // One audit row per chunk (here one chunk) with counts and the run id only (log `account`, no causer: the operator
    // runs it on the server); no row per student.
    $audit = Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->sole();
    expect($audit->log_name)->toBe('account')
        ->and($audit->causer_id)->toBeNull()
        ->and(array_keys($audit->properties->all()))->toBe(['via', 'run', 'moved', 'changed_meanwhile'])
        ->and($audit->properties->only(['via', 'moved', 'changed_meanwhile'])->all())->toBe(['via' => 'cli', 'moved' => 3, 'changed_meanwhile' => 0])
        ->and(Str::isUlid($audit->properties['run']))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'user')->count())->toBe($userRows);

    foreach (d173Secrets($move) as $secret) {
        expect(str_contains($output, $secret))->toBeFalse()
            ->and(str_contains((string) json_encode($audit->toArray()), $secret))->toBeFalse();
    }

    // The moved student signs in with the NISN and the birth-date password and lands on the activation.
    $this->from('/login')->post('/login', ['identifier' => $move[2]->nisn, 'password' => 'SMAGA_19072009'])
        ->assertRedirect(route('aktivasi'));
});

it('finds nobody on a second run and writes no second audit row (idempotent)', function (): void {
    d173Population();
    d173Run(['--terapkan' => true, '--force' => true]);
    $after = d173Hashes();

    [$code, $output] = d173Run(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Tidak ada siswa yang perlu diganti. Tidak ada yang diubah.')
        ->and($output)->toContain('1 tanpa tanggal lahir, 4 dengan kata sandi lain.')
        ->and(d173Hashes())->toBe($after)
        ->and(Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->count())->toBe(1);

    [, $preview] = d173Run();
    expect($preview)->toContain('Tidak ada siswa belum aktivasi yang masih memakai kata sandi awal lama.');
});

it('refuses --terapkan outside APP_ENV=local without --force and writes nothing', function (): void {
    d173Population();
    $before = d173Hashes();

    [$code, $output] = d173Run(['--terapkan' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Di luar APP_ENV=local, --terapkan hanya berjalan bersama --force. Tidak ada yang diubah.')
        ->and(d173Hashes())->toBe($before)
        ->and(Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->exists())->toBeFalse();
});

it('applies with --terapkan alone in APP_ENV=local (the school server today)', function (): void {
    ['move' => $move] = d173Population();
    $this->app['env'] = 'local';

    [$code, $output] = d173Run(['--terapkan' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Kata sandi awal 3 siswa diganti')
        ->and(Hash::check('SMAGA_19072009', User::query()->findOrFail($move[2]->user_id)->password))->toBeTrue();
});

it('says so when nobody waits, without an audit row', function (): void {
    [$code, $output] = d173Run(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Tidak ada siswa yang perlu diganti. Tidak ada yang diubah.')
        ->and($output)->not->toContain('Tidak diubah')
        ->and(Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->exists())->toBeFalse();
});

it('writes nothing for a row activated or reset between the check and the write (changed_meanwhile)', function (): void {
    ['move' => $move] = d173Population();
    $before = d173Hashes();

    // The hashes of the chunk are made before its transaction: meanwhile one student activates and another one gets a
    // temporary password from an Admin. Both rows keep their hash; the third is moved.
    d173HasherBeforeMake(function (int $call) use ($move): void {
        match ($call) {
            1 => DB::table('users')->where('id', $move[0]->user_id)->update(['status' => UserStatus::Active->value, 'must_change_password' => false]),
            2 => DB::table('users')->where('id', $move[1]->user_id)->update(['temporary_password_issued_at' => now()]),
            default => null,
        };
    });

    [$code, $output] = d173Run(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Kata sandi awal 1 siswa diganti ke pola SMAGA_ + tanggal lahir (ddmmyyyy).')
        ->and($output)->toContain('XI-4: 1 siswa')
        ->and($output)->not->toContain('X-1:')
        ->and($output)->toContain('Tidak diubah (siswa belum aktivasi): 1 tanpa tanggal lahir, 1 dengan kata sandi lain, 2 berubah saat perintah berjalan.')
        ->and(User::query()->findOrFail($move[0]->user_id)->password)->toBe($before[$move[0]->user_id])
        ->and(User::query()->findOrFail($move[1]->user_id)->password)->toBe($before[$move[1]->user_id])
        ->and(Hash::check('SMAGA_19072009', User::query()->findOrFail($move[2]->user_id)->password))->toBeTrue();

    $audit = Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->sole();
    expect($audit->properties->only(['moved', 'changed_meanwhile'])->all())->toBe(['moved' => 1, 'changed_meanwhile' => 2]);

    // The two changed rows are no candidates any more: a second run finds nobody.
    expect(app(ApplyBirthDateInitialPasswords::class)->handle())->toMatchArray(['move' => 0, 'changed_meanwhile' => 0]);
});

it('keeps and audits what a run wrote before it stopped part-way, and the next run moves the rest', function (): void {
    $students = d173ManyStudents(ApplyBirthDateInitialPasswords::CHUNK + 1);
    $last = end($students);

    // The run stops while it hashes the second chunk (Ctrl+C, a restart, an exception).
    d173HasherBeforeMake(function (int $call): void {
        if ($call === ApplyBirthDateInitialPasswords::CHUNK + 1) {
            throw new RuntimeException('Dihentikan.');
        }
    });

    expect(fn () => d173Run(['--terapkan' => true, '--force' => true]))->toThrow(RuntimeException::class, 'Dihentikan.');

    // The first chunk is written and audited together; the last student is untouched.
    $moved = collect($students)->slice(0, ApplyBirthDateInitialPasswords::CHUNK)
        ->filter(fn (Student $s): bool => Hash::check('SMAGA_01022010', User::query()->findOrFail($s->user_id)->password));
    $audit = Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->sole();

    expect($moved)->toHaveCount(ApplyBirthDateInitialPasswords::CHUNK)
        ->and(Hash::check(app(InitialPassword::class)->make(), User::query()->findOrFail($last->user_id)->password))->toBeTrue()
        ->and($audit->properties->only(['moved', 'changed_meanwhile'])->all())->toBe(['moved' => ApplyBirthDateInitialPasswords::CHUNK, 'changed_meanwhile' => 0]);

    // The next run reads every pending account again, prints its progress per chunk and moves the one left.
    [$code, $output] = d173Run(['--terapkan' => true, '--force' => true]);
    $total = ApplyBirthDateInitialPasswords::CHUNK + 1;
    $rows = Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->oldest('id')->get();

    expect($code)->toBe(0)
        ->and($output)->toContain("Memeriksa {$total} akun siswa belum aktivasi.")
        ->and($output)->toContain('  '.ApplyBirthDateInitialPasswords::CHUNK."/{$total} akun diperiksa")
        ->and($output)->toContain("  {$total}/{$total} akun diperiksa")
        ->and($output)->toContain('Kata sandi awal 1 siswa diganti')
        ->and($output)->toContain(ApplyBirthDateInitialPasswords::CHUNK.' dengan kata sandi lain')
        ->and(Hash::check('SMAGA_01022010', User::query()->findOrFail($last->user_id)->password))->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows[1]->properties['moved'])->toBe(1)
        ->and($rows[1]->properties['run'])->not->toBe($rows[0]->properties['run']);

    foreach (d173Secrets([$students[0], $last]) as $secret) {
        expect(str_contains($output, $secret))->toBeFalse();
    }
});

it('prints the progress of a long preview per chunk and writes nothing', function (): void {
    d173ManyStudents(ApplyBirthDateInitialPasswords::CHUNK + 1);
    $before = d173Hashes();
    $total = ApplyBirthDateInitialPasswords::CHUNK + 1;

    [$code, $output] = d173Run();

    expect($code)->toBe(0)
        ->and($output)->toContain("Memeriksa {$total} akun siswa belum aktivasi.")
        ->and($output)->toContain('  '.ApplyBirthDateInitialPasswords::CHUNK."/{$total} akun diperiksa")
        ->and($output)->toContain("  {$total}/{$total} akun diperiksa")
        ->and($output)->toContain("Pratinjau, tidak ada yang diubah: {$total} siswa belum aktivasi masih memakai kata sandi awal lama.")
        ->and(d173Hashes())->toBe($before)
        ->and(Activity::query()->where('event', ApplyBirthDateInitialPasswords::EVENT)->exists())->toBeFalse();
});

it('labels the audit event on Log audit', function (): void {
    expect(AuditLog::EVENT_LABELS)->toHaveKey(ApplyBirthDateInitialPasswords::EVENT);
});

it('leaves nobody for the command after DataUjiSekolahSeeder, which seeds no students since 4 Nov 2026', function (): void {
    // The seeder refuses a database with accounts; this test starts from an empty one with only the roles.
    $this->seed(DataUjiSekolahSeeder::class);

    expect(Student::query()->withTrashed()->count())->toBe(0);

    [, $output] = d173Run();
    expect($output)->toContain('Tidak ada siswa belum aktivasi yang masih memakai kata sandi awal lama.');
});
