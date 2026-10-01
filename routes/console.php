<?php

use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Users\ApplyPrincipalReplacement;
use App\Jobs\AutoPublishPublications;
use App\Jobs\CleanupOtpCodes;
use App\Jobs\ExpireInvitations;
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

// --- B3: invitation expiry + Humas auto-publish (docs/dev-notes/B3.md, backlog #9 / #12) ---
// Decline team invitations still open after registration_closes_at (D-36 rule applies afterwards).
Schedule::job(new ExpireInvitations)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// terjadwal publications whose scheduled_at arrived → dipublikasikan + reminder to Humas.
Schedule::job(new AutoPublishPublications)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
// --- end B3 ---

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

// --- B2: security housekeeping (docs/dev-notes/B2.md, backlog #16, SECURITY §3b/§3m-9) ---
// OTP rows older than 24 h are deleted (codes live 10 minutes).
Schedule::job(new CleanupOtpCodes)
    ->hourlyAt(20)
    ->withoutOverlapping()
    ->onOneServer();

// Safety net for the audit hash chain: seal rows whose after-commit seal was lost.
Schedule::command('sth:audit:seal')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Verify the whole chain daily; the result and the chain head go to the `security` log (off-site backup).
Schedule::command('sth:audit:verify')
    ->dailyAt('02:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer();
// --- end B2 ---

// --- B5/W4: delayed privileged role changes (docs/dev-notes/B5.md, backlog #13 / #27) ---
// Requests made while only one Admin existed apply 24 h later unless cancelled (idempotent; approvals apply at once).
Schedule::call(fn () => app(ApplyRoleChange::class)->applyDue())
    ->name('sth:peran-tertunda')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
// --- end B5/W4 ---
