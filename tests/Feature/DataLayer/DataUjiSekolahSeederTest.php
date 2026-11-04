<?php

declare(strict_types=1);

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DataUjiSekolahSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
| smaga_server only (SV3, 2026-11-04): the six real staff accounts get staff_password and the trial guru keep their own
| guru_password. The values come from the local data file, or from the fictional example of a fresh clone: the test
| reads them through the seeder, so it holds for both.
*/

uses(RefreshDatabase::class);

it('gives the six staff accounts the staff password and the trial guru the guru password', function (): void {
    $this->seed(DataUjiSekolahSeeder::class);

    $realStaff = DataUjiSekolahSeeder::realStaff();

    expect($realStaff)->toHaveCount(6)
        ->and(DataUjiSekolahSeeder::staffPassword())->not->toBe(DataUjiSekolahSeeder::guruPassword());

    foreach (array_keys(DataUjiSekolahSeeder::STAFF) as $username) {
        $user = User::query()->where('username', $username)->sole();
        $password = array_key_exists($username, $realStaff)
            ? DataUjiSekolahSeeder::staffPassword()
            : DataUjiSekolahSeeder::guruPassword();

        expect(Hash::check($password, $user->password))->toBeTrue("{$username} has the wrong password");
    }

    expect(User::query()->where('username', 'guru1')->value('email'))->toBe(DataUjiSekolahSeeder::trialAddress('guru1'));
});

it('seeds the 36 classes and no student at all: the school starts from zero with Import siswa (4 Nov 2026)', function (): void {
    $this->seed(DataUjiSekolahSeeder::class);

    expect(Student::query()->withTrashed()->count())->toBe(0)
        ->and(User::role('siswa')->count())->toBe(0)
        ->and(User::query()->count())->toBe(count(DataUjiSekolahSeeder::STAFF))
        ->and(Classroom::query()->count())->toBe(36);
});
