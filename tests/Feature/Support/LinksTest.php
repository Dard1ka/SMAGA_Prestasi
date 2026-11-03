<?php

declare(strict_types=1);

use App\Support\Links;
use Illuminate\Http\Request;

// Links::navigable decides which <a> may carry wire:navigate. Getting it wrong breaks downloads
// (Livewire would swap a PDF/XLSX body into the page), so every excluded shape is pinned here.

beforeEach(function (): void {
    config(['app.url' => 'http://sth.test']);
});

it('navigates internal app paths', function (string $href) {
    expect(Links::navigable($href))->toBeTrue();
})->with(['/', '/prestasi', '/peringkat', '/siswa/beranda', 'prestasi', '/prestasi?bidang=riset', '/berkasku']);

it('navigates absolute urls on the app host', function () {
    expect(Links::navigable('http://sth.test/prestasi'))->toBeTrue();
});

it('navigates absolute urls on the host actually being served', function () {
    // Production is served from a domain, APP_URL may be configured differently; both count as "this app".
    app()->instance('request', Request::create('https://prestasi.sman3-smg.sch.id/peringkat'));

    expect(Links::navigable('https://prestasi.sman3-smg.sch.id/prestasi'))->toBeTrue()
        ->and(Links::navigable('https://prestasi.sman3-smg.sch.id/berkas/01HQ'))->toBeFalse();
});

it('never navigates file responses from FileController', function (string $href) {
    expect(Links::navigable($href))->toBeFalse();
})->with(['/berkas/01HQ', '/berkas/publik/01HQ', 'http://sth.test/berkas/01HQ', '/berkas']);

it('never navigates off-site urls', function (string $href) {
    expect(Links::navigable($href))->toBeFalse();
})->with(['https://sman3-smg.sch.id', 'http://evil.example/prestasi', '//evil.example/x']);

it('never navigates non-page hrefs', function (?string $href) {
    expect(Links::navigable($href))->toBeFalse();
})->with(['#', '', '   ', 'mailto:a@b.c', 'tel:+62', 'javascript:void(0)', 'data:text/html,x', null]);

it('does not prefetch the rate-limited public pages', function (string $href) {
    expect(Links::navigable($href))->toBeTrue()
        ->and(Links::prefetchable($href))->toBeFalse();
})->with(['/', '/prestasi', '/prestasi/siswa/abc', '/peringkat', '/verifikasi-surat/XY', 'http://sth.test/']);

it('prefetches in-app pages that are not rate limited', function (string $href) {
    expect(Links::prefetchable($href))->toBeTrue();
})->with(['/siswa/beranda', '/kesiswaan/usulan', '/notifikasi', '/prestasiku', '/peringkatku']);

it('never prefetches what it would not navigate', function (string $href) {
    expect(Links::prefetchable($href))->toBeFalse();
})->with(['/berkas/01HQ', 'https://sman3-smg.sch.id', '#', 'mailto:a@b.c']);
