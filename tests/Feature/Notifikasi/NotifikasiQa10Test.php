<?php

declare(strict_types=1);

use App\Livewire\Akun\Notifikasi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 * QA10: marking the only unread notification of the last page of "Belum dibaca" as read left an empty page that said
 * "Tidak ada notifikasi baru" without a pager. The screen now shows the last page that still has rows. Fictional data.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

/** Inserts an unread database notification; "Kabar 1" is the newest. */
function qa10RegNotify(User $user, int $n): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Verification\\Notice',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['kind' => 'test', 'title' => 'Kabar '.$n, 'message' => 'Isi kabar '.$n, 'url' => null]),
        'read_at' => null,
        'created_at' => now()->subMinutes($n),
        'updated_at' => now()->subMinutes($n),
    ]);

    return $id;
}

it('shows the last page with rows after the only unread one of the last page was marked read (QA10)', function (): void {
    $user = User::query()->findOrFail(H::activeStudent()->user_id);
    $ids = [];

    foreach (range(1, Notifikasi::PER_PAGE + 1) as $n) {
        $ids[$n] = qa10RegNotify($user, $n);
    }

    $last = Notifikasi::PER_PAGE + 1;

    Livewire::actingAs($user)->test(Notifikasi::class)
        ->call('setFilter', 'belum')
        ->call('gotoPage', 2)
        ->assertSee('Kabar '.$last)
        ->call('markRead', $ids[$last])
        ->assertDontSee('Tidak ada notifikasi baru')
        ->assertSee('Kabar '.Notifikasi::PER_PAGE);
});
