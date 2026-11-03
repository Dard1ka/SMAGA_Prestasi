<?php

declare(strict_types=1);

use App\Livewire\Admin\AuditLog;
use App\Livewire\Akun\StatusAktivasi;
use App\Livewire\Kepsek\Laporan;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\Talenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | QA retest 2026-10-06 (backlog #63, QA Q2-18): a download that asks for the password again used to land back
 | on its screen with nothing happening, so the user had to find the button and click it a second time. Now the
 | download runs by itself once, right after the confirmation. Only downloads do this: an action that changes
 | data is never started without a click. The password page also has a "Batal" back to the screen.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->withoutVite();
    $this->admin = A::admin();
});

dataset('downloads behind the password', [
    'talenta siswa' => [Talenta::class, ['export'], 'export'],
    'database prestasi' => [DatabasePrestasi::class, ['export'], 'export'],
    'log audit' => [AuditLog::class, ['export'], 'export'],
    'status aktivasi' => [StatusAktivasi::class, ['export'], 'export'],
    'laporan kepala sekolah' => [Laporan::class, ['export', 'xlsx'], 'export(&#039;xlsx&#039;)'],
]);

it('starts the download by itself once the password is confirmed, and only once', function (string $component, array $call, string $init): void {
    Livewire::actingAs($this->admin)->test($component)
        ->call(...$call)
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    session()->put(A::passwordConfirmed()); // what Fortify writes after /user/confirm-password

    Livewire::actingAs($this->admin)->test($component)->assertSeeHtml('wire:init="'.$init.'"');

    // Coming back to the screen later (or a reload) does not download again.
    Livewire::actingAs($this->admin)->test($component)->assertDontSeeHtml('wire:init=');
})->with('downloads behind the password');

it('does not start the download when the password was not confirmed', function (): void {
    Livewire::actingAs($this->admin)->test(Talenta::class)->call('export')->assertRedirect(route('password.confirm'));

    // "Batal" on the password page: back on the screen without a confirmation.
    Livewire::actingAs($this->admin)->test(Talenta::class)->assertDontSeeHtml('wire:init=');
});

it('does not start the download on another screen', function (): void {
    Livewire::actingAs($this->admin)->test(Talenta::class)->call('export')->assertRedirect(route('password.confirm'));
    session()->put(A::passwordConfirmed());

    Livewire::actingAs($this->admin)->test(DatabasePrestasi::class)->assertDontSeeHtml('wire:init=');
});

it('never starts an action that changes data after the confirmation', function (): void {
    Livewire::actingAs($this->admin)->test(StatusAktivasi::class)
        ->set('windowDays', '20')
        ->call('saveWindow')
        ->assertRedirect(route('password.confirm'));
    session()->put(A::passwordConfirmed());

    Livewire::actingAs($this->admin)->test(StatusAktivasi::class)->assertDontSeeHtml('wire:init=');
});

it('offers "Batal" on the password page, back to the screen that asked', function (): void {
    $this->actingAs($this->admin)
        ->withSession(['url.intended' => route('kesiswaan.talenta.index', ['tahap' => 'seleksi'])])
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Batal')
        ->assertSee('href="'.e(route('kesiswaan.talenta.index', ['tahap' => 'seleksi'])).'"', false);
});

it('sends "Batal" to the beranda when the page that asked is unknown or not ours', function (?string $intended): void {
    $this->actingAs($this->admin)
        ->withSession(array_filter(['url.intended' => $intended]))
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('href="'.e(route('home')).'"', false)
        ->assertDontSee('evil.test');
})->with(['none' => [null], 'another site' => ['https://evil.test/kesiswaan/talenta']]);

it('sends "Batal" back to the page the user came from when the page that asked is itself behind the password', function (string $route): void {
    $this->actingAs($this->admin)
        ->withSession(['url.intended' => route($route)])
        ->withHeader('Referer', route('admin.ringkasan'))
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('href="'.e(route('admin.ringkasan')).'"', false)
        ->assertDontSee('href="'.e(route($route)).'"', false);
})->with(['admin.pengguna.index', 'admin.pengaturan', 'two-factor.setup']);

it('sends "Batal" to the beranda when such a page was opened without a page before it', function (): void {
    $this->actingAs($this->admin)
        ->withSession(['url.intended' => route('admin.pengguna.index')])
        ->withHeader('Referer', route('admin.pengguna.index'))
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('href="'.e(route('home')).'"', false);
});
