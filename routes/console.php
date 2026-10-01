<?php

use App\Actions\Users\ApplyPrincipalReplacement;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- W2-A2: registration timeline + report reminders (docs/dev-notes/W2-A2.md) ---
// surat_terbit → berlangsung → menunggu_laporan (idempotent, audited), then reminders 14 and 3 days before the D-25 due date.
Schedule::command('sth:registrations:advance')
    ->dailyAt('00:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('sth:reports:remind')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
// --- end W2-A2 ---

// --- W2-D: automatic archive + Kepala Sekolah replacement (docs/dev-notes/W2-D.md, SECURITY §3m-8/§3m-9) ---
// Archive students whose archive_at has arrived (idempotent; sessions revoked; D-13, D-29).
Schedule::command('sth:arsip-otomatis')
    ->dailyAt('01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();

// Apply "Ganti Kepala Sekolah" requests whose 24 h delay has passed (idempotent).
Schedule::call(fn () => app(ApplyPrincipalReplacement::class)->applyDue())
    ->name('sth:ganti-kepala-sekolah')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
// --- end W2-D ---
