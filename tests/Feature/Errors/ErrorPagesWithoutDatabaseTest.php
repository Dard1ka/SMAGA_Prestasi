<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

/*
| M2-C: the error pages must still render while the database is down (that is when a visitor needs them most).
| No RefreshDatabase here on purpose: the default connection points at a closed port, so any query from the
| page, its layout or the view composer would throw and Laravel would fall back to its plain text page.
*/

beforeEach(function (): void {
    $this->withoutVite();

    config([
        'app.debug' => false,
        'database.connections.mati' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'mati', 'username' => 'mati', 'password' => 'mati',
            'options' => [PDO::ATTR_TIMEOUT => 1],
        ],
        'database.default' => 'mati',
    ]);
    DB::purge();

    Route::middleware('web')->get('/_galat/{code}', fn (string $code) => abort((int) $code))->whereNumber('code');
});

it('renders the error pages for a guest without a database', function (int $status, string $sentence): void {
    $this->get('/_galat/'.$status)
        ->assertStatus($status)
        ->assertSee('<html lang="id">', false)
        ->assertSee($sentence)
        ->assertSee('Kembali ke beranda')
        ->assertSee('href="'.route('public.home').'"', false);
})->with([
    [403, 'Tidak ada akses ke halaman ini.'],
    [404, 'Halaman tidak ditemukan.'],
    [419, 'Halaman kedaluwarsa. Muat ulang lalu coba lagi.'],
    [500, 'Terjadi kesalahan di server.'],
    [503, 'Aplikasi sedang dirawat.'],
]);

it('answers an unknown address without a database', function (): void {
    $this->get('/halaman-yang-tidak-ada')
        ->assertNotFound()
        ->assertSee('Halaman tidak ditemukan.')
        ->assertSee('Kembali ke beranda');
});

it('shows the 500 page when looking up the signed-in user fails', function (): void {
    Exceptions::fake(); // the failed query is reported; keep it out of storage/logs

    // The session names a user, the `web` middleware asks for it, the database does not answer.
    $this->withSession([auth()->guard()->getName() => 1])->get('/_galat/404')
        ->assertStatus(500)
        ->assertSee('Terjadi kesalahan di server.')
        ->assertSee('Kembali ke beranda')
        ->assertSee('href="'.route('public.home').'"', false)
        ->assertDontSee('SQLSTATE');

    Exceptions::assertReported(QueryException::class);
});
