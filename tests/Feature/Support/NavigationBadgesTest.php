<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use App\Providers\NavigationServiceProvider;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
| M2-C (QA Q1-05): sidebar counts on every page. App\Providers\NavigationServiceProvider registers the one
| resolver App\Support\Navigation asks, so a page that passes no `badges` to the layout (Arsip surat, Template
| surat, Notifikasi, Keamanan akun, …) shows the same numbers as the queue screens. Counts only for a user who
| may see the queue, one COUNT per badge, nothing for guests; a page's own badges still win.
*/

beforeEach(function (): void {
    H::seedRoles();
    config(['fortify.sth.require_staff_2fa' => false]);
    Notification::fake();
    $this->withoutVite();
});

/** The number the sidebar shows for a badge key: null when the element is missing or hidden. */
function m2cSidebarBadge(string $html, string $key): ?int
{
    if (preg_match('/<span class="badge num" data-sth-badge="'.preg_quote($key, '/').'"([^>]*)>\s*(\d+)\s*<\/span>/', $html, $m) !== 1) {
        return null;
    }

    return str_contains($m[1], 'hidden') ? null : (int) $m[2];
}

/** @return array<string, array<string, mixed>> */
function m2cNavItems(array $nav): array
{
    return collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->keyBy('key')->all();
}

it('shows the Tata Usaha queue count on pages that pass no badges', function (string $route): void {
    Registration::factory()->count(2)->status(RegistrationStatus::Disetujui)->create();
    Registration::factory()->create(); // menunggu_kepsek: not in the TU queue

    $response = $this->actingAs(H::staff(RoleName::TataUsaha))->get(route($route))->assertOk();

    expect(m2cSidebarBadge($response->getContent(), 'tu.surat'))->toBe(2)
        // sidebar + mobile drawer: both carry the hook the browser event `sth-badges` updates
        ->and(substr_count($response->getContent(), 'data-sth-badge="tu.surat"'))->toBe(2);
})->with(['tu.surat.arsip', 'tu.surat.template', 'notifikasi.index', 'akun.keamanan']);

it('hides the count on those pages when the queue is empty', function (): void {
    $response = $this->actingAs(H::staff(RoleName::TataUsaha))->get(route('tu.surat.arsip'))->assertOk();

    expect(m2cSidebarBadge($response->getContent(), 'tu.surat'))->toBeNull()
        ->and($response->getContent())->toContain('data-sth-badge="tu.surat"');
});

it('shows both Kesiswaan counts on the account pages', function (string $route): void {
    Competition::factory()->count(3)->create(); // menunggu_verifikasi
    Competition::factory()->published()->create();
    Report::factory()->count(2)->create(); // dikirim
    Report::factory()->verified()->create();

    $html = $this->actingAs(H::staff(RoleName::Kesiswaan))->get(route($route))->assertOk()->getContent();

    expect(m2cSidebarBadge($html, 'kesiswaan.usulan'))->toBe(3)
        ->and(m2cSidebarBadge($html, 'kesiswaan.verifikasi'))->toBe(2);
})->with(['notifikasi.index', 'akun.keamanan']);

it('shows the Kepala Sekolah and Humas counts on pages that pass none', function (): void {
    Registration::factory()->count(2)->create(); // menunggu_kepsek
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();

    $named = Student::factory()->create();
    foreach (Achievement::factory()->count(2)->create(['student_id' => $named->getKey()]) as $achievement) {
        Publication::factory()->create(['achievement_id' => $achievement->getKey()]);
    }
    // A disabled account is never named to Humas, so its card is not in the queue (D-162: no consent any more).
    $disabled = Student::factory()->create(['user_id' => User::factory()->disabled()]);
    Publication::factory()->create(['achievement_id' => Achievement::factory()->create(['student_id' => $disabled->getKey()])->getKey()]);

    $kepsek = $this->actingAs(H::staff(RoleName::KepalaSekolah))->get(route('kepsek.surat.index'))->assertOk()->getContent();
    $humas = $this->actingAs(H::staff(RoleName::Humas))->get(route('notifikasi.index'))->assertOk()->getContent();

    expect(m2cSidebarBadge($kepsek, 'kepsek.persetujuan'))->toBe(2)
        ->and(m2cSidebarBadge($humas, 'humas.publikasi'))->toBe(2);
});

it('keeps the number of the page when the page passes one', function (): void {
    Registration::factory()->count(2)->status(RegistrationStatus::Disetujui)->create();
    $tu = H::staff(RoleName::TataUsaha);

    // The queue screen passes its own count: same number, and the resolver does not count again.
    $html = $this->actingAs($tu)->get(route('tu.beranda'))->assertOk()->getContent();
    expect(m2cSidebarBadge($html, 'tu.surat'))->toBe(2);

    DB::enableQueryLog();
    // D-150: the TU menu also counts "Persetujuan lomba"; a page that passes both keys costs no count query.
    $items = m2cNavItems(Navigation::for($tu, null, ['tu.surat' => 9, 'kepsek.persetujuan' => 4], 'tu.surat.arsip'));
    $counts = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'count(*)'));
    DB::disableQueryLog();

    expect($items['surat']['badge'])->toBe(9)
        ->and($items['persetujuan']['badge'])->toBe(4)
        ->and($counts)->toBeEmpty();
});

it('counts only for a user who may see the queue', function (): void {
    Registration::factory()->count(2)->create();
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();
    Competition::factory()->count(2)->create();

    $admin = H::staff(RoleName::Admin);
    $guru = H::staff(RoleName::Guru);
    $kesiswaan = H::staff(RoleName::Kesiswaan);
    $siswa = User::query()->findOrFail(H::activeStudent()->user_id);

    // Menus without a counted badge cost nothing.
    expect(NavigationServiceProvider::badges($admin, 'admin'))->toBe([])
        ->and(NavigationServiceProvider::badges($guru, 'guru'))->toBe([])
        ->and(NavigationServiceProvider::badges($siswa, 'siswa'))->toBe([])
        // The permission decides, not the menu that happens to be rendered.
        ->and(NavigationServiceProvider::badges($guru, 'tata_usaha'))->toBe([])
        ->and(NavigationServiceProvider::badges($guru, 'kesiswaan'))->toBe([])
        ->and(NavigationServiceProvider::badges($kesiswaan, 'kepala_sekolah'))->toBe([])
        // D-01: Admin never approves, so it gets no approval count; it does hold the Kesiswaan permissions.
        ->and(NavigationServiceProvider::badges($admin, 'kepala_sekolah'))->toBe([])
        ->and(NavigationServiceProvider::badges($admin, 'kesiswaan'))->toBe(['kesiswaan.usulan' => 2, 'kesiswaan.verifikasi' => 0])
        ->and(NavigationServiceProvider::badges($admin, 'tata_usaha'))->toBe(['tu.surat' => 1]);

    // An Admin page shows the Admin menu, which has no badge. The only count hook left on the page is the bell
    // of the top bar (D-127), which every signed-in page carries once.
    $html = $this->actingAs($admin)->get(route('notifikasi.index'))->assertOk()->getContent();
    expect(substr_count($html, 'data-sth-badge='))->toBe(1)
        ->and($html)->toContain('data-sth-badge="'.Navigation::BELL.'"');
});

it('keeps the bell out of the menu keys and adds it to the live counts (D-127)', function (): void {
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();

    $tu = H::staff(RoleName::TataUsaha);
    $siswa = User::query()->findOrFail(H::activeStudent()->user_id);

    // The bell is a key of the `sth-badges` event, not a menu item: no menu lists it, the sidebar never counts it.
    expect(Navigation::BELL)->toBe('akun.notifikasi')
        ->and(Navigation::BADGES)->not->toContain(Navigation::BELL)
        ->and(NavigationServiceProvider::badges($tu, 'tata_usaha'))->not->toHaveKey(Navigation::BELL);

    // What GET notifikasi.jumlah answers: the bell first, then the counts of the user's own menus.
    $counts = NavigationServiceProvider::liveCounts($tu);

    expect(array_key_first($counts))->toBe(Navigation::BELL)
        ->and($counts)->toEqual([Navigation::BELL => 0] + NavigationServiceProvider::badges($tu, 'tata_usaha'))
        ->and($counts['tu.surat'])->toBe(1)
        ->and(NavigationServiceProvider::liveCounts($siswa))->toBe([Navigation::BELL => 0]);
});

it('runs one COUNT per badge and nothing else', function (): void {
    Competition::factory()->count(2)->create();
    $kesiswaan = H::staff(RoleName::Kesiswaan);
    NavigationServiceProvider::badges($kesiswaan, 'kesiswaan'); // warm the role/permission lookups

    DB::enableQueryLog();
    $badges = NavigationServiceProvider::badges($kesiswaan, 'kesiswaan');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($badges)->toBe(['kesiswaan.usulan' => 2, 'kesiswaan.verifikasi' => 0])
        ->and($queries)->toHaveCount(2)
        ->and($queries)->each->toStartWith('select count(*)');

    // Keys the page passed are skipped.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $rest = NavigationServiceProvider::badges($kesiswaan, 'kesiswaan', ['kesiswaan.usulan']);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($rest)->toBe(['kesiswaan.verifikasi' => 0])
        ->and($queries)->toHaveCount(1);
});

it('never counts for guests or preview users', function (): void {
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();

    DB::enableQueryLog();
    $guest = m2cNavItems(Navigation::for(null, 'tata_usaha', [], 'tu.surat.arsip'));
    $preview = m2cNavItems(Navigation::for(['name' => 'Ibu Sri Handayani', 'roles' => ['tata_usaha']], null, [], 'tu.surat.arsip'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($guest['surat']['badge'])->toBeNull()
        ->and($preview['surat']['badge'])->toBeNull()
        ->and($queries)->toBe([])
        ->and(NavigationServiceProvider::badges((object) ['name' => 'Bukan model'], 'tata_usaha'))->toBe([]);
});

it('lists the badge keys of every role menu', function (): void {
    expect(Navigation::badgeKeys('kesiswaan'))->toBe(['kesiswaan.usulan', 'kesiswaan.verifikasi'])
        ->and(Navigation::badgeKeys('tata_usaha'))->toBe(['tu.surat', 'kepsek.persetujuan'])
        ->and(Navigation::badgeKeys('kepala_sekolah'))->toBe(['kepsek.persetujuan'])
        ->and(Navigation::badgeKeys('humas'))->toBe(['humas.publikasi', 'humas.verifikasi', 'humas.jadwal'])
        ->and(Navigation::badgeKeys('siswa'))->toBe(['siswa.lomba-saya'])
        ->and(Navigation::badgeKeys('admin'))->toBe([])
        ->and(Navigation::badgeKeys('guru'))->toBe([])
        ->and(Navigation::badgeKeys(null))->toBe([])
        ->and(Navigation::badgeKeys('tidak-ada'))->toBe([]);

    $all = collect(Navigation::ROLES)->flatMap(fn (string $role): array => Navigation::badgeKeys($role))->unique()->sort()->values()->all();

    expect($all)->toBe(collect(Navigation::BADGES)->sort()->values()->all());
});
