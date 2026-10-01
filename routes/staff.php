<?php

use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Arsip;
use App\Livewire\Humas\Jadwal;
use App\Livewire\Humas\Publikasi;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Kesiswaan\Beranda;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Livewire\Sekolah\Dashboard;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Livewire\TataUsaha\SuratTugas;
use Illuminate\Support\Facades\Route;

/*
| Area staf: guru, kesiswaan, kepala_sekolah, tata_usaha, humas.
| Loaded from bootstrap/app.php with the "web" middleware group. Staff routes also require 2FA (docs/SECURITY.md).
*/

Route::middleware(['auth'])->group(function (): void {
    //
});

// --- W2-C: kepsek, tu, humas (owner W2-C; Pint moves class names into the shared `use` list above) ---
// Every screen also authorizes through its policy inside the Livewire component (mount + each action).

Route::middleware(['area.staff', 'role:kepala_sekolah'])->prefix('kepsek')->name('kepsek.')->group(function (): void {
    Route::livewire('/', Dashboard::class)->name('beranda');
    Route::livewire('/persetujuan', Persetujuan::class)->name('persetujuan.index');
});

Route::middleware(['area.staff', 'role:tata_usaha'])->prefix('tu')->name('tu.')->group(function (): void {
    Route::livewire('/', SuratTugas::class)->name('beranda');
    Route::livewire('/surat/arsip', ArsipSurat::class)->name('surat.arsip');
    Route::livewire('/surat/{registration}', SuratTugas::class)
        ->whereUlid('registration')
        ->name('surat.show');
});

Route::middleware(['area.staff', 'role:humas'])->prefix('humas')->name('humas.')->group(function (): void {
    Route::livewire('/', Publikasi::class)->name('beranda');
    Route::livewire('/jadwal', Jadwal::class)->name('jadwal');
    Route::livewire('/apresiasi', Apresiasi::class)->name('apresiasi.index');
    Route::livewire('/arsip', Arsip::class)->name('arsip');
});

// --- end W2-C ---

// --- W2-B: kesiswaan (owner W2-B; Admin shares these screens through role:kesiswaan|admin, UI-MAP §4a) ---
// Every screen also authorizes through its policy inside the Livewire component (mount + each action).

Route::middleware(['area.staff', 'role:kesiswaan|admin'])->prefix('kesiswaan')->name('kesiswaan.')->group(function (): void {
    Route::livewire('/', Beranda::class)->name('beranda');
    Route::livewire('/lomba', Index::class)->name('lomba.index');
    Route::livewire('/verifikasi-hasil', VerifikasiHasil::class)->name('verifikasi.index');
    Route::livewire('/verifikasi-hasil/{report}', VerifikasiHasil::class)
        ->whereUlid('report')
        ->name('verifikasi.show');
    Route::livewire('/talenta', Talenta::class)->name('talenta.index');
    Route::livewire('/import-siswa', ImportSiswa::class)->name('import');
    // Shared with Humas (W2-C's class, authorized by appreciation.manage through AppreciationEventPolicy).
    Route::livewire('/apresiasi', Apresiasi::class)->name('apresiasi.index');
});

// Admin import screen (UI-MAP #23, same component as kesiswaan.import); kept here so routes/admin.php stays W2-D's.
Route::middleware(['area.staff', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('/import-siswa', ImportSiswa::class)->name('import');
});

// --- end W2-B ---
