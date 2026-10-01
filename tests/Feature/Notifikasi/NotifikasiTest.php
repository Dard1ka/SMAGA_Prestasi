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

    Livewire::test(Notifikasi::class)->call('markAllRead')->assertRedirect(route('notifikasi.index'));
    expect($user->unreadNotifications()->count())->toBe(0);
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
