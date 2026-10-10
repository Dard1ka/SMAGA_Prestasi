<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Akun\Notifikasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| B1 — Notifikasi (notifikasi.index) + top-bar bell: own database notifications only, unread count,
| mark read / all read, open the target (internal URLs only). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

/** Inserts a database notification row for the user and returns its id. */
function b1Notify(User $user, string $title, ?string $url = null, bool $read = false, array $extra = []): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Verification\\Notice',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(array_merge(['kind' => 'test', 'title' => $title, 'message' => 'Isi '.$title, 'url' => $url], $extra)),
        'read_at' => $read ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function b1ActiveStudent(): User
{
    return User::query()->findOrFail(H::activeStudent()->user_id);
}

it('opens for every role and sends guests to the login', function (): void {
    $this->get(route('notifikasi.index'))->assertRedirect(route('login'));

    $this->actingAs(b1ActiveStudent())->get(route('notifikasi.index'))->assertOk()->assertSee('Belum ada notifikasi');

    foreach (RoleName::staff() as $role) {
        $this->actingAs(A::staff($role))->get(route('notifikasi.index'))->assertOk();
    }
});

it('lists only the own notifications and shows the unread count on the bell', function (): void {
    $user = b1ActiveStudent();
    $other = A::staff(RoleName::Guru);
    b1Notify($user, 'Surat tugas terbit', url('/siswa/lomba-saya'));
    b1Notify($user, 'Laporan diverifikasi');
    b1Notify($user, 'Sudah lama', read: true);
    b1Notify($other, 'Rahasia guru');

    $this->actingAs($user)->get(route('notifikasi.index'))
        ->assertOk()
        ->assertSee('Surat tugas terbit')
        ->assertSee('Isi Laporan diverifikasi')
        ->assertDontSee('Rahasia guru')
        ->assertSee('aria-label="Notifikasi, 2 belum dibaca"', false)
        ->assertSee('href="'.route('notifikasi.index').'"', false);
});

it('filters unread, marks one and all as read', function (): void {
    $user = b1ActiveStudent();
    $first = b1Notify($user, 'Pertama');
    b1Notify($user, 'Kedua');
    b1Notify($user, 'Lama', read: true);
    $this->actingAs($user);

    Livewire::test(Notifikasi::class)
        ->call('setFilter', 'belum')
        ->assertSee('Pertama')->assertDontSee('Lama')
        ->call('markRead', $first)
        ->assertDontSee('Pertama');

    expect($user->unreadNotifications()->count())->toBe(1);

    // D-127: no page reload any more; the bell gets its number through `sth-badges` (see the tests below).
    Livewire::test(Notifikasi::class)->call('markAllRead')
        ->assertNoRedirect()
        ->assertDispatched('sth-toast', message: 'Semua notifikasi ditandai sudah dibaca.', tone: 'success');
    expect($user->unreadNotifications()->count())->toBe(0);
});

// D-127 (lead, 2026-10-10): the bell follows the state without a page load -----------------------------

/** The bell of a rendered page: [aria-label of the button, the bubble's attributes, the bubble's text]. */
function d127Bell(string $html): array
{
    expect(preg_match('/aria-label="(Notifikasi[^"]*)"/', $html, $label))->toBe(1)
        ->and(preg_match('/<span([^>]*data-sth-badge="akun\.notifikasi"[^>]*)>\s*([^<]*?)\s*<\/span>/', $html, $bubble))->toBe(1);

    return [$label[1], $bubble[1], $bubble[2]];
}

it('always prints the bell bubble, hidden at zero, with the hooks the script updates', function (): void {
    $user = b1ActiveStudent();

    $html = $this->actingAs($user)->get(route('notifikasi.index'))->assertOk()->getContent();
    [$label, $attributes, $text] = d127Bell($html);

    expect($label)->toBe('Notifikasi')
        ->and($attributes)->toContain(' hidden')->toContain('data-sth-badge-max="99"')->toContain('data-sth-count="0"')->toContain('aria-hidden="true"')
        ->and($text)->toBe('0')
        // One bell per page; its button carries the two label texts the script switches between.
        ->and(substr_count($html, 'data-sth-badge="akun.notifikasi"'))->toBe(1)
        ->and(substr_count($html, 'data-sth-badge-label="akun.notifikasi"'))->toBe(1)
        ->and($html)->toContain('data-label-none="Notifikasi"')
        ->toContain('data-label-some="Notifikasi, :n belum dibaca"');

    b1Notify($user, 'Satu');
    b1Notify($user, 'Dua');

    [$label, $attributes, $text] = d127Bell($this->get(route('akun.keamanan'))->assertOk()->getContent());

    expect($label)->toBe('Notifikasi, 2 belum dibaca')
        ->and($attributes)->not->toContain(' hidden')->toContain('data-sth-count="2"')
        ->and($text)->toBe('2');
});

it('shows 99+ on the bell above 99 unread and keeps the real number for the script', function (): void {
    $user = b1ActiveStudent();

    foreach (range(1, 120) as $n) {
        b1Notify($user, 'Kabar '.$n);
    }

    [$label, $attributes, $text] = d127Bell($this->actingAs($user)->get(route('akun.keamanan'))->assertOk()->getContent());

    expect($text)->toBe('99+')
        ->and($attributes)->toContain('data-sth-count="120"')
        ->and($label)->toBe('Notifikasi, 120 belum dibaca');
});

it('tells the bell the unread count on every render: first load, mark one, mark all, filter', function (): void {
    $user = b1ActiveStudent();
    $first = b1Notify($user, 'Pertama');
    b1Notify($user, 'Kedua');
    b1Notify($user, 'Lama', read: true);
    b1Notify(A::staff(RoleName::Guru), 'Milik guru');
    $this->actingAs($user);

    Livewire::test(Notifikasi::class)
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 2])
        ->call('markRead', $first)
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 1])
        ->assertNoRedirect()
        ->call('setFilter', 'belum')
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 1])
        ->call('markAllRead')
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 0])
        ->assertSee('Tidak ada notifikasi baru')
        ->assertDontSee('Tandai semua dibaca');

    // Another user's rows are untouched.
    expect(DB::table('notifications')->whereNull('read_at')->count())->toBe(1);
});

it('marks all read from a later page of "Belum dibaca" and ends on an empty first page', function (): void {
    $user = b1ActiveStudent();

    foreach (range(1, Notifikasi::PER_PAGE + 3) as $n) {
        b1Notify($user, 'Kabar '.$n);
    }

    Livewire::actingAs($user)->test(Notifikasi::class)
        ->call('setFilter', 'belum')
        ->call('gotoPage', 2)
        ->call('markAllRead')
        ->assertOk()
        ->assertNoRedirect()
        ->assertSee('Tidak ada notifikasi baru')
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 0]);

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('shows a notification that arrived while the screen was open after a refresh', function (): void {
    $user = b1ActiveStudent();
    $this->actingAs($user);

    $screen = Livewire::test(Notifikasi::class)
        ->assertSee('Belum ada notifikasi')
        // The script refreshes every component that carries this hook when a background check sees the bell change.
        ->assertSeeHtml('data-sth-refresh-on="akun.notifikasi"');

    b1Notify($user, 'Surat tugas terkirim');

    $screen->call('$refresh')
        ->assertSee('Surat tugas terkirim')
        ->assertDispatched('sth-badges', badges: ['akun.notifikasi' => 1]);
});

it('opens an internal target and marks it read; external targets are never followed', function (): void {
    $user = b1ActiveStudent();
    $internal = b1Notify($user, 'Internal', url('/siswa/lomba-saya?tab=1'));
    $external = b1Notify($user, 'Eksternal', 'https://evil.example/phish');
    $this->actingAs($user);

    Livewire::test(Notifikasi::class)->call('open', $internal)->assertRedirect('/siswa/lomba-saya?tab=1');
    Livewire::test(Notifikasi::class)->call('open', $external)->assertRedirect(route('notifikasi.index'));

    expect($user->unreadNotifications()->count())->toBe(0);
});

it("returns 404 for another user's notification", function (): void {
    $user = b1ActiveStudent();
    $foreign = b1Notify(A::staff(RoleName::Guru), 'Bukan milikmu');
    $this->actingAs($user);

    Livewire::test(Notifikasi::class)->call('markRead', $foreign)->assertNotFound();
    Livewire::test(Notifikasi::class)->call('open', $foreign)->assertNotFound();
    expect(DB::table('notifications')->where('id', $foreign)->value('read_at'))->toBeNull();
});

it('accepts only same-host or relative targets', function (mixed $url, ?string $expected): void {
    $url = $url === 'APP_URL' ? url('/kesiswaan/verifikasi-hasil?x=1') : $url;

    expect(Notifikasi::internalTarget($url))->toBe($expected);
})->with([
    'app url' => ['APP_URL', '/kesiswaan/verifikasi-hasil?x=1'],
    'relative' => ['/siswa/lomba-saya', '/siswa/lomba-saya'],
    'other host' => ['https://evil.example/siswa', null],
    'protocol relative' => ['//evil.example/siswa', null],
    'javascript' => ['javascript:alert(1)', null],
    'credentials' => ['https://user:pass@localhost/x', null],
    'backslash' => ['/\\evil.example', null],
    'not a string' => [['/x'], null],
    'empty' => ['', null],
]);
