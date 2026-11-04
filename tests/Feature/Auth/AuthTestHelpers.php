<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for tests/Feature/Auth and tests/Feature/Accounts (fictional data only, DESIGN-SPEC-v2 §5).
 */
final class AuthTestHelpers
{
    public const NIS = '225226201';

    public const NISN = '0098765432';

    public const PASSWORD = 'password';

    /** Seeds every role with RoleName::defaultPermissions() (same matrix as the RoleSeeder). */
    public static function seedRoles(): void
    {
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web')
                ->syncPermissions(array_map(fn (Permission $p): string => $p->value, $role->defaultPermissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Imported student before first login (password "password", no email). */
    public static function pendingStudent(array $studentAttributes = []): Student
    {
        return Student::factory()->create(array_merge([
            'nis' => self::NIS,
            'nisn' => self::NISN,
            'birth_date' => '2010-03-02',
            'classroom_id' => Classroom::factory()->level(11)->create(['name' => 'XI-4'])->getKey(),
            'import_school_year' => '2026/2027',
        ], $studentAttributes));
    }

    /** Student who finished activation (active, verified email). */
    public static function activeStudent(array $userAttributes = []): Student
    {
        $student = self::pendingStudent();
        $student->user->forceFill(array_merge([
            'email' => 'nadia.anindya@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ], $userAttributes))->save();

        return $student->refresh();
    }

    public static function staff(RoleName $role = RoleName::Guru, array $attributes = []): User
    {
        // fresh(): a DB-loaded model has every column, like a real request (Model::shouldBeStrict()).
        return User::factory()->staff($role)->create(array_merge(['email' => 'staf.'.$role->value.'@example.test'], $attributes))->fresh();
    }

    /** Fake rows in the database session table for a user. */
    public static function fakeSessions(User $user, int $count = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sessions')->insert([
                'id' => 'sess-'.$user->getKey().'-'.$i,
                'user_id' => $user->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Pest',
                'payload' => base64_encode('{}'),
                'last_activity' => now()->getTimestamp(),
            ]);
        }
    }

    public static function sessionCount(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->getKey())->count();
    }

    /**
     * M2-A: who a browser is signed in as when all it holds is this session cookie (null = nobody). Needs
     * `session.driver = database`. Everything the test process still remembers (the guard's user, the session data
     * in memory) is dropped first, so only the row in the `sessions` table decides, as for a real request.
     * Afterwards the test process "is" that browser: the session store holds this id and what the row gave it.
     */
    public static function userBehindSession(string $sessionId): ?int
    {
        Route::middleware('web')->get('/_test/whoami', fn () => response()->json(['id' => Auth::id()]));

        Auth::forgetGuards();
        session()->flush();

        $name = (string) config('session.cookie');
        $cookie = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$sessionId, false);

        $id = test()->call('GET', '/_test/whoami', [], [$name => $cookie])->assertOk()->json('id');

        return $id === null ? null : (int) $id;
    }
}
