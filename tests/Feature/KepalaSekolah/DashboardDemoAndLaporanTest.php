<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Laporan;
use App\Livewire\Sekolah\Dashboard;
use App\Models\File;
use App\Support\DemoData;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * SV1 (browser check of 2026-10-30) on the dashboard of the Kepala Sekolah and Admin:
 * - the orange chip "Data contoh" stood on every APP_ENV=local instance, also on the lead's servers with real data
 *   (D-168). It now needs the local demo data set itself: a file row of the demo seed (DemoFiles, folder demo/).
 * - "Download laporan" opened the report of the current school year whatever year the dashboard showed.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah);
});

it('keeps the demo folder of the app equal to the folder the demo seed writes', function (): void {
    expect(DemoData::DIRECTORY)->toBe(DemoFiles::DIRECTORY)
        ->and(DemoFiles::pdf('juknis-contoh.pdf', FilePurpose::Juknis)->path)->toStartWith(DemoData::DIRECTORY.'/');
});

it('shows "Data contoh" on a local instance that holds the demo data set (SV1)', function (): void {
    app()->detectEnvironment(fn () => 'local');
    DemoFiles::pdf('sertifikat-contoh.pdf', FilePurpose::ReportEvidence);

    expect(DemoData::present())->toBeTrue();
    Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertViewHas('demo', true)
        ->assertSee('Data contoh');
});

it('hides "Data contoh" on a local instance without the demo data set (SV1, D-168)', function (): void {
    app()->detectEnvironment(fn () => 'local');
    // Real uploads, letters and the signature image live in folders of their own.
    File::factory()->create();
    File::factory()->create(['path' => 'ttd/ttd-stempel-contoh.png', 'mime' => 'image/png']);
    // Another disk with a demo-like path is not the demo seed either.
    File::factory()->create(['disk' => 'public', 'path' => DemoData::DIRECTORY.'/bukan-demo.pdf']);

    expect(DemoData::present())->toBeFalse();
    Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertViewHas('demo', false)
        ->assertDontSee('Data contoh');
});

it('never shows "Data contoh" outside APP_ENV=local, even with demo files', function (string $environment): void {
    DemoFiles::pdf('sertifikat-contoh.pdf', FilePurpose::ReportEvidence);
    app()->detectEnvironment(fn () => $environment);

    expect(DemoData::present())->toBeFalse();
    Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertViewHas('demo', false)
        ->assertDontSee('Data contoh');
})->with(['testing', 'production', 'staging']);

it('downloads the report of the year the dashboard shows, and of all years for "Semua tahun" (SV1)', function (): void {
    $component = Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertSet('tahun', '');

    // "Semua tahun" here; the report reads an empty year as the current one, so the link names "semua".
    expect($component->viewData('laporanUrl'))->toBe(route('kepsek.laporan', ['tahun' => 'semua']));
    $component->assertSeeHtml('href="'.e(route('kepsek.laporan', ['tahun' => 'semua'])).'"');

    $component->set('tahun', '2025/2026');
    expect($component->viewData('laporanUrl'))->toBe(route('kepsek.laporan', ['tahun' => '2025/2026']));
    $component->assertSeeHtml('href="'.e(route('kepsek.laporan', ['tahun' => '2025/2026'])).'"');

    // A year the select refuses falls back to "Semua tahun", and so does the link.
    $component->set('tahun', 'bukan-tahun')->assertSet('tahun', '');
    expect($component->viewData('laporanUrl'))->toBe(route('kepsek.laporan', ['tahun' => 'semua']));
});

it('opens the report on the period the dashboard link names', function (): void {
    Livewire::withQueryParams(['tahun' => '2025/2026'])->actingAs($this->kepsek)->test(Laporan::class)
        ->assertSet('tahun', '2025/2026')
        ->assertSee('Tahun ajaran 2025/2026');

    Livewire::withQueryParams(['tahun' => 'semua'])->actingAs($this->kepsek)->test(Laporan::class)
        ->assertSet('tahun', 'semua')
        ->assertSee('Semua tahun');

    $this->actingAs($this->kepsek)->get(route('kepsek.laporan', ['tahun' => '2025/2026']))
        ->assertOk()
        ->assertSee('Tahun ajaran 2025/2026');
});

it('gives the Admin dashboard the same link', function (): void {
    $admin = F::staff(RoleName::Admin);

    $component = Livewire::actingAs($admin)->test(Dashboard::class)->set('tahun', '2024/2025');

    expect($component->viewData('laporanUrl'))->toBe(route('kepsek.laporan', ['tahun' => '2024/2025']));
    $this->actingAs($admin)->get(route('kepsek.laporan', ['tahun' => '2024/2025']))->assertOk()->assertSee('Tahun ajaran 2024/2025');
});
