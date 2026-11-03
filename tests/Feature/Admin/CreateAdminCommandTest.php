<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

const STH_STRONG = 'Operator-SMAGA#2026';

it('creates the first Admin interactively with a strong password and audits it', function (): void {
    A::seedRoles();

    $this->artisan('sth:create-admin')
        ->expectsQuestion('Nama lengkap', 'Operator Sekolah')
        ->expectsQuestion('Nama pengguna', 'operator')
        ->expectsQuestion('Email', 'operator@example.test')
        ->expectsQuestion('Kata sandi (min. 12, huruf besar + kecil, angka, simbol)', STH_STRONG)
        ->expectsQuestion('Ulangi kata sandi', STH_STRONG)
        ->expectsOutputToContain('Akun Admin "operator" dibuat')
        ->assertSuccessful();

    $user = User::query()->where('username', 'operator')->sole();
    expect($user->hasRole(RoleName::Admin->value))->toBeTrue()
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->two_factor_confirmed_at)->toBeNull()        // 2FA is set up at the first login
        ->and(Hash::check(STH_STRONG, $user->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.admin_created')->sole()->properties['via'])->toBe('cli');
});

it('refuses weak or mismatching passwords', function (string $password, string $confirmation): void {
    A::seedRoles();

    $this->artisan('sth:create-admin', ['--name' => 'Operator Sekolah', '--username' => 'operator', '--email' => 'operator@example.test'])
        ->expectsQuestion('Kata sandi (min. 12, huruf besar + kecil, angka, simbol)', $password)
        ->expectsQuestion('Ulangi kata sandi', $confirmation)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'too short' => ['Ab1!xyz', 'Ab1!xyz'],
    'no symbol' => ['OperatorSmaga2026', 'OperatorSmaga2026'],
    'no upper case' => ['operator-smaga#2026', 'operator-smaga#2026'],
    'mismatch' => [STH_STRONG, STH_STRONG.'x'],
]);

it('needs --additional once an Admin exists and stops at three Admins', function (): void {
    A::seedRoles();
    A::admin();

    $this->artisan('sth:create-admin', ['--name' => 'Admin Dua', '--username' => 'admin.dua', '--email' => 'dua@example.test'])
        ->expectsOutputToContain('Pakai --additional')
        ->assertFailed();

    A::admin(['username' => 'operator2']);
    A::admin(['username' => 'operator3']);

    $this->artisan('sth:create-admin', ['--name' => 'Admin Empat', '--username' => 'admin.empat', '--email' => 'empat@example.test', '--additional' => true])
        ->expectsOutputToContain('Sudah ada 3 akun Admin')
        ->assertFailed();
});

it('refuses to run before the roles are seeded and without an interactive password', function (): void {
    $this->artisan('sth:create-admin')->expectsOutputToContain('Peran belum ada')->assertFailed();

    A::seedRoles();
    $this->artisan('sth:create-admin', ['--name' => 'Operator', '--username' => 'operator', '--email' => 'op@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('Kata sandi wajib')
        ->assertFailed();
});

it('refuses a username or email that already exists', function (): void {
    A::seedRoles();
    User::factory()->create(['username' => 'operator', 'email' => 'operator@example.test']);

    $this->artisan('sth:create-admin', ['--name' => 'Operator Sekolah', '--username' => 'operator', '--email' => 'operator@example.test'])
        ->expectsQuestion('Kata sandi (min. 12, huruf besar + kecil, angka, simbol)', STH_STRONG)
        ->expectsQuestion('Ulangi kata sandi', STH_STRONG)
        ->assertFailed();
});
