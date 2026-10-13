<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Models\User;
use App\Support\StaffRoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

it('names the staff role of an account (FS1, D-139)', function (RoleName $role, string $label): void {
    expect(StaffRoleLabel::of(H::staff($role)))->toBe($label)
        ->and(StaffRoleLabel::forDecision(H::staff($role, ['email' => 'dua.'.$role->value.'@example.test', 'username' => 'dua.'.$role->value])))->toBe($label);
})->with([
    'kepsek' => [RoleName::KepalaSekolah, 'Kepala Sekolah'],
    'tu' => [RoleName::TataUsaha, 'Tata Usaha'],
    'kesiswaan' => [RoleName::Kesiswaan, 'Kesiswaan'],
    'humas' => [RoleName::Humas, 'Humas'],
    'admin' => [RoleName::Admin, 'Admin'],
    'guru' => [RoleName::Guru, 'Guru'],
]);

it('picks one role by the fixed priority when an account has several', function (array $roles, string $label): void {
    $user = H::staff($roles[0]);
    $user->assignRole(array_map(fn (RoleName $r): string => $r->value, array_slice($roles, 1)));

    expect(StaffRoleLabel::of($user->fresh()))->toBe($label);
})->with([
    'kepsek + guru' => [[RoleName::Guru, RoleName::KepalaSekolah], 'Kepala Sekolah'],
    'kesiswaan + tu' => [[RoleName::Kesiswaan, RoleName::TataUsaha], 'Tata Usaha'],
    'humas + kesiswaan' => [[RoleName::Humas, RoleName::Kesiswaan], 'Kesiswaan'],
    'admin + kesiswaan' => [[RoleName::Admin, RoleName::Kesiswaan], 'Kesiswaan'],
    'guru + humas' => [[RoleName::Guru, RoleName::Humas], 'Humas'],
    'guru + admin' => [[RoleName::Guru, RoleName::Admin], 'Admin'],
]);

it('falls back to "sekolah" when nobody or no staff role decided', function (): void {
    $student = User::factory()->create();
    $student->assignRole(RoleName::Siswa->value);
    $roleless = User::factory()->create();

    expect(StaffRoleLabel::forDecision(null))->toBe('sekolah')
        ->and(StaffRoleLabel::of($student->fresh()))->toBe('')
        ->and(StaffRoleLabel::forDecision($student->fresh()))->toBe('sekolah')
        ->and(StaffRoleLabel::forDecision($roleless->fresh()))->toBe('sekolah');
});
