<?php

declare(strict_types=1);

use App\Livewire\Admin\ArsipOtomatis;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\Pengaturan;
use App\Livewire\Admin\Pengguna;
use App\Livewire\Admin\Ringkasan;
use App\Livewire\Akun\StatusAktivasi;
use App\Livewire\Sekolah\Dashboard;
use Illuminate\Support\Facades\Route;

/*
| Area admin (role: admin), docs/UI-MAP.md §5. Loaded from bootstrap/app.php with the "web" middleware group.
| `area.staff` = auth, active, activated, staff.2fa. Every screen authorizes again through its policy.
| Owner: W2-D. admin.import lives in routes/staff.php (W2-B block, same component as kesiswaan.import).
*/

Route::middleware(['area.staff', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    // Landing = the shared school dashboard (UI-MAP #20, W2-C's Sekolah\Dashboard, `dashboard.view`).
    Route::livewire('/', Dashboard::class)->name('beranda');

    // Account/settings blockers + latest Admin audit entries (W2-D addition).
    Route::livewire('/ringkasan', Ringkasan::class)->name('ringkasan');

    // Sensitive screens: password confirmation to open (framework window) + 15 min inside each action
    // (App\Livewire\Admin\Concerns\RequiresRecentPassword, SECURITY §3m-9).
    Route::middleware('password.confirm')->group(function (): void {
        Route::livewire('/pengaturan', Pengaturan::class)->name('pengaturan');
        Route::livewire('/pengguna', Pengguna::class)->name('pengguna.index');
    });
});

// --- B1: Status aktivasi for Admin (backlog #5, UI-MAP §5, SECURITY K9/K2/K3/K8; same component as kesiswaan.aktivasi.index) ---
Route::middleware(['area.staff', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('/aktivasi', StatusAktivasi::class)->name('aktivasi.index');
});
// --- end B1 ---

// --- B5: Arsip otomatis + Log audit (backlog #8, UI-MAP §5, SECURITY §3m-8/§3m-9; owner B5) ---
// Both screens authorize again inside the component (user.archive / audit.view); restore, archive-date changes
// and the CSV export need a password confirmation from the last 15 minutes inside the action.
Route::middleware(['area.staff', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('/arsip', ArsipOtomatis::class)->name('arsip.index');
    Route::livewire('/audit', AuditLog::class)->name('audit.index');
});
// --- end B5 ---
