<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->withoutVite();
});

dataset('admin screens', [
    'status sistem' => ['admin.ringkasan', []],
    'pengaturan' => ['admin.pengaturan', []],
    'pengaturan bobot' => ['admin.pengaturan', ['tab' => 'bobot']],
    'pengaturan batas' => ['admin.pengaturan', ['tab' => 'batas']],
    'pengaturan surat' => ['admin.pengaturan', ['tab' => 'surat']],
    'pengaturan pengguna' => ['admin.pengaturan', ['tab' => 'pengguna']],
    'pengaturan email' => ['admin.pengaturan', ['tab' => 'email']],
    'pengaturan publik' => ['admin.pengaturan', ['tab' => 'publik']],
    'pengguna' => ['admin.pengguna.index', []],
]);

it('opens every admin screen for an Admin with a confirmed password', function (string $route, array $query): void {
    $this->actingAs(A::admin())
        ->withSession(A::passwordConfirmed())
        ->get(route($route, $query))
        ->assertOk();
})->with('admin screens');

it('asks for the password again before the sensitive admin screens', function (string $route): void {
    $this->actingAs(A::admin())
        ->get(route($route))
        ->assertRedirect(route('password.confirm'));
})->with(['admin.pengaturan', 'admin.pengguna.index']);

it('denies admin screens to every other role', function (RoleName $role): void {
    $user = $role === RoleName::Siswa ? H::activeStudent()->user->fresh() : A::staff($role);

    foreach (['admin.ringkasan', 'admin.pengaturan', 'admin.pengguna.index'] as $route) {
        $this->actingAs($user)->withSession(A::passwordConfirmed())->get(route($route))->assertForbidden();
    }
})->with([RoleName::Siswa, RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas]);

it('sends guests to the login page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['admin.beranda', 'admin.ringkasan', 'admin.pengaturan', 'admin.pengguna.index']);

it('requires TOTP 2FA for the admin area', function (): void {
    config(['fortify.sth.require_staff_2fa' => true]);
    $admin = A::admin();
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin->fresh())->withSession(A::passwordConfirmed())
        ->get(route('admin.ringkasan'))
        ->assertRedirect(route('two-factor.setup'));
});

it('forwards an Admin from /beranda to the admin landing', function (): void {
    $this->actingAs(A::admin())->get(route('home'))->assertRedirect(route('admin.beranda'));
});

it('shows blockers on the status page when nothing is configured', function (): void {
    $this->actingAs(A::admin())
        ->get(route('admin.ringkasan'))
        ->assertOk()
        ->assertSee('Belum ada tanda tangan + stempel')
        ->assertSee('Belum ada akun Kepala Sekolah');
});

it('links the role landings from the menus (UI-MAP .beranda names first)', function (): void {
    $sections = Navigation::for(['name' => 'Operator', 'roles' => ['admin']], 'admin', [], 'admin.beranda')['sections'];
    $items = collect($sections)->flatMap(fn (array $s) => $s['items'])->keyBy('key');

    expect($items['dashboard']['route'])->toBe('admin.beranda')
        ->and($items['dashboard']['current'])->toBeTrue()
        ->and($items['pengaturan']['route'])->toBe('admin.pengaturan')
        ->and($items['pengguna']['route'])->toBe('admin.pengguna.index')
        ->and($items['ringkasan']['route'])->toBe('admin.ringkasan')
        ->and($items['arsip']['href'])->toBe(route('admin.pengaturan', ['tab' => 'batas']));

    foreach (['kepala_sekolah' => ['dashboard', 'kepsek.beranda'], 'tata_usaha' => ['surat', 'tu.beranda'], 'humas' => ['publikasi', 'humas.beranda']] as $role => [$key, $route]) {
        $roleItems = collect(Navigation::for(['name' => 'X', 'roles' => [$role]], $role)['sections'])->flatMap(fn (array $s) => $s['items'])->keyBy('key');
        expect($roleItems[$key]['route'])->toBe($route);
    }
});
