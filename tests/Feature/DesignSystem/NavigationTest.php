<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Siswa\TurMenu;
use App\Support\Navigation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
| App\Support\Navigation (W1-D): menus per role, current item, disabled items, role choice, initials.
*/

function sthNavRegisterRoutes(array $names): void
{
    foreach ($names as $name) {
        Route::get('/_nav-test/'.str_replace('.', '/', $name), fn () => 'ok')->name($name);
    }

    Route::getRoutes()->refreshNameLookups();
}

/** @return array<string, array<string, mixed>> */
function sthNavItems(array $nav): array
{
    return collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->keyBy('key')->all();
}

afterEach(function (): void {
    Navigation::resolveBadgesUsing(null);
});

it('builds the siswa menu from the spec with disabled items for missing routes', function (): void {
    $nav = Navigation::for(['name' => 'Nadia Putri Anindya', 'meta' => 'Siswa · XI-4'], 'siswa', ['siswa.lomba-saya' => 2], 'none');
    $items = sthNavItems($nav);

    expect(array_column($nav['sections'], 'label'))->toBe(['Menu', 'Akun'])
        ->and(array_keys($items))->toBe(['beranda', 'cari-lomba', 'lomba-saya', 'portofolio', 'peringkat', 'profil-talenta', 'notifikasi', 'keamanan', 'bantuan'])
        // Wave 2 created real routes: an item is disabled exactly while its route is missing.
        ->and($items['beranda']['disabled'])->toBe(! Route::has('siswa.beranda'))
        ->and($items['beranda']['href'])->toBe(Route::has('siswa.beranda') ? route('siswa.beranda') : '#')
        ->and($items['lomba-saya']['badge'])->toBe(2)
        ->and($nav)->not->toHaveKey('cta') // one way to each place: "Cari lomba" is a menu item, no button above the menu
        ->and($nav['tabbar'])->toHaveCount(5)
        // One name per page: the tab bar says what the sidebar says ("Lomba saya", "Portofolio").
        ->and(array_column($nav['tabbar'], 'label'))->toBe(['Beranda', 'Lomba', 'Lomba saya', 'Peringkat', 'Portofolio'])
        ->and($items['lomba-saya']['label'])->toBe('Lomba saya')
        ->and($items['portofolio']['label'])->toBe('Portofolio')
        ->and($nav['user'])->toBe(['name' => 'Nadia Putri Anindya', 'meta' => 'Siswa · XI-4', 'initials' => 'NA']);
});

it('draws the tab replicas of the mobile menu tour with the tab bar labels', function (): void {
    // Siswa\TurMenu covers a tab with a copy of it: a tab that is renamed must be renamed there too.
    $tabs = array_column(Navigation::tabbar('none'), 'label');

    expect(array_column(TurMenu::mobileTips(), 'label'))->toBe(array_slice($tabs, 1))
        ->and(array_column(TurMenu::mobileTips(), 'title'))->toBe(array_slice($tabs, 1));
});

it('marks the matching route as current and links existing routes', function (): void {
    sthNavRegisterRoutes(['siswa.beranda', 'siswa.lomba.index', 'siswa.lomba.show']);

    $items = sthNavItems(Navigation::for(null, 'siswa', [], 'siswa.lomba.show'));

    expect($items['cari-lomba']['current'])->toBeTrue()
        ->and($items['cari-lomba']['disabled'])->toBeFalse()
        ->and($items['cari-lomba']['href'])->toBe(route('siswa.lomba.index'))
        ->and($items['beranda']['current'])->toBeFalse();
});

it('prefers the exact route over a wildcard match (TU arsip vs antrean)', function (): void {
    sthNavRegisterRoutes(['tu.surat.index', 'tu.surat.arsip']);

    $items = sthNavItems(Navigation::for(null, 'tata_usaha', [], 'tu.surat.arsip'));

    expect($items['arsip']['current'])->toBeTrue()
        ->and($items['surat']['current'])->toBeFalse();
});

it('uses ?tab=verifikasi to highlight Usulan lomba over Kelola lomba', function (): void {
    sthNavRegisterRoutes(['kesiswaan.lomba.index']);

    request()->query->set('tab', 'verifikasi');
    $items = sthNavItems(Navigation::for(null, 'kesiswaan', [], 'kesiswaan.lomba.index'));
    expect($items['usulan']['current'])->toBeTrue()
        ->and($items['usulan']['href'])->toContain('tab=verifikasi')
        ->and($items['kelola-lomba']['current'])->toBeFalse();

    request()->query->remove('tab');
    $items = sthNavItems(Navigation::for(null, 'kesiswaan', [], 'kesiswaan.lomba.index'));
    expect($items['kelola-lomba']['current'])->toBeTrue()
        ->and($items['usulan']['current'])->toBeFalse();
});

it('picks the menu from the route area only when the user has that role', function (): void {
    expect(Navigation::for(['name' => 'X', 'roles' => ['admin', 'kesiswaan']], null, [], 'kesiswaan.lomba.index')['role'])->toBe('kesiswaan')
        ->and(Navigation::for(['name' => 'X', 'roles' => ['admin']], null, [], 'kesiswaan.lomba.index')['role'])->toBe('admin')
        ->and(Navigation::for(['name' => 'X', 'roles' => ['guru', 'kesiswaan']], null, [], null)['role'])->toBe('kesiswaan')
        ->and(Navigation::for(['name' => 'X'], null, [], null)['role'])->toBeNull();
});

it('forces the current item by key and renders previews as links', function (): void {
    $nav = Navigation::for(null, 'kepala_sekolah', [], null, active: 'persetujuan', preview: true);
    $items = sthNavItems($nav);

    expect($items['persetujuan']['current'])->toBeTrue()
        ->and($items['persetujuan']['disabled'])->toBeFalse()
        ->and(collect($items)->where('current', true))->toHaveCount(1);
});

it('never invents badge counts and uses the registered resolver', function (): void {
    expect(sthNavItems(Navigation::for(['name' => 'Ibu Dwi Rahayu'], 'kesiswaan'))['verifikasi']['badge'])->toBeNull();

    Navigation::resolveBadgesUsing(fn (object $user, string $role): array => ['kesiswaan.verifikasi' => 4, 'kesiswaan.usulan' => 0]);
    $items = sthNavItems(Navigation::for((object) ['name' => 'Ibu Dwi Rahayu'], 'kesiswaan'));

    expect($items['verifikasi']['badge'])->toBe(4)
        ->and($items['usulan']['badge'])->toBeNull();
});

it('links the backlog screens from the role menus (W4)', function (string $role, array $expected): void {
    $items = sthNavItems(Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], 'none'));

    foreach ($expected as $key => $route) {
        expect($items)->toHaveKey($key)
            ->and($items[$key]['route'])->toBe($route)
            ->and($items[$key]['href'])->toBe(route($route))
            ->and($items[$key]['disabled'])->toBeFalse();
    }
})->with([
    'siswa' => ['siswa', ['peringkat' => 'siswa.peringkat', 'notifikasi' => 'notifikasi.index', 'keamanan' => 'akun.keamanan']],
    'guru' => ['guru', ['binaan' => 'guru.beranda', 'lomba' => 'guru.lomba.index']],
    'kesiswaan' => ['kesiswaan', ['prestasi' => 'kesiswaan.prestasi.index', 'aktivasi' => 'kesiswaan.aktivasi.index']],
    'kepala sekolah' => ['kepala_sekolah', ['prestasi' => 'kepsek.prestasi', 'surat' => 'kepsek.surat.index', 'laporan' => 'kepsek.laporan']],
    'tata usaha' => ['tata_usaha', ['template' => 'tu.surat.template']],
    'admin' => ['admin', ['aktivasi' => 'admin.aktivasi.index', 'arsip' => 'admin.arsip.index', 'audit' => 'admin.audit.index']],
]);

it('marks the backlog screens current on their own routes', function (string $role, string $route, string $key): void {
    $items = sthNavItems(Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], $route));

    expect($items[$key]['current'])->toBeTrue()
        ->and(collect($items)->where('current', true))->toHaveCount(1);
})->with([
    ['kesiswaan', 'kesiswaan.aktivasi.index', 'aktivasi'],
    ['admin', 'admin.aktivasi.index', 'aktivasi'],
    ['admin', 'admin.arsip.index', 'arsip'],
    ['admin', 'admin.audit.index', 'audit'],
    ['kepala_sekolah', 'kepsek.surat.index', 'surat'],
    ['siswa', 'akun.keamanan', 'keamanan'],
    ['siswa', 'notifikasi.index', 'notifikasi'],
]);

it('puts Notifikasi and Keamanan akun in the avatar menu of every role', function (): void {
    $html = Blade::render('<x-sth.topbar :nav="$nav" title="Beranda" />', [
        'nav' => Navigation::for(['name' => 'Ibu Dwi Rahayu', 'roles' => ['kesiswaan']], 'kesiswaan', [], 'none'),
    ]);

    expect($html)->toContain('href="'.route('notifikasi.index').'"')
        ->and($html)->toContain('href="'.route('akun.keamanan').'"')
        ->and($html)->toContain('Keamanan akun');
});

it('covers exactly the roles of App\Enums\RoleName', function (): void {
    $roles = array_map(fn (RoleName $r): string => $r->value, RoleName::cases());

    expect(Navigation::ROLES)->toEqualCanonicalizing($roles)
        ->and(array_keys(Navigation::definitions()))->toEqualCanonicalizing($roles);
});

it('builds initials from the first and last name, skipping honorifics and degrees', function (string $name, string $initials): void {
    expect(Navigation::initials($name))->toBe($initials);
})->with([
    ['Nadia Putri Anindya', 'NA'],
    ['Ibu Dwi Rahayu', 'DR'],
    ['Drs. Bambang Hartono, M.Pd.', 'BH'],
    ['Pak Hendra Kusuma, S.Pd., M.Si.', 'HK'],
    ['Operator STH', 'OS'],
    ['Keisha', 'K'],
    ['', ''],
]);
