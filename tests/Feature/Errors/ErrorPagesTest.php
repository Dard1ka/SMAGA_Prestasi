<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
| M2-C (QA Q1-07, Q2-30, Q2-03): error pages in the app's look. Laravel's own views carry an inline <style>, which
| the CSP blocks, and print the exception message (spatie's English "User does not have the right roles.").
| resources/views/errors/*.blade.php replace them: Indonesian, lang="id", "Kembali ke beranda", no exception text,
| no query, also with APP_DEBUG=true (405 included, so a wrong-method request stays small).
*/

const M2C_SECRET = 'Secret exception text';

beforeEach(function (): void {
    H::seedRoles();
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();

    // One address per status; the message stands for any package or framework text that must not reach the page.
    Route::middleware('web')->get('/_galat/{code}', fn (string $code) => abort((int) $code, M2C_SECRET))->whereNumber('code');
    Route::middleware('web')->get('/_galat-server', fn () => throw new RuntimeException(M2C_SECRET));
});

dataset('error pages', [
    '401' => [401, 'Belum masuk', 'Masuk dulu untuk membuka halaman ini.'],
    '403' => [403, 'Akses ditolak', 'Tidak ada akses ke halaman ini.'],
    '404' => [404, 'Tidak ditemukan', 'Halaman tidak ditemukan.'],
    '405' => [405, 'Tidak bisa dibuka', 'Alamat ini tidak bisa dibuka langsung.'],
    '419' => [419, 'Kedaluwarsa', 'Halaman kedaluwarsa. Muat ulang lalu coba lagi.'],
    '429' => [429, 'Coba lagi nanti', 'Terlalu banyak permintaan. Tunggu sebentar.'],
    '500' => [500, 'Ada gangguan', 'Terjadi kesalahan di server.'],
    '503' => [503, 'Sedang dirawat', 'Aplikasi sedang dirawat.'],
    'other 4xx' => [418, 'Tidak bisa dibuka', 'Permintaan ini tidak bisa diproses.'],
    'other 5xx' => [502, 'Ada gangguan', 'Terjadi kesalahan di server.'],
]);

/** What every error page must look like, whatever the status. */
function m2cAssertErrorPage(TestResponse $response, int $status, string $title, string $sentence, string $home): void
{
    $response->assertStatus($status)
        ->assertSee('<html lang="id">', false)
        ->assertSee('SMAGA Talent Hub')
        ->assertSee('Kode '.$status)
        ->assertSee('<h1 class="title-page">'.$title.'</h1>', false)
        ->assertSee($sentence)
        ->assertSee('Kembali ke beranda')
        ->assertSee('href="'.$home.'"', false)
        ->assertDontSee(M2C_SECRET)
        ->assertDontSee('User does not have the right roles');

    $html = (string) $response->getContent();

    // CSP: no style attribute, no inline handler, and every inline <script>/<style> carries the nonce.
    expect($html)->not->toContain('style="')
        ->and(preg_match('/\son[a-z]+="/', $html))->toBe(0)
        ->and(preg_match('/<(?:style|script)(?![^>]*\bnonce=)(?![^>]*\bsrc=)[^>]*>/', $html))->toBe(0)
        ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("style-src 'self' 'nonce-")
        // A page, not a debug dump.
        ->and(strlen($html))->toBeLessThan(20000);
}

it('renders every status in the app look for a guest', function (int $status, string $title, string $sentence): void {
    config(['app.debug' => false]);

    m2cAssertErrorPage($this->get('/_galat/'.$status), $status, $title, $sentence, route('public.home'));
})->with('error pages');

it('renders every status in the app look for a signed-in user', function (int $status, string $title, string $sentence): void {
    config(['app.debug' => false]);

    m2cAssertErrorPage($this->actingAs(H::staff(RoleName::Guru))->get('/_galat/'.$status), $status, $title, $sentence, route('home'));
})->with('error pages');

it('stays the same small page with APP_DEBUG on', function (int $status, string $title, string $sentence): void {
    config(['app.debug' => true]);

    m2cAssertErrorPage($this->get('/_galat/'.$status), $status, $title, $sentence, route('public.home'));
    m2cAssertErrorPage($this->actingAs(H::staff(RoleName::Guru))->get('/_galat/'.$status), $status, $title, $sentence, route('home'));
})->with([
    '403' => [403, 'Akses ditolak', 'Tidak ada akses ke halaman ini.'],
    '404' => [404, 'Tidak ditemukan', 'Halaman tidak ditemukan.'],
    '405' => [405, 'Tidak bisa dibuka', 'Alamat ini tidak bisa dibuka langsung.'],
    '419' => [419, 'Kedaluwarsa', 'Halaman kedaluwarsa. Muat ulang lalu coba lagi.'],
]);

it('hides the server error behind the 500 page when debug is off', function (): void {
    config(['app.debug' => false]);
    Exceptions::fake(); // reported as usual, but kept out of storage/logs

    m2cAssertErrorPage($this->get('/_galat-server'), 500, 'Ada gangguan', 'Terjadi kesalahan di server.', route('public.home'));

    Exceptions::assertReported(RuntimeException::class);
});

it('answers a role refusal in Indonesian, without the package message', function (bool $debug): void {
    config(['app.debug' => $debug]);

    // Admin never opens the Kepala Sekolah approval queue (D-01): spatie's role middleware throws here.
    $response = $this->actingAs(H::staff(RoleName::Admin))->get(route('kepsek.persetujuan.index'));

    m2cAssertErrorPage($response, 403, 'Akses ditolak', 'Tidak ada akses ke halaman ini.', route('home'));
})->with(['debug off' => [false], 'debug on' => [true]]);

it('answers an unknown address and a wrong method with the small pages', function (bool $debug): void {
    config(['app.debug' => $debug]);

    m2cAssertErrorPage($this->get('/halaman-yang-tidak-ada'), 404, 'Tidak ditemukan', 'Halaman tidak ditemukan.', route('public.home'));

    // Fortify only accepts POST here; people (and crawlers) send GET.
    m2cAssertErrorPage($this->get('/user/two-factor-authentication'), 405, 'Tidak bisa dibuka', 'Alamat ini tidak bisa dibuka langsung.', route('public.home'));
})->with(['debug off' => [false], 'debug on' => [true]]);

it('keeps JSON answers for clients that ask for JSON', function (): void {
    config(['app.debug' => false]);

    $this->getJson('/_galat/404')->assertStatus(404)->assertJsonStructure(['message']);
});

// "Kembali" ----------------------------------------------------------------------------------------

it('offers Kembali only to another page of this app', function (): void {
    config(['app.debug' => false]);
    $previous = url('/prestasi?tahun=2026');

    $this->withHeader('Referer', $previous)->get('/_galat/404')
        ->assertStatus(404)
        ->assertSee('href="'.e($previous).'"', false);

    foreach ([
        'https://contoh-lain.test/halaman',          // another site
        url('/').'.contoh-lain.test/halaman',        // host that only starts like ours
        'javascript:alert(1)',
        url('/_galat/404'),                          // the error address itself
        '',
    ] as $referer) {
        $html = (string) $this->withHeader('Referer', $referer)->get('/_galat/404')->assertStatus(404)->getContent();

        expect(substr_count($html, 'class="btn '))->toBe(1, 'Referer: '.$referer);
    }
});

it('offers no Kembali where going back only repeats the answer', function (int $status): void {
    config(['app.debug' => false]);

    $html = (string) $this->withHeader('Referer', url('/prestasi'))->get('/_galat/'.$status)->assertStatus($status)->getContent();

    expect(substr_count($html, 'class="btn '))->toBe(1);
})->with([429, 503]);

// No query ------------------------------------------------------------------------------------------

it('renders every error view without a query', function (string $view, int $status): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = view('errors.'.$view, ['exception' => new HttpException($status, M2C_SECRET)])->render();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([])
        ->and($html)->toContain('Kode '.$status)
        ->and($html)->toContain('Kembali ke beranda')
        ->and($html)->not->toContain(M2C_SECRET);
})->with([
    ['401', 401], ['403', 403], ['404', 404], ['405', 405], ['419', 419], ['429', 429], ['500', 500], ['503', 503],
    ['4xx', 418], ['5xx', 502],
]);

it('does not look the user up for a server error', function (): void {
    config(['app.debug' => false]);
    $user = User::query()->findOrFail(H::activeStudent()->user_id);

    // A session that names a user, as after a login; the 500 page must not go and fetch that user.
    Route::get('/_galat-tanpa-web', fn () => abort(500, M2C_SECRET))->middleware(StartSession::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->withSession([auth()->guard()->getName() => $user->getKey()])->get('/_galat-tanpa-web');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertStatus(500)->assertSee('Terjadi kesalahan di server.')->assertSee('href="'.route('public.home').'"', false);
    expect($queries)->toBe([]);
});

it('knows a signed-in visitor on a public page from the started session', function (): void {
    config(['app.debug' => false]);
    $user = User::query()->findOrFail(H::activeStudent()->user_id);

    Route::get('/_galat-publik', fn () => abort(404))->middleware(StartSession::class);

    $this->withSession([auth()->guard()->getName() => $user->getKey()])->get('/_galat-publik')
        ->assertStatus(404)
        ->assertSee('href="'.route('home').'"', false);
});

// /verifikasi-surat ----------------------------------------------------------------------------------

it('tells a wrong letter code that the letter is not registered, on its own page', function (string $code): void {
    $this->get('/verifikasi-surat/'.$code)
        ->assertNotFound()
        ->assertSee('Verifikasi surat tugas')
        ->assertSee('Tidak ditemukan')
        ->assertSee('Surat dengan kode ini tidak terdaftar.')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with(['kode-salah', 'KODE_salah.123', str_repeat('0', 32), 'abc']);
