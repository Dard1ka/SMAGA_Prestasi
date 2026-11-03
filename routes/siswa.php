<?php

declare(strict_types=1);

use App\Livewire\Siswa\Beranda;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Lomba\Detail as LombaDetail;
use App\Livewire\Siswa\Lomba\Katalog as LombaKatalog;
use App\Livewire\Siswa\Lomba\Usulkan as LombaUsulkan;
use App\Livewire\Siswa\LombaSaya\Index as LombaSayaIndex;
use App\Livewire\Siswa\Peringkat as SiswaPeringkat;
use App\Livewire\Siswa\Portofolio;
use App\Livewire\Siswa\ProfilTalenta;
use Illuminate\Support\Facades\Route;

/*
| Area siswa (role: siswa). Loaded from bootstrap/app.php with the "web" middleware group.
| Group `area.siswa` = auth, active, activated, role:siswa (bootstrap/app.php). Every screen also
| authorizes through a policy. Shared file: each wave-2 agent edits only its own commented block.
*/

Route::middleware('area.siswa')->prefix('siswa')->name('siswa.')->group(function (): void {
    // --- W2-A1: beranda, katalog + usulan, detail + daftar, konfirmasi tim, bantuan ---
    Route::livewire('/', Beranda::class)->name('beranda');
    Route::livewire('/lomba', LombaKatalog::class)->name('lomba.index');
    // Before /lomba/{competition}: "usulkan" is no lomba id (D-145, the whole lomba on one page).
    Route::livewire('/lomba/usulkan', LombaUsulkan::class)->name('lomba.usulkan');
    Route::livewire('/lomba/{competition}', LombaDetail::class)->name('lomba.show');
    Route::livewire('/undangan/{registration}', KonfirmasiTim::class)->name('undangan.show');
    Route::view('/bantuan', 'siswa.bantuan')->name('bantuan');
    // --- end W2-A1 ---

    // --- W2-A2: lomba saya, lapor hasil, portofolio, profil talenta ---
    // Screens re-authorize via their policies (RegistrationPolicy::view, ReportPolicy::create, …): not yours → 404.
    Route::livewire('/lomba-saya', LombaSayaIndex::class)->name('lomba-saya.index');
    Route::livewire('/lomba-saya/{registration}', LombaSayaIndex::class)->name('lomba-saya.show');
    // UX2 (the lead, 2026-10-20): the laporan hasil is filled in one place, the yellow card of Lomba saya. The old page
    // address (links in old mails and bell rows) opens that card; Lomba saya authorizes as always (not yours → 404).
    Route::get('/lomba-saya/{registration}/lapor', fn (string $registration) => redirect()->route('siswa.lomba-saya.show', ['registration' => $registration, 'lapor' => 1]))
        ->name('lapor');
    Route::livewire('/portofolio', Portofolio::class)->name('portofolio');
    Route::livewire('/talenta', ProfilTalenta::class)->name('talenta');
    // --- end W2-A2 ---

    // --- B4: peringkat (backlog #2; same data rules as public.peringkat, active students only, "Kamu" row) ---
    Route::livewire('/peringkat', SiswaPeringkat::class)->name('peringkat');
    // --- end B4 ---
});
