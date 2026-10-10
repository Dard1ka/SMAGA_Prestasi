<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menus per role for the app shell (DESIGN-SPEC-v2 §4): sidebar sections, the siswa call-to-action,
 * the topbar search target, the mobile tab bar and the public header.
 *
 * Route names follow docs/UI-MAP.md. Each entry lists candidate route names; the first one that exists
 * is used. When none exists yet the item renders as href="#" with aria-disabled="true", so the shell
 * works before wave 2 has created the screens.
 *
 * Badge counts are never invented here. They come from the one resolver that
 * App\Providers\NavigationServiceProvider registers (so every page of a role shows them, M2-C), and a page
 * may pass its own (:badges="['tu.surat' => 3]" / ->layoutData(['badges' => …])): the page's value wins and
 * the resolver is not asked for that key again.
 *     Navigation::resolveBadgesUsing(fn (object $user, string $role, array $except): array => [...]);
 */
final class Navigation
{
    /** @var list<string> */
    public const ROLES = ['siswa', 'guru', 'kesiswaan', 'kepala_sekolah', 'tata_usaha', 'humas', 'admin'];

    /** Badge keys used by the menus. */
    public const BADGES = [
        'siswa.lomba-saya',
        'kesiswaan.usulan',
        'kesiswaan.verifikasi',
        'kepsek.persetujuan',
        'tu.surat',
        'humas.publikasi',
        'humas.jadwal',
    ];

    /**
     * The bell of the top bar: unread database notifications of the signed-in user (D-127). A key of the browser
     * event `sth-badges` like the menu keys, but not a menu item, so it is not in BADGES and no menu lists it.
     */
    public const BELL = 'akun.notifikasi';

    /** Used when a user has several roles and neither the page nor the route decides. */
    private const PRIORITY = ['admin', 'kepala_sekolah', 'kesiswaan', 'tata_usaha', 'humas', 'guru', 'siswa'];

    /** Route-name prefix → role area. */
    private const AREA_PREFIX = [
        'siswa.' => 'siswa',
        'guru.' => 'guru',
        'kesiswaan.' => 'kesiswaan',
        'kepsek.' => 'kepala_sekolah',
        'tu.' => 'tata_usaha',
        'humas.' => 'humas',
        'admin.' => 'admin',
    ];

    private const ROLE_LABELS = [
        'siswa' => 'Siswa',
        'guru' => 'Guru Pembimbing',
        'kesiswaan' => 'Kesiswaan',
        'kepala_sekolah' => 'Kepala Sekolah',
        'tata_usaha' => 'Tata Usaha',
        'humas' => 'Humas',
        'admin' => 'Admin',
    ];

    /** Honorifics skipped when building initials ("Ibu Dwi Rahayu" → "DR"). */
    private const HONORIFICS = ['ibu', 'bu', 'bapak', 'pak', 'mas', 'mbak', 'kak', 'drs', 'dra', 'dr', 'prof', 'hj', 'h'];

    private static ?Closure $badgeResolver = null;

    /**
     * Register one callback that returns badge counts:
     * fn (object $user, string $role, list<string> $except): array<string, int>.
     * `$except` = keys the page passed itself (they win anyway, so the callback can skip their queries).
     */
    public static function resolveBadgesUsing(?Closure $resolver): void
    {
        self::$badgeResolver = $resolver;
    }

    /**
     * Badge keys the menu of a role shows (e.g. kesiswaan → kesiswaan.usulan, kesiswaan.verifikasi).
     *
     * @return list<string>
     */
    public static function badgeKeys(?string $role): array
    {
        $keys = [];

        foreach (self::definitions()[$role]['sections'] ?? [] as $section) {
            foreach ($section['items'] as $def) {
                if (isset($def['badge'])) {
                    $keys[] = (string) $def['badge'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role] ?? '';
    }

    /**
     * Everything the app shell needs for one user and role.
     *
     * @param  object|array<string, mixed>|null  $user  a User model or ['name' => …, 'meta' => …]
     * @param  array<string, int|null>  $badges  badge key => count (overrides the resolver)
     * @param  ?string  $active  force the current item by menu key (e.g. 'cari-lomba'); default: route match
     * @param  bool  $preview  dev showcase: missing routes render as normal '#' links instead of disabled
     * @return array{
     *     role: ?string, roleLabel: string, homeUrl: string,
     *     search: ?array<string, mixed>,
     *     sections: list<array{label: string, items: list<array<string, mixed>>}>,
     *     tabbar: list<array<string, mixed>>,
     *     user: array{name: string, meta: string, initials: string},
     * }
     */
    public static function for(
        object|array|null $user = null,
        ?string $role = null,
        array $badges = [],
        ?string $currentRoute = null,
        ?string $active = null,
        bool $preview = false,
    ): array {
        $currentRoute ??= Route::currentRouteName();
        $role = self::resolveRole($user, $role, $currentRoute);
        $badges = array_merge(self::resolvedBadges($user, $role, array_map('strval', array_keys($badges))), $badges);
        $definition = $role !== null ? self::definitions()[$role] : ['search' => null, 'sections' => []];

        $sections = [];
        $flat = [];

        foreach ($definition['sections'] as $sectionIndex => $section) {
            $items = [];

            foreach ($section['items'] as $itemIndex => $def) {
                $items[] = self::item($def, $badges, $preview);
                $flat[] = [$sectionIndex, $itemIndex];
            }

            $sections[] = ['label' => $section['label'], 'items' => $items];
        }

        // Exactly one item is current: the forced key, else the best route match (exact + query > wildcard).
        $best = null;
        $bestScore = 0;

        foreach ($flat as [$s, $i]) {
            $score = $active !== null
                ? (int) ($sections[$s]['items'][$i]['key'] === $active)
                : self::matchScore($definition['sections'][$s]['items'][$i], $sections[$s]['items'][$i]['route'], $currentRoute);

            if ($score > $bestScore) {
                [$best, $bestScore] = [[$s, $i], $score];
            }
        }

        if ($best !== null) {
            $sections[$best[0]]['items'][$best[1]]['current'] = true;
        }

        return [
            'role' => $role,
            'roleLabel' => self::roleLabel($role),
            'homeUrl' => self::homeUrl($role),
            'search' => self::search($definition['search']),
            'sections' => $sections,
            'tabbar' => $role === 'siswa' ? self::tabbar($currentRoute, $active, $preview) : [],
            'user' => self::userCard($user, $role),
        ];
    }

    /**
     * Siswa mobile tab bar (DESIGN-SPEC-v2 §4 Mobile). `$active` accepts tab keys or sidebar keys.
     *
     * @return list<array<string, mixed>>
     */
    public static function tabbar(?string $currentRoute = null, ?string $active = null, bool $preview = false): array
    {
        $currentRoute ??= Route::currentRouteName();
        $defs = [
            ['key' => 'beranda', 'label' => 'Beranda', 'icon' => 'home', 'route' => ['siswa.beranda'], 'aliases' => []],
            ['key' => 'lomba', 'label' => 'Lomba', 'icon' => 'search', 'route' => ['siswa.lomba.index', 'siswa.katalog'], 'match' => ['siswa.lomba.*', 'siswa.katalog*'], 'aliases' => ['cari-lomba']],
            ['key' => 'lombaku', 'label' => 'Lomba saya', 'icon' => 'clipboard', 'route' => ['siswa.lomba-saya.index', 'siswa.lomba-saya'], 'match' => ['siswa.lomba-saya*', 'siswa.lapor*'], 'aliases' => ['lomba-saya']],
            ['key' => 'peringkat', 'label' => 'Peringkat', 'icon' => 'bars', 'route' => ['siswa.peringkat', 'peringkat', 'public.peringkat'], 'aliases' => []],
            ['key' => 'profil', 'label' => 'Portofolio', 'icon' => 'award', 'route' => ['siswa.portofolio'], 'match' => ['siswa.portofolio*', 'siswa.talenta*'], 'aliases' => ['portofolio', 'profil-talenta']],
        ];

        $items = array_map(fn (array $def): array => self::item($def, [], $preview), $defs);
        $scores = array_map(
            fn (array $def, array $item): int => $active !== null
                ? (int) ($def['key'] === $active || in_array($active, $def['aliases'], true))
                : self::matchScore($def, $item['route'], $currentRoute),
            $defs,
            $items,
        );
        $max = max($scores);

        if ($max > 0) {
            $items[array_search($max, $scores, true)]['current'] = true;
        }

        return $items;
    }

    /**
     * Public header links (Beranda · Prestasi · Peringkat) + login.
     *
     * @return array{links: list<array<string, mixed>>, login: array<string, mixed>}
     */
    public static function publicLinks(?string $current = null): array
    {
        $links = [
            self::item(['key' => 'beranda', 'label' => 'Beranda', 'icon' => 'home', 'route' => ['public.home', 'home']], []),
            self::item(['key' => 'prestasi', 'label' => 'Prestasi', 'icon' => 'award', 'route' => ['public.prestasi', 'prestasi']], []),
            self::item(['key' => 'peringkat', 'label' => 'Peringkat', 'icon' => 'bars', 'route' => ['public.peringkat', 'peringkat']], []),
        ];

        foreach ($links as $index => $link) {
            $links[$index]['current'] = $current !== null && $link['key'] === $current;
        }

        return [
            'links' => $links,
            'login' => self::item(['key' => 'masuk', 'label' => 'Masuk', 'icon' => 'user', 'route' => ['login']], []),
        ];
    }

    /** URL of the first reachable menu item of a role (brand link, "Buka dashboard"). */
    public static function homeUrl(?string $role): string
    {
        if ($role === null) {
            return self::url(['public.home', 'home']) ?? url('/');
        }

        foreach (self::definitions()[$role]['sections'] as $section) {
            foreach ($section['items'] as $def) {
                if (($url = self::url($def['route'])) !== null) {
                    return $url;
                }
            }
        }

        return url('/');
    }

    /** Initials from the first and last name word, skipping honorifics and degrees. */
    public static function initials(?string $name): string
    {
        $name = trim(Str::before((string) $name, ','));
        $words = array_values(array_filter(
            preg_split('/\s+/u', $name) ?: [],
            fn (string $word): bool => $word !== ''
                && ! str_ends_with($word, '.')
                && ! in_array(mb_strtolower($word), self::HONORIFICS, true),
        ));

        if ($words === []) {
            return '';
        }

        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Raw menu definitions per role. 'route' = candidate route names (first existing wins),
     * 'match' = extra route-name patterns that mark the item current, 'query' = required query values.
     *
     * @return array<string, array{search: ?array<string, mixed>, sections: list<array{label: string, items: list<array<string, mixed>>}>}>
     */
    public static function definitions(): array
    {
        $cariLomba = ['key' => 'cari-lomba', 'label' => 'Cari lomba', 'icon' => 'search', 'route' => ['siswa.lomba.index', 'siswa.katalog'], 'match' => ['siswa.lomba.*', 'siswa.katalog*']];
        $kelolaLomba = ['key' => 'kelola-lomba', 'label' => 'Kelola lomba', 'icon' => 'trophy', 'route' => ['kesiswaan.lomba.index', 'kesiswaan.lomba'], 'match' => ['kesiswaan.lomba.*']];
        $notifikasi = ['key' => 'notifikasi', 'label' => 'Notifikasi', 'icon' => 'bell', 'route' => ['notifikasi.index']];
        $keamanan = ['key' => 'keamanan', 'label' => 'Keamanan akun', 'icon' => 'lock', 'route' => ['akun.keamanan']];

        return [
            'siswa' => [
                'search' => ['placeholder' => 'Cari lomba', 'route' => ['siswa.lomba.index', 'siswa.katalog']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'beranda', 'label' => 'Beranda', 'icon' => 'home', 'route' => ['siswa.beranda']],
                        $cariLomba,
                        ['key' => 'lomba-saya', 'label' => 'Lomba saya', 'icon' => 'clipboard', 'route' => ['siswa.lomba-saya.index', 'siswa.lomba-saya'], 'match' => ['siswa.lomba-saya*', 'siswa.lapor*'], 'badge' => 'siswa.lomba-saya'],
                        ['key' => 'portofolio', 'label' => 'Portofolio', 'icon' => 'award', 'route' => ['siswa.portofolio']],
                        ['key' => 'peringkat', 'label' => 'Peringkat', 'icon' => 'bars', 'route' => ['siswa.peringkat', 'peringkat', 'public.peringkat']],
                    ]],
                    ['label' => 'Akun', 'items' => [
                        ['key' => 'profil-talenta', 'label' => 'Profil talenta', 'icon' => 'user', 'route' => ['siswa.talenta', 'siswa.profil-talenta']],
                        // B1/W4: account pages of every role (staff reach them from the avatar menu and the bell).
                        $notifikasi,
                        $keamanan,
                        ['key' => 'bantuan', 'label' => 'Bantuan', 'icon' => 'help', 'route' => ['siswa.bantuan', 'bantuan']],
                    ]],
                ],
            ],
            'guru' => [
                'search' => ['placeholder' => 'Cari siswa binaan', 'route' => ['guru.beranda', 'guru.binaan', 'guru.siswa']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'binaan', 'label' => 'Siswa binaan', 'icon' => 'users', 'route' => ['guru.beranda', 'guru.binaan', 'guru.siswa']],
                        ['key' => 'lomba', 'label' => 'Lomba', 'icon' => 'trophy', 'route' => ['guru.lomba.index', 'guru.lomba'], 'match' => ['guru.lomba.*']],
                    ]],
                ],
            ],
            'kesiswaan' => [
                'search' => ['placeholder' => 'Cari lomba', 'route' => ['kesiswaan.lomba.index', 'kesiswaan.lomba']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'beranda', 'label' => 'Beranda', 'icon' => 'home', 'route' => ['kesiswaan.beranda']],
                        $kelolaLomba,
                        // D-27: this queue lists every pending lomba upload (siswa, guru, staf).
                        ['key' => 'usulan', 'label' => 'Usulan lomba', 'icon' => 'inbox', 'route' => [['kesiswaan.lomba.index', ['tab' => 'verifikasi']], 'kesiswaan.usulan'], 'match' => ['kesiswaan.usulan*'], 'query' => ['tab' => 'verifikasi'], 'badge' => 'kesiswaan.usulan'],
                        ['key' => 'verifikasi', 'label' => 'Verifikasi hasil', 'icon' => 'shield', 'route' => ['kesiswaan.verifikasi.index', 'kesiswaan.verifikasi'], 'match' => ['kesiswaan.verifikasi*'], 'badge' => 'kesiswaan.verifikasi'],
                    ]],
                    ['label' => 'Data', 'items' => [
                        ['key' => 'prestasi', 'label' => 'Database prestasi', 'icon' => 'database', 'route' => ['kesiswaan.prestasi.index', 'kesiswaan.prestasi']],
                        ['key' => 'talenta', 'label' => 'Talenta siswa', 'icon' => 'users', 'route' => ['kesiswaan.talenta.index', 'kesiswaan.talenta']],
                        ['key' => 'apresiasi', 'label' => 'Apresiasi', 'icon' => 'award', 'route' => ['kesiswaan.apresiasi.index', 'kesiswaan.apresiasi']],
                        // D-26: Kesiswaan may import students too.
                        ['key' => 'import', 'label' => 'Import siswa', 'icon' => 'upload', 'route' => ['kesiswaan.import']],
                        // B1 (K9/K2): activation report, reopen windows.
                        ['key' => 'aktivasi', 'label' => 'Status aktivasi', 'icon' => 'check-circle', 'route' => ['kesiswaan.aktivasi.index'], 'match' => ['kesiswaan.aktivasi*']],
                    ]],
                ],
            ],
            'kepala_sekolah' => [
                'search' => ['placeholder' => 'Cari siswa atau lomba', 'route' => ['kepsek.persetujuan.index', 'kepsek.persetujuan']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bars', 'route' => ['kepsek.beranda', 'kepsek.dashboard']],
                        ['key' => 'persetujuan', 'label' => 'Persetujuan', 'icon' => 'check-circle', 'route' => ['kepsek.persetujuan.index', 'kepsek.persetujuan'], 'match' => ['kepsek.persetujuan*'], 'badge' => 'kepsek.persetujuan'],
                        ['key' => 'prestasi', 'label' => 'Prestasi per bidang', 'icon' => 'chart-pie', 'route' => ['kepsek.prestasi', 'public.prestasi', 'prestasi']],
                        // B5 (SECURITY §3m-5): every letter generated in the principal's name, revocations included.
                        ['key' => 'surat', 'label' => 'Surat atas nama saya', 'icon' => 'mail', 'route' => ['kepsek.surat.index'], 'match' => ['kepsek.surat*']],
                    ]],
                    ['label' => 'Laporan', 'items' => [
                        ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'file', 'route' => ['kepsek.laporan']],
                    ]],
                ],
            ],
            'tata_usaha' => [
                'search' => ['placeholder' => 'Cari lomba atau siswa', 'route' => ['tu.beranda', 'tu.surat.index', 'tu.surat']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'surat', 'label' => 'Antrean surat', 'icon' => 'mail', 'route' => ['tu.beranda', 'tu.surat.index', 'tu.surat'], 'match' => ['tu.surat.*'], 'badge' => 'tu.surat'],
                        ['key' => 'arsip', 'label' => 'Arsip surat', 'icon' => 'folder', 'route' => ['tu.surat.arsip', 'tu.arsip']],
                        ['key' => 'template', 'label' => 'Template surat', 'icon' => 'file', 'route' => ['tu.surat.template', 'tu.template']],
                    ]],
                ],
            ],
            'humas' => [
                'search' => ['placeholder' => 'Cari prestasi atau siswa', 'route' => ['humas.beranda', 'humas.publikasi.index', 'humas.publikasi']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'publikasi', 'label' => 'Antrean publikasi', 'icon' => 'megaphone', 'route' => ['humas.beranda', 'humas.publikasi.index', 'humas.publikasi'], 'match' => ['humas.publikasi*'], 'badge' => 'humas.publikasi'],
                        ['key' => 'jadwal', 'label' => 'Jadwal', 'icon' => 'calendar', 'route' => ['humas.jadwal'], 'badge' => 'humas.jadwal'],
                        ['key' => 'apresiasi', 'label' => 'Apresiasi', 'icon' => 'award', 'route' => ['humas.apresiasi.index', 'humas.apresiasi']],
                        ['key' => 'arsip', 'label' => 'Arsip', 'icon' => 'folder', 'route' => ['humas.arsip']],
                    ]],
                ],
            ],
            'admin' => [
                'search' => ['placeholder' => 'Cari nama, NIS, atau NISN', 'route' => ['admin.pengguna.index', 'admin.pengguna']],
                'sections' => [
                    ['label' => 'Menu', 'items' => [
                        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bars', 'route' => ['admin.beranda', 'admin.dashboard']],
                        ['key' => 'kelola-lomba', 'label' => 'Kelola lomba', 'icon' => 'trophy', 'route' => ['kesiswaan.lomba.index', 'admin.lomba'], 'match' => ['kesiswaan.lomba.*', 'admin.lomba*']],
                    ]],
                    ['label' => 'Data', 'items' => [
                        ['key' => 'import', 'label' => 'Import siswa', 'icon' => 'upload', 'route' => ['admin.import']],
                        ['key' => 'pengguna', 'label' => 'Pengguna & peran', 'icon' => 'users', 'route' => ['admin.pengguna.index', 'admin.pengguna'], 'match' => ['admin.pengguna*']],
                        // B1 (K9/K2/K1): activation report + activation window setting.
                        ['key' => 'aktivasi', 'label' => 'Status aktivasi', 'icon' => 'check-circle', 'route' => ['admin.aktivasi.index'], 'match' => ['admin.aktivasi*']],
                        // B5: Admin\ArsipOtomatis (the Pengaturan › Batas & arsip fallback is gone).
                        ['key' => 'arsip', 'label' => 'Arsip otomatis', 'icon' => 'archive', 'route' => ['admin.arsip.index'], 'match' => ['admin.arsip*']],
                    ]],
                    ['label' => 'Sistem', 'items' => [
                        ['key' => 'ringkasan', 'label' => 'Status sistem', 'icon' => 'shield', 'route' => ['admin.ringkasan']],
                        // B5: filterable audit log + export.
                        ['key' => 'audit', 'label' => 'Log audit', 'icon' => 'clipboard', 'route' => ['admin.audit.index'], 'match' => ['admin.audit*']],
                        ['key' => 'pengaturan', 'label' => 'Pengaturan', 'icon' => 'sliders', 'route' => ['admin.pengaturan'], 'match' => ['admin.pengaturan*']],
                    ]],
                ],
            ],
        ];
    }

    /**
     * @param  object|array<string, mixed>|null  $user
     */
    private static function resolveRole(object|array|null $user, ?string $explicit, ?string $currentRoute): ?string
    {
        if ($explicit !== null && in_array($explicit, self::ROLES, true)) {
            return $explicit;
        }

        $roles = self::rolesOf($user);

        if ($currentRoute !== null) {
            foreach (self::AREA_PREFIX as $prefix => $role) {
                if (str_starts_with($currentRoute, $prefix) && ($roles === [] || in_array($role, $roles, true))) {
                    return $role;
                }
            }
        }

        foreach (self::PRIORITY as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * @param  object|array<string, mixed>|null  $user
     * @return list<string>
     */
    private static function rolesOf(object|array|null $user): array
    {
        if (is_object($user) && method_exists($user, 'getRoleNames')) {
            return array_values(array_map('strval', $user->getRoleNames()->all()));
        }

        $roles = data_get($user, 'roles');

        return is_array($roles) ? array_values(array_map('strval', $roles)) : [];
    }

    /**
     * Counts from the registered resolver. Nothing for guests and preview arrays; a failing query costs the
     * numbers, never the page.
     *
     * @param  object|array<string, mixed>|null  $user
     * @param  list<string>  $except  keys the page passed itself
     * @return array<string, int|null>
     */
    private static function resolvedBadges(object|array|null $user, ?string $role, array $except = []): array
    {
        if (self::$badgeResolver === null || ! is_object($user) || $role === null) {
            return [];
        }

        $resolver = self::$badgeResolver;

        return (array) rescue(fn () => $resolver($user, $role, $except), [], true);
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array<string, int|null>  $badges
     * @return array{key: string, label: string, icon: string, route: ?string, href: string, current: bool, disabled: bool, badge: ?int}
     */
    private static function item(array $def, array $badges, bool $preview = false): array
    {
        [$route, $url] = self::resolve($def['route']);
        $badge = isset($def['badge']) ? ($badges[$def['badge']] ?? null) : null;

        return [
            'key' => (string) $def['key'],
            'label' => (string) $def['label'],
            'icon' => (string) $def['icon'],
            'route' => $route,
            'href' => $url ?? '#',
            'current' => false,
            'disabled' => $url === null && ! $preview,
            'badge' => $badge !== null && (int) $badge > 0 ? (int) $badge : null,
            // The sidebar prints this as data-sth-badge so the browser event `sth-badges` can update the count in place.
            'badgeKey' => isset($def['badge']) ? (string) $def['badge'] : null,
        ];
    }

    /**
     * @param  list<string|array{0: string, 1: array<string, mixed>}>  $candidates
     * @return array{0: ?string, 1: ?string}
     */
    private static function resolve(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            [$name, $parameters] = is_array($candidate) ? $candidate : [$candidate, []];

            if (Route::has($name)) {
                try {
                    return [$name, route($name, $parameters)];
                } catch (Throwable) {
                    continue; // route needs parameters a menu cannot supply
                }
            }
        }

        return [null, null];
    }

    /**
     * @param  list<string|array{0: string, 1: array<string, mixed>}>  $candidates
     */
    private static function url(array $candidates): ?string
    {
        return self::resolve($candidates)[1];
    }

    /**
     * 0 = not current, 1 = wildcard match, 2 = exact route, +1 when required query values match.
     *
     * @param  array<string, mixed>  $def
     */
    private static function matchScore(array $def, ?string $route, ?string $currentRoute): int
    {
        if ($currentRoute === null || $route === null) {
            return 0;
        }

        $query = (array) ($def['query'] ?? []);
        $queryMatches = $query !== [] && collect($query)->every(fn ($value, $key): bool => (string) request()->query((string) $key) === (string) $value);

        if ($query !== [] && ! $queryMatches) {
            // An item bound to ?tab=… only competes through its own extra patterns.
            return Str::is((array) ($def['match'] ?? []), $currentRoute) ? 1 : 0;
        }

        if ($currentRoute === $route) {
            return $queryMatches ? 3 : 2;
        }

        $patterns = array_merge([$route.'.*'], (array) ($def['match'] ?? []));

        return Str::is($patterns, $currentRoute) ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>|null  $def
     * @return array{placeholder: string, action: string, name: string}|null
     */
    private static function search(?array $def): ?array
    {
        if ($def === null) {
            return null;
        }

        return [
            'placeholder' => (string) $def['placeholder'],
            'action' => self::url($def['route']) ?? url()->current(),
            'name' => 'q',
        ];
    }

    /**
     * @param  object|array<string, mixed>|null  $user
     * @return array{name: string, meta: string, initials: string}
     */
    private static function userCard(object|array|null $user, ?string $role): array
    {
        $name = (string) data_get($user, 'name', '');
        $meta = data_get($user, 'meta');

        if ($meta === null) {
            $meta = self::roleLabel($role);
            $class = $role === 'siswa' ? self::classroomOf($user) : null;

            if ($class !== null && $class !== '') {
                $meta .= ' · '.$class;
            }
        }

        return ['name' => $name, 'meta' => (string) $meta, 'initials' => self::initials($name)];
    }

    /**
     * Class name for the siswa user card ("Siswa · XI-4"). Loads the relation explicitly so strict
     * mode (no lazy loading) is respected; returns null while the relation does not exist yet.
     *
     * @param  object|array<string, mixed>|null  $user
     */
    private static function classroomOf(object|array|null $user): ?string
    {
        if (is_array($user)) {
            $class = data_get($user, 'classroom');

            return is_string($class) ? $class : null;
        }

        if (! is_object($user) || ! method_exists($user, 'student') || ! method_exists($user, 'loadMissing')) {
            return null;
        }

        return rescue(function () use ($user): ?string {
            $user->loadMissing('student.classroom');
            $class = data_get($user, 'student.classroom.name');

            return is_string($class) ? $class : null;
        }, null, false);
    }
}
