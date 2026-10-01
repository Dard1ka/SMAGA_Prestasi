<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\SettingKey;
use App\Http\Middleware\PublicPageHeaders;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    $f = new PublikFixtures;
    $this->student = $f->student('Nadia Putri Anindya', 'XI-4');
    $this->lomba = $f->lomba('OPSI 2026', Bidang::Riset, Level::Nasional, '2026-09-20');
    $f->prestasi($this->student, $this->lomba, ResultType::Juara2);
    $this->slug = $f->slug($this->student);
});

it('sends noindex on every public page by default', function (): void {
    foreach ([route('public.home'), route('public.prestasi'), route('public.peringkat'), route('public.lomba.pemenang', $this->lomba->id), route('public.prestasi.siswa', $this->slug)] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
});

it('lets Admin allow indexing of the aggregate pages but never of student pages', function (): void {
    Setting::put(SettingKey::PublicIndexing, true);

    foreach ([route('public.home'), route('public.prestasi'), route('public.peringkat'), route('public.lomba.pemenang', $this->lomba->id)] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->get(route('public.prestasi', ['siswa' => $this->slug]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('protects every public route with throttle 60/min and the public headers middleware', function (string $name): void {
    $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

    expect($middleware)->toContain('throttle:60,1')
        ->and($middleware)->toContain(PublicPageHeaders::class)
        ->and($middleware)->not->toContain('auth');
})->with(['public.home', 'public.prestasi', 'public.prestasi.siswa', 'public.lomba.pemenang', 'public.peringkat']);

it('uses the agreed URLs', function (): void {
    expect(route('public.home', absolute: false))->toBe('/')
        ->and(route('public.prestasi', absolute: false))->toBe('/prestasi')
        ->and(route('public.peringkat', absolute: false))->toBe('/peringkat')
        ->and(route('public.prestasi.siswa', $this->slug, false))->toBe('/prestasi/siswa/'.$this->slug)
        ->and(route('public.lomba.pemenang', $this->lomba->id, false))->toBe('/prestasi/lomba/'.$this->lomba->id);
});
