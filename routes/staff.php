<?php

use App\Http\Controllers\AppreciationListPrintController;
use App\Http\Controllers\LetterPrintController;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Akun\StatusAktivasi;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Arsip;
use App\Livewire\Humas\Jadwal;
use App\Livewire\Humas\Publikasi;
use App\Livewire\Kepsek\Laporan;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Kepsek\PrestasiBidang as KepsekPrestasiBidang;
use App\Livewire\Kepsek\SuratSaya;
use App\Livewire\Kesiswaan\Beranda;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Livewire\Sekolah\Dashboard;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Livewire\TataUsaha\SuratTugas;
use App\Livewire\TataUsaha\TemplateSurat;
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
    // M1-A: "Cetak" opens the letter PDF inline (LetterPolicy::view + audit inside StreamLetter); the two
    // letterhead emblems for the HTML preview (LetterPolicy::viewQueue).
    Route::get('/surat/cetak/{letter}', [LetterPrintController::class, 'show'])
        ->whereUlid('letter')
        ->name('surat.cetak');
    Route::get('/surat/kop/{logo}', [LetterPrintController::class, 'logo'])
        ->whereIn('logo', array_keys(LetterPrintController::LOGOS))
        ->name('surat.kop');
});

Route::middleware(['area.staff', 'role:humas'])->prefix('humas')->name('humas.')->group(function (): void {
    Route::livewire('/', Publikasi::class)->name('beranda');
    Route::livewire('/jadwal', Jadwal::class)->name('jadwal');
    Route::livewire('/apresiasi', Apresiasi::class)->name('apresiasi.index');
    Route::livewire('/arsip', Arsip::class)->name('arsip');
    // D-139: Humas verifies reports too; the same screen as kesiswaan.verifikasi.*, area-aware.
    Route::livewire('/verifikasi-hasil', VerifikasiHasil::class)->name('verifikasi.index');
    Route::livewire('/verifikasi-hasil/{report}', VerifikasiHasil::class)->whereUlid('report')->name('verifikasi.show');
});

// D-141: "Cetak daftar" of an appreciation event, an inline PDF; shared by Humas, Kesiswaan and Admin
// (AppreciationEventPolicy::printList, appreciation.manage).
Route::middleware(['area.staff', 'role:humas|kesiswaan|admin'])->group(function (): void {
    Route::get('/apresiasi/{event}/cetak', [AppreciationListPrintController::class, 'show'])
        ->whereUlid('event')
        ->name('apresiasi.cetak');
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

// --- B4: guru area (backlog #3, UI-MAP §4e) + Kesiswaan database prestasi (backlog #4, UI-MAP §4a) ---
// Guru screens show the guru's own binaan only (RegistrationPolicy::viewBinaan/viewAsMentor; others → 404).
// `/beranda` already forwards role guru to guru.beranda (routes/web.php). Database prestasi: AchievementPolicy::viewDatabase/export.

Route::middleware(['area.staff', 'role:guru'])->prefix('guru')->name('guru.')->group(function (): void {
    Route::livewire('/', Binaan::class)->name('beranda');
    Route::livewire('/binaan/{registration}', BinaanDetail::class)
        ->whereUlid('registration')
        ->name('binaan.show');
    Route::livewire('/lomba', GuruLomba::class)->name('lomba.index');
});

Route::middleware(['area.staff', 'role:kesiswaan|admin'])->prefix('kesiswaan')->name('kesiswaan.')->group(function (): void {
    Route::livewire('/prestasi', DatabasePrestasi::class)->name('prestasi.index');
});

// --- end B4 ---

// --- B1: Status aktivasi (backlog #5, UI-MAP §4a, SECURITY K9/K2; Admin uses admin.aktivasi.index) ---
// App\Livewire\Akun\StatusAktivasi authorizes student.view on mount and the action permission in every action.

Route::middleware(['area.staff', 'role:kesiswaan|admin'])->prefix('kesiswaan')->name('kesiswaan.')->group(function (): void {
    Route::livewire('/aktivasi', StatusAktivasi::class)->name('aktivasi.index');
});

// --- end B1 ---

// --- B5: Kepala Sekolah prestasi/laporan/surat (backlog #6, UI-MAP §4b) + TU template surat (backlog #7, UI-MAP §4c) ---
// Every screen authorizes again inside the component: AchievementPolicy::viewSchoolReport/exportSchoolReport,
// LetterPolicy::viewAny, SettingPolicy::viewLetterTemplate/manageLetterTemplate. kepsek.laporan is also open to Admin
// (the shared dashboard at admin.beranda links "Download laporan" there).

Route::middleware(['area.staff', 'role:kepala_sekolah'])->prefix('kepsek')->name('kepsek.')->group(function (): void {
    Route::livewire('/prestasi', KepsekPrestasiBidang::class)->name('prestasi');
    Route::livewire('/surat', SuratSaya::class)->name('surat.index');
});

Route::middleware(['area.staff', 'role:kepala_sekolah|admin'])->prefix('kepsek')->name('kepsek.')->group(function (): void {
    Route::livewire('/laporan', Laporan::class)->name('laporan');
});

Route::middleware(['area.staff', 'role:tata_usaha'])->prefix('tu')->name('tu.')->group(function (): void {
    Route::livewire('/surat/template', TemplateSurat::class)->name('surat.template');
});

// --- end B5 ---
