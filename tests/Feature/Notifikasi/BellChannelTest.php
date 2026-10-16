<?php

declare(strict_types=1);

use App\Livewire\Akun\Notifikasi;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Channels\BellChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| RV1: a bell row keeps the PATH of its target, not the absolute address (App\Notifications\Channels\BellChannel).
| Found when the lead's demo ran behind a tunnel whose address changes at every start: every notification stored
| "https://<old address>/…", and the screen only offers "Buka" for an address of the host it runs on, so older rows
| lost their button without a word. The mail keeps the absolute address; a bell row is opened inside the app.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
});

it('cuts an address of this application down to its path and leaves every other address alone', function (string $url, string $expected): void {
    $url = str_replace('ROOT', rtrim(url('/'), '/'), $url);
    $expected = str_replace('ROOT', rtrim(url('/'), '/'), $expected);

    expect(BellChannel::relative($url))->toBe($expected);
})->with([
    'a page' => ['ROOT/siswa/lomba-saya', '/siswa/lomba-saya'],
    'a page with a query' => ['ROOT/kesiswaan/verifikasi-hasil?x=1&y=2', '/kesiswaan/verifikasi-hasil?x=1&y=2'],
    'the root itself' => ['ROOT', '/'],
    'the root with a slash' => ['ROOT/', '/'],
    'the root with a query' => ['ROOT?tab=1', '/?tab=1'],
    'already a path' => ['/siswa/lomba-saya', '/siswa/lomba-saya'],
    'another site' => ['https://evil.example/siswa', 'https://evil.example/siswa'],
    // Shares its first letters with the root, but is another host.
    'a host that only starts like ours' => ['ROOT.evil.example/siswa', 'ROOT.evil.example/siswa'],
    'a port that only starts like ours' => ['ROOT8/siswa', 'ROOT8/siswa'],
]);

it('is the database channel of the application', function (): void {
    expect(app(DatabaseChannel::class))->toBeInstanceOf(BellChannel::class);
});

it('stores the path in the bell row of a real notification, and the mail keeps the absolute address', function (): void {
    $mentor = F::mentor();
    $registration = F::registration(names: ['Nadia Putri Anindya'], mentor: $mentor);
    $student = User::query()->findOrFail($registration->leader->user_id);

    $notification = RegistrationApproved::for($registration, 'siswa');
    $student->notify($notification);
    $mentor->notify(RegistrationApproved::for($registration, 'guru'));

    $stored = fn (User $user): string => (string) json_decode((string) DB::table('notifications')
        ->where('notifiable_id', $user->getKey())->value('data'), true)['url'];

    expect($stored($student))->toBe('/siswa/lomba-saya/'.$registration->getKey())
        ->and($stored($mentor))->toBe('/guru/binaan/'.$registration->getKey())
        // The mail is read outside the app: it needs the whole address.
        ->and($notification->toMail($student)->actionUrl)->toBe(route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]))
        ->and($notification->toMail($student)->actionUrl)->toStartWith('http');
});

it('still opens a stored row after the application moved to another address', function (): void {
    $mentor = F::mentor();
    $registration = F::registration(names: ['Nadia Putri Anindya'], mentor: $mentor);

    // The notice is written while the app answers under its first address.
    config(['app.url' => 'https://alamat-lama.example']);
    URL::forceRootUrl('https://alamat-lama.example');
    $oldAbsolute = route('guru.binaan.show', ['registration' => $registration->getKey()]);

    $mentor->notify(RegistrationApproved::for($registration, 'guru'));
    $row = DB::table('notifications')->where('notifiable_id', $mentor->getKey())->first();
    $stored = (string) json_decode((string) $row->data, true)['url'];

    // The demo restarts under a new tunnel address (or the database is opened on a second machine).
    config(['app.url' => 'https://alamat-baru.example']);
    URL::forceRootUrl('https://alamat-baru.example');

    // What every row stored before this change looked like: the old absolute address is refused, the button is gone.
    expect($oldAbsolute)->toContain('://alamat-lama.example/guru/binaan/')
        ->and(Notifikasi::internalTarget($oldAbsolute))->toBeNull()
        ->and($stored)->toBe('/guru/binaan/'.$registration->getKey())
        ->and(Notifikasi::internalTarget($stored))->toBe('/guru/binaan/'.$registration->getKey());

    Livewire::actingAs($mentor)->test(Notifikasi::class)
        ->assertViewHas('items', fn ($items): bool => $items->firstWhere('id', $row->id)['hasTarget'] === true)
        ->call('open', $row->id)
        ->assertRedirect('/guru/binaan/'.$registration->getKey());
});
