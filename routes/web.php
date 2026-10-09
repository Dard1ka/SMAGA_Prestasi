<?php

declare(strict_types=1);

use App\Http\Controllers\FileController;
use App\Http\Controllers\LetterVerificationController;
use App\Http\Middleware\PublicPageHeaders;
use App\Livewire\Akun\BukanSaya;
use App\Livewire\Akun\Keamanan;
use App\Livewire\Akun\Notifikasi;
use App\Livewire\Auth\Activation;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\Publik\Beranda;
use App\Livewire\Publik\PemenangLomba;
use App\Livewire\Publik\Peringkat;
use App\Livewire\Publik\PrestasiBidang;
use App\Livewire\Publik\PrestasiSiswa;
use App\Livewire\Siswa\TurMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Public pages + authentication (owner of the auth part: W1-C). Fortify registers:
| GET/POST /login (login, login.store), POST /logout (logout), GET/POST /user/confirm-password
| (password.confirm, password.confirm.store), GET/POST /two-factor-challenge (two-factor.login[.store])
| and the /user/two-factor-* JSON endpoints. Area routes live in routes/{siswa,staff,admin}.php.
*/

// --- W2-D: public pages (no login, D-16/D-31; throttle 60/min/IP, SECURITY §3m-7) ---
// Data only through App\Livewire\Publik\Data\PublicAchievements (column allowlist, consent, cache).
// PublicPageHeaders adds X-Robots-Tag: noindex unless Admin allowed indexing (student pages: always).
// QA5 retest: every throttled route has its own counter (3rd parameter = key prefix). Unnamed `throttle:N,M`
// limits share one counter per user or IP, so browsing public pages used up the QR letter check (429).
Route::middleware(['throttle:60,1,publik', PublicPageHeaders::class])->group(function (): void {
    Route::livewire('/', Beranda::class)->name('public.home');
    Route::livewire('/prestasi', PrestasiBidang::class)->name('public.prestasi');
    Route::livewire('/prestasi/siswa/{slug}', PrestasiSiswa::class)
        ->where('slug', '[a-f0-9]{20}')
        ->name('public.prestasi.siswa');
    Route::livewire('/prestasi/lomba/{competition}', PemenangLomba::class)
        ->where('competition', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
        ->name('public.lomba.pemenang');
    Route::livewire('/peringkat', Peringkat::class)->name('public.peringkat');
});
// --- end W2-D ---

// --- W2-C: public letter verification (QR on every surat tugas, SECURITY §3g; 30/min/IP, noindex) ---
// M2-C: any one-segment code reaches the controller, so a mistyped code gets its own "Surat dengan kode ini
// tidak terdaftar." page instead of the generic 404 (the controller only looks up 32 hex characters).
Route::get('/verifikasi-surat/{code}', LetterVerificationController::class)
    ->where('code', '[^/]{1,128}')
    ->middleware('throttle:30,1,verifikasi-surat')
    ->name('public.surat.verifikasi');
// --- end W2-C ---

// M2-C (QA Q2-03): people type /logout into the address bar. Logging out stays POST + CSRF (Fortify `logout`);
// this GET never ends a session, it only answers with a redirect instead of a 405 page.
Route::get('/logout', fn (Request $request) => redirect()->route($request->user() !== null ? 'home' : 'login'))
    ->name('logout.get');

Route::middleware('guest')->group(function (): void {
    Route::livewire('/lupa-kata-sandi', ForgotPassword::class)->name('password.request');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    // First login: email → 6-digit code → password (student: change or keep; staff: change). The only screen
    // until activation is done.
    Route::livewire('/aktivasi', Activation::class)->name('aktivasi');

    // Staff TOTP setup; `staff.2fa` sends staff here until 2FA is confirmed.
    Route::livewire('/akun/2fa', TwoFactorSetup::class)
        ->middleware(['activated', 'password.confirm'])
        ->name('two-factor.setup');

    // Fortify `home`: forwards each role to its area once wave 2 defines it; placeholder until then.
    Route::get('/beranda', function (Request $request) {
        $user = $request->user();

        $areas = [
            'admin' => 'admin.beranda',
            'kepala_sekolah' => 'kepsek.beranda',
            'kesiswaan' => 'kesiswaan.beranda',
            'tata_usaha' => 'tu.beranda',
            'humas' => 'humas.beranda',
            'guru' => 'guru.beranda',
            'siswa' => 'siswa.beranda',
        ];

        foreach ($areas as $role => $routeName) {
            if ($user->hasRole($role) && Route::has($routeName)) {
                return redirect()->route($routeName);
            }
        }

        // W4: a new privileged staff account has no role until a second Admin approves it (or 24 h pass).
        return view('auth.placeholder', [
            'title' => 'Beranda',
            'message' => $user->roles()->doesntExist()
                // Staff copy without "kamu" / "-mu" (D-119, QA9).
                ? 'Akun ini belum punya peran. Peran dari Admin berlaku setelah disetujui. Coba masuk lagi nanti.'
                : 'Halaman beranda sedang disiapkan.',
        ]);
    })->middleware(['activated', 'staff.2fa'])->name('home');

    // W2-A1: after activation (and from Bantuan) → Tur-Menu overlay on siswa.beranda (docs/UI-MAP.md #6/#7).
    // QA5 retest: forced once through a session flash, not ?tur=1, so a reload after "Lewati" shows no tour.
    Route::get('/siswa/tur', fn () => redirect()->route('siswa.beranda')->with(TurMenu::FORCE_KEY, true))
        ->middleware(['activated', 'role:siswa'])->name('siswa.tour');

    // W2-A1: policy-checked file download (SECURITY §3d); purposes → App\Policies\FilePolicy.
    Route::get('/berkas/{file}', [FileController::class, 'show'])
        ->middleware(['activated', 'staff.2fa', 'throttle:60,1,berkas'])->name('files.show');

    // Cover picture of a lomba for <img> on cards: the small JPEG, signed-in users only, same FilePolicy, cached
    // by the browser and not audited (FileController::cover). Its own counter: one catalog page loads a dozen.
    Route::get('/berkas/sampul/{file}', [FileController::class, 'cover'])
        ->middleware(['activated', 'staff.2fa', 'throttle:240,1,berkas-sampul'])->name('files.cover');
});

// W2-A1: public certificate link (guests too) — FilePolicy::viewPublic only (consent + Admin switch), else 404.
Route::get('/berkas/publik/{file}', [FileController::class, 'publik'])
    ->middleware('throttle:30,1,berkas-publik')->name('files.public');

// --- B1: Akun › Keamanan, Notifikasi (every role), K8 "Bukan saya?" (docs/dev-notes/B1.md, UI-MAP §2) ---
// Keamanan/Notifikasi act only on the signed-in user (no ids in the URL). `staff.2fa` only affects staff.
Route::middleware(['auth', 'active', 'activated', 'staff.2fa'])->group(function (): void {
    Route::livewire('/akun/keamanan', Keamanan::class)->name('akun.keamanan');
    Route::livewire('/notifikasi', Notifikasi::class)->name('notifikasi.index');
});

// Signed, single-use link from the email-binding notice (7 days). Guests too; GET only shows the page.
Route::livewire('/akun/bukan-saya/{notice}', BukanSaya::class)
    ->whereUlid('notice')
    ->middleware(['signed', 'throttle:20,1,bukan-saya'])
    ->name('akun.bukan-saya');
// --- end B1 ---
