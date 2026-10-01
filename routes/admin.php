<?php

declare(strict_types=1);

use App\Livewire\Admin\Pengaturan;
use App\Livewire\Admin\Pengguna;
use App\Livewire\Admin\Ringkasan;
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
