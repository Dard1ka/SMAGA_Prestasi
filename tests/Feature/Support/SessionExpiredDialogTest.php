<?php

declare(strict_types=1);

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Support\TalentGate;

uses(RefreshDatabase::class);

/*
 | D-170 (lead, 2026-10-30): when a Livewire request answers 419 (session over, page open too long, or with
 | APP_DEBUG=false a stale snapshot), the page shows the app's own dialog "Sesi sudah berakhir" with "Muat ulang"
 | instead of Livewire's native English confirm "This page has expired…". The dialog lives in <x-sth.flash>, which
 | every layout renders once; app.js opens it.
 */

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
});

it('renders the dialog in every layout that loads Livewire', function (): void {
    $layouts = glob(resource_path('views/layouts/*.blade.php')) ?: [];
    $loading = [];
    $missing = [];

    foreach ($layouts as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, '@livewireScripts')) {
            continue;
        }

        $loading[] = basename($path);

        // <x-sth.flash /> carries the dialog #sth-sesi-habis.
        if (! str_contains($source, '<x-sth.flash')) {
            $missing[] = basename($path);
        }
    }

    expect(count($loading))->toBeGreaterThanOrEqual(4)
        ->and($missing)->toBe([]);
});

it('puts the dialog with a reload button on the pages', function (string $routeName, ?string $role): void {
    if ($role === 'siswa') {
        $student = F::student('Nadia Putri Anindya');
        TalentGate::pass($student); // D-153
        $this->actingAs($student->user);
    } elseif ($role !== null) {
        $this->actingAs(F::staff(RoleName::from($role)))->withSession(['auth.password_confirmed_at' => time()]);
    }

    $html = (string) $this->get(route($routeName))->assertOk()->getContent();

    expect(substr_count($html, 'id="sth-sesi-habis"'))->toBe(1)
        ->and($html)->toContain('Sesi sudah berakhir')
        ->and($html)->toContain('data-sth-reload')
        ->and($html)->not->toContain('This page has expired');
})->with([
    'publik peringkat (public layout)' => ['public.peringkat', null],
    'login (auth layout)' => ['login', null],
    'kesiswaan status aktivasi (app layout)' => ['kesiswaan.aktivasi.index', 'kesiswaan'],
    'siswa beranda (app layout)' => ['siswa.beranda', 'siswa'],
]);

it('lets app.js answer a 419 with that dialog instead of the native confirm', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    // Read the one function that handles it, so a preventDefault() or a 419 check elsewhere in the file cannot keep this
    // green after the handler lost them (the review of SV1 found the whole-file search could not fail).
    $start = strpos($script, 'function watchExpiredPage()');
    $end = $start === false ? false : strpos($script, "\n}\n", $start);
    $handler = $start === false || $end === false ? '' : substr($script, $start, $end - $start);

    expect($handler)->not->toBe('')
        ->and($handler)->toContain('window.Livewire.interceptRequest')
        ->and($handler)->toContain('onError(')
        ->and($handler)->toContain('response?.status !== 419')
        ->and($handler)->toContain('preventDefault();')
        ->and($handler)->toContain("openDialog('sth-sesi-habis')")
        ->and($handler)->toContain('window.sth.expiryDismissed')
        ->and($script)->toContain("watchExpiredPage();\ndocument.addEventListener('livewire:init', watchExpiredPage);")
        ->and($script)->toContain("target.closest('[data-sth-reload]')")
        ->and($script)->toContain('window.location.reload()');
});

it('copies "Data untuk flyer" on plain http too: the copy command after the Clipboard API is missing (SV1)', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)->toContain("document.execCommand('copy')")
        ->and($script)->toContain("copied ? 'Data disalin.' : 'Tekan Ctrl+C untuk menyalin.'");
});
