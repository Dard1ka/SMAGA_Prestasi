<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\NotificationCountController;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Providers\NavigationServiceProvider;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| D-127 (lead's mail / notification test of 2026-10-09, finding 1-3): the bell in the top bar and the sidebar
| counts follow the state without a page load. One JSON address, GET /notifikasi/jumlah (`notifikasi.jumlah`),
| answers the unread count of the signed-in user plus the sidebar counts of the user's own menus; resources/js/app.js
| asks it while a page is open and in use. It acts on the signed-in user only (no ids in or out), has its own
| limit ("lonceng"), is never stored and never becomes a page to land on. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Notification::fake();
    $this->withoutVite();
});

/** Inserts a database notification row for the user. */
function d127Notify(User $user, string $title, bool $read = false): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Verification\\Notice',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['kind' => 'test', 'title' => $title, 'message' => 'Isi '.$title, 'url' => null]),
        'read_at' => $read ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function d127Student(): User
{
    return User::query()->findOrFail(H::activeStudent()->user_id);
}

/** The request app.js sends: both headers, on this request only. */
function d127Ask(): TestResponse
{
    return test()->get(route('notifikasi.jumlah'), [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);
}

// Who may ask ------------------------------------------------------------------------------------------

it('answers a guest with 401 JSON and never becomes the intended address', function (): void {
    d127Ask()->assertUnauthorized()->assertHeader('Content-Type', 'application/json');

    expect(session('url.intended'))->toBeNull();

    // A person without the headers is sent to the login like on any page.
    $this->get(route('notifikasi.jumlah'))->assertRedirect(route('login'));
});

it('answers every role with the own unread count only', function (?RoleName $role): void {
    $user = $role === null ? d127Student() : A::staff($role);
    $other = A::staff(RoleName::Guru);
    d127Notify($user, 'Pertama');
    d127Notify($user, 'Kedua');
    d127Notify($user, 'Sudah dibaca', read: true);
    d127Notify($other, 'Milik orang lain');

    $this->actingAs($user);
    $response = d127Ask()->assertOk()->assertHeader('Content-Type', 'application/json');

    $badges = $response->json('badges');
    $menu = NavigationServiceProvider::badges($user, $role?->value ?? RoleName::Siswa->value);

    expect($badges)->toBeArray()
        ->and($badges[Navigation::BELL])->toBe(2)
        ->and($badges)->toEqual([Navigation::BELL => 2] + $menu)
        // Nothing else in the answer: no names, no ids, no titles.
        ->and(array_keys($response->json()))->toBe(['badges'])
        ->and($response->getContent())->not->toContain('Pertama')->not->toContain('Milik orang lain');
})->with([
    'siswa' => [null],
    'guru' => [RoleName::Guru],
    'kesiswaan' => [RoleName::Kesiswaan],
    'kepala sekolah' => [RoleName::KepalaSekolah],
    'tata usaha' => [RoleName::TataUsaha],
    'humas' => [RoleName::Humas],
    'admin' => [RoleName::Admin],
]);

it('answers the sidebar counts of the own menu, the same numbers the page shows', function (): void {
    Competition::factory()->count(3)->create(); // menunggu_verifikasi
    Report::factory()->count(2)->create(); // dikirim
    Registration::factory()->count(2)->create(); // menunggu_kepsek
    Registration::factory()->status(RegistrationStatus::Disetujui)->create(); // TU queue

    $kesiswaan = A::staff(RoleName::Kesiswaan);
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $tu = A::staff(RoleName::TataUsaha);
    $humas = A::staff(RoleName::Humas);

    $this->actingAs($kesiswaan);
    expect(d127Ask()->assertOk()->json('badges'))
        ->toMatchArray([Navigation::BELL => 0, 'kesiswaan.usulan' => 3, 'kesiswaan.verifikasi' => 2]);

    // The page of the same user prints the same numbers in its sidebar.
    $html = $this->get(route('akun.keamanan'))->assertOk()->getContent();
    expect($html)->toMatch('/data-sth-badge="kesiswaan\.usulan"[^>]*>\s*3\s*</')
        ->toMatch('/data-sth-badge="kesiswaan\.verifikasi"[^>]*>\s*2\s*</');

    $this->actingAs($kepsek);
    expect(d127Ask()->assertOk()->json('badges'))->toMatchArray(['kepsek.persetujuan' => 2]);

    $this->actingAs($tu);
    expect(d127Ask()->assertOk()->json('badges'))->toMatchArray(['tu.surat' => 1]);

    $this->actingAs($humas);
    expect(d127Ask()->assertOk()->json('badges'))->toHaveKey('humas.publikasi');
});

it('never answers a queue the user may not open', function (): void {
    Competition::factory()->count(2)->create();
    Registration::factory()->count(2)->create();
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();

    $siswa = d127Student();
    $guru = A::staff(RoleName::Guru);
    $admin = A::staff(RoleName::Admin);

    // Siswa, guru and Admin: the bell only. Admin holds the Kesiswaan permissions but its menu shows no count,
    // and it never approves (D-01), so no approval count either.
    foreach ([$siswa, $guru, $admin] as $user) {
        expect(NavigationServiceProvider::liveCounts($user))->toBe([Navigation::BELL => 0]);
    }

    expect(NavigationServiceProvider::liveCounts($guru))->not->toHaveKey('tu.surat')
        ->and(NavigationServiceProvider::liveCounts($admin))->not->toHaveKey('kepsek.persetujuan')
        // D-150: Tata Usaha approves too, so its menu counts the approval queue.
        ->and(NavigationServiceProvider::liveCounts(A::staff(RoleName::TataUsaha)))->toHaveKey('kepsek.persetujuan')
        ->and(NavigationServiceProvider::liveCounts(A::staff(RoleName::KepalaSekolah)))->not->toHaveKey('tu.surat');
});

it('merges the menus of a user with two roles', function (): void {
    Competition::factory()->count(2)->create();
    Registration::factory()->status(RegistrationStatus::Disetujui)->create();

    $user = A::staff(RoleName::Kesiswaan);
    $user->assignRole(RoleName::TataUsaha->value);
    d127Notify($user, 'Satu');

    expect(NavigationServiceProvider::liveCounts($user->fresh()))->toMatchArray([
        Navigation::BELL => 1,
        'kesiswaan.usulan' => 2,
        'kesiswaan.verifikasi' => 0,
        'tu.surat' => 1,
    ]);
});

it('refuses an account that has not finished activation', function (): void {
    $this->actingAs(H::pendingStudent()->user);

    d127Ask()->assertForbidden()->assertHeader('Content-Type', 'application/json');
});

it('refuses staff without a confirmed second step when that is required', function (): void {
    config(['fortify.sth.require_staff_2fa' => true]);
    $this->actingAs(H::staff(RoleName::Kesiswaan));

    d127Ask()->assertForbidden();

    config(['fortify.sth.require_staff_2fa' => false]);

    d127Ask()->assertOk();
});

it('answers 401 and ends the session of a disabled or archived account', function (UserStatus $status): void {
    $user = d127Student();
    $this->actingAs($user);
    $user->forceFill(['status' => $status])->save();

    d127Ask()->assertUnauthorized();

    $this->assertGuest();
})->with([UserStatus::Disabled, UserStatus::Archived]);

it('answers 401 for a frozen account', function (): void {
    $user = d127Student();
    $this->actingAs($user);
    $user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME])->save();

    d127Ask()->assertUnauthorized();

    $this->assertGuest();
});

// What it answers ------------------------------------------------------------------------------------

it('sends a person who opens the address to the Notifikasi page, never raw JSON', function (): void {
    $this->actingAs(d127Student());

    $this->get(route('notifikasi.jumlah'))->assertRedirect(route('notifikasi.index'));

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->{$method}(route('notifikasi.jumlah'))->assertStatus(405);
    }
});

it('is never stored by the browser', function (): void {
    $this->actingAs(d127Student());

    $response = d127Ask()->assertOk();

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeFalse();
});

it('leaves the session as a page left it: no previous address, flashed data kept for the next page', function (): void {
    $user = d127Student();
    $this->actingAs($user);

    $this->get(route('akun.keamanan'))->assertOk();
    expect(session()->previousUrl())->toBe(route('akun.keamanan'));

    d127Ask()->assertOk();
    expect(session()->previousUrl())->toBe(route('akun.keamanan'))
        ->and(session('url.intended'))->toBeNull();

    // What an action flashed for the next page is still there after a check came in between…
    $this->withSession(['toast' => 'Perubahan disimpan.', '_flash' => ['old' => ['toast'], 'new' => []]]);
    d127Ask()->assertOk();
    expect(session('toast'))->toBe('Perubahan disimpan.');

    // …shows on that page, and is gone after it.
    $this->get(route('akun.keamanan'))->assertOk()->assertSee('Perubahan disimpan.');
    $this->get(route('akun.keamanan'))->assertOk()->assertDontSee('Perubahan disimpan.');
});

it('costs a student one COUNT on notifications and no queue query', function (): void {
    $user = d127Student();
    d127Notify($user, 'Satu');
    $this->actingAs($user);
    d127Ask()->assertOk(); // warm the role and permission lookups

    DB::flushQueryLog();
    DB::enableQueryLog();
    d127Ask()->assertOk()->assertExactJson(['badges' => [Navigation::BELL => 1]]);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $q): bool => str_contains($q, '"notifications"')))->toHaveCount(1)
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'count(*)')))->toHaveCount(1)
        ->and($queries->filter(fn (string $q): bool => Str::contains($q, ['registrations', 'competitions', 'reports', 'publications', 'letters'])))->toBeEmpty();
});

// Limits -----------------------------------------------------------------------------------------------

it('has its own limit of 30 checks per minute and the account middleware of the Notifikasi page', function (): void {
    $route = Route::getRoutes()->getByName('notifikasi.jumlah');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('notifikasi/jumlah')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->gatherMiddleware())->toContain('web', 'auth', 'active', 'activated', 'staff.2fa', 'throttle:30,1,lonceng')
        // The page itself keeps its middleware and gets no limit from the check.
        ->and(Route::getRoutes()->getByName('notifikasi.index')->gatherMiddleware())->not->toContain('throttle:30,1,lonceng');
});

it('answers 429 from the 31st check of a minute without touching the pages or the file limit', function (): void {
    $this->actingAs(d127Student());

    for ($i = 0; $i < 30; $i++) {
        d127Ask()->assertOk();
    }

    $limited = d127Ask()->assertStatus(429)->assertHeader('Content-Type', 'application/json');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);

    // Counters of their own (QA5): the page still opens, a file address is not limited by the checks.
    $this->get(route('notifikasi.index'))->assertOk();
    expect($this->get(route('files.show', (string) Str::ulid()))->status())->not->toBe(429);
});

// The page side: where the script finds the address -----------------------------------------------------

it('prints the address and the interval on a signed-in page', function (): void {
    $html = $this->actingAs(d127Student())->get(route('akun.keamanan'))->assertOk()->getContent();

    expect($html)->toContain('data-sth-counts-url="'.route('notifikasi.jumlah').'"')
        ->toContain('data-sth-counts-every="60"')
        ->and(substr_count($html, 'data-sth-counts-url='))->toBe(1);
});

it('prints no address when the background check is switched off', function (int|string|null $seconds): void {
    config(['sth.counts.poll_seconds' => $seconds]);

    $html = $this->actingAs(d127Student())->get(route('akun.keamanan'))->assertOk()->getContent();

    // The bell is still there and still follows the screen's own events; only nothing asks the server.
    expect($html)->not->toContain('data-sth-counts-url')
        ->not->toContain('data-sth-counts-every')
        ->toContain('data-sth-badge="'.Navigation::BELL.'"')
        ->and(NotificationCountController::pollSeconds())->toBe(0)
        ->and(NotificationCountController::pollUrl(auth()->user()))->toBeNull();
})->with([0, -5, '0', null]);

it('never lets a page ask more often than every 30 seconds', function (): void {
    expect(config('sth.counts.poll_seconds'))->toBe(60)
        ->and(NotificationCountController::pollSeconds())->toBe(60);

    config(['sth.counts.poll_seconds' => 5]);
    expect(NotificationCountController::pollSeconds())->toBe(NotificationCountController::MIN_SECONDS)->toBe(30);

    $html = $this->actingAs(d127Student())->get(route('akun.keamanan'))->assertOk()->getContent();
    expect($html)->toContain('data-sth-counts-every="30"');

    config(['sth.counts.poll_seconds' => 300]);
    expect(NotificationCountController::pollSeconds())->toBe(300);
});

it('never lets a page wait longer than an hour between two checks', function (int $seconds): void {
    // A browser timer takes a 32-bit delay in milliseconds: above about 24.8 days it wraps and can fire at once,
    // again and again. "Never" is 0; a number that large is read as an hour.
    config(['sth.counts.poll_seconds' => $seconds]);

    expect(NotificationCountController::pollSeconds())->toBe(NotificationCountController::MAX_SECONDS)->toBe(3600);

    $html = $this->actingAs(d127Student())->get(route('akun.keamanan'))->assertOk()->getContent();
    expect($html)->toContain('data-sth-counts-every="3600"');
})->with([3600, 3601, 3_000_000, PHP_INT_MAX]);

it('prints no address on pages without a bell', function (): void {
    // Guests: login, forgot password, public home.
    foreach ([route('login'), route('password.request'), route('public.home')] as $url) {
        $this->get($url)->assertOk()->assertDontSee('data-sth-counts-url', false)->assertDontSee('data-sth-badge="'.Navigation::BELL.'"', false);
    }

    // The focused phone layout (Siswa › Laporkan hasil) has no top bar.
    expect((string) $this->blade('<x-layouts::mobile title="Laporkan hasil">Isi</x-layouts::mobile>'))->not->toContain('data-sth-counts-url');

    // Activation.
    $this->actingAs(H::pendingStudent()->user)->get(route('aktivasi'))->assertOk()->assertDontSee('data-sth-counts-url', false);
});

it('prints no address on the password and second-step pages', function (): void {
    $this->actingAs(A::staff(RoleName::Kesiswaan));

    $this->get(route('password.confirm'))->assertOk()->assertDontSee('data-sth-counts-url', false);
    $this->withSession(A::passwordConfirmed())->get(route('two-factor.setup'))->assertOk()->assertDontSee('data-sth-counts-url', false);
});

it('prints no address on the second-step challenge of a login', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $staff->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))->assertOk()
        ->assertDontSee('data-sth-counts-url', false)
        ->assertDontSee('data-sth-badge="'.Navigation::BELL.'"', false);
});

it('prints no address for a preview user (dev showcase): the bell is drawn, nothing is asked', function (): void {
    $html = (string) $this->blade(
        '<x-layouts::app title="Komponen" role="kesiswaan" :user="$person" :notifications="2" preview>Isi</x-layouts::app>',
        ['person' => ['name' => 'Ibu Dwi Rahayu', 'meta' => 'Kesiswaan']],
    );

    expect($html)->not->toContain('data-sth-counts-url')
        ->toContain('data-sth-badge="'.Navigation::BELL.'"')
        ->toContain('aria-label="Notifikasi, 2 belum dibaca"');
});

// The script: this repo has no JavaScript test runner, so this only keeps the block and its rules from being
// dropped unnoticed. How the block behaves is checked in a browser (steps in the dev-note of this change).

it('keeps the live counts block of app.js and its rules', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)
        // hooks
        ->toContain('data-sth-counts-url')
        ->toContain('data-sth-counts-every')
        ->toContain('data-sth-badge-max')
        ->toContain('data-sth-badge-label')
        ->toContain('data-sth-refresh-on')
        ->toContain("'".Navigation::BELL."'")
        // the request: both headers, same origin, never cached, never followed to another page
        ->toContain("'X-Requested-With': 'XMLHttpRequest'")
        ->toContain("Accept: 'application/json'")
        ->toContain("redirect: 'error'")
        ->toContain("cache: 'no-store'")
        // only a visible tab asks, a new notification is announced, not toasted
        ->toContain('visibilityState')
        ->toContain("announce('Ada notifikasi baru.')")
        ->not->toContain("showToast('Ada notifikasi baru.");

    // The block itself: one timer chain, never an interval, and it sends nothing but a GET.
    expect(preg_match('#/\* -+ live counts \(bell \+ sidebar\) \*/(.*?)/\* -+ [a-z]#s', $script, $block))->toBe(1)
        ->and($block[1])->not->toContain('setInterval')
        ->and($block[1])->toContain("method: 'GET'")
        ->and($block[1])->not->toContain("method: 'POST'")
        ->and($block[1])->toContain('Retry-After')
        ->and($block[1])->toContain('pagehide')
        ->and($block[1])->toContain('livewire:navigate');
});

it('keeps the limits of the script in step with the server and holds, not stops, on an outage', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect(preg_match('#/\* -+ live counts \(bell \+ sidebar\) \*/(.*?)/\* -+ [a-z]#s', $script, $block))->toBe(1);

    // The script clamps the interval a second time, with the numbers of pollSeconds().
    expect(preg_match('/const COUNTS_MIN_EVERY = (\d+);/', $block[1], $min))->toBe(1)
        ->and((int) $min[1])->toBe(NotificationCountController::MIN_SECONDS)
        ->and(preg_match('/const COUNTS_MAX_EVERY = (\d+);/', $block[1], $max))->toBe(1)
        ->and((int) $max[1])->toBe(NotificationCountController::MAX_SECONDS)
        ->and($block[1])->toContain('Math.min(COUNTS_MAX_EVERY');

    // A server that cannot answer now (503 while a deploy runs `artisan down`) is waited for like a 429. Only an
    // answer that waiting cannot change (401 / 403) counts towards the two that end the checks of a page.
    expect($block[1])->toContain('response.status === 429 || response.status >= 500');
});
