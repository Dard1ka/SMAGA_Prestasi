<?php

use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Users\ApplyPrincipalReplacement;
use App\Jobs\CleanupOtpCodes;
use App\Jobs\ExpireInvitations;
use App\Jobs\PruneExpiredCache;
use App\Jobs\RemindDuePublications;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// QA10: every job below uses withoutOverlapping(60). The default lock lives 24 h, so a scheduler killed during a run
// skipped that job until the next day; every job is idempotent and far shorter than an hour.

// --- W2-A2: registration timeline + report reminders (docs/dev-notes/W2-A2.md) ---
// surat_terbit → berlangsung → menunggu_laporan (idempotent, audited), then reminders 14 and 3 days before the D-25 due date.
Schedule::command('sth:registrations:advance')
    ->dailyAt('00:15')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60);

Schedule::command('sth:reports:remind')
    ->dailyAt('07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60);
// --- end W2-A2 ---

// --- B3: invitation expiry + Humas publication reminder (docs/dev-notes/B3.md, backlog #9 / #12; D-122) ---
// Decline team invitations still open after registration_closes_at (D-36 rule applies afterwards).
Schedule::job(new ExpireInvitations)
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();

// terjadwal publications whose scheduled_at arrived → one reminder to Humas. Nothing is published by the scheduler
// (D-122): the row stays terjadwal until Humas presses "Tandai dipublikasikan", and only then the student is told.
Schedule::job(new RemindDuePublications)
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();
// --- end B3 ---

// --- W2-D: automatic archive + Kepala Sekolah replacement (docs/dev-notes/W2-D.md, SECURITY §3m-8/§3m-9) ---
// Archive students whose archive_at has arrived (idempotent; sessions revoked; D-13, D-29).
Schedule::command('sth:arsip-otomatis')
    ->dailyAt('01:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60)
    ->onOneServer();

// Apply "Ganti Kepala Sekolah" requests whose 24 h delay has passed (idempotent).
Schedule::call(fn () => app(ApplyPrincipalReplacement::class)->applyDue())
    ->name('sth:ganti-kepala-sekolah')
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();
// --- end W2-D ---

// --- B2: security housekeeping (docs/dev-notes/B2.md, backlog #16, SECURITY §3b/§3m-9) ---
// OTP rows older than 24 h are deleted (codes live 10 minutes).
Schedule::job(new CleanupOtpCodes)
    ->hourlyAt(20)
    ->withoutOverlapping(60)
    ->onOneServer();

// Safety net for the audit hash chain: seal rows whose after-commit seal was lost.
Schedule::command('sth:audit:seal')
    ->everyTenMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();

// Verify the whole chain daily; the result and the chain head go to the `security` log (off-site backup).
Schedule::command('sth:audit:verify')
    ->dailyAt('02:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60)
    ->onOneServer();

// Expired rows of the database cache (old versioned public-page keys, rate-limiter counters) are deleted daily.
Schedule::job(new PruneExpiredCache)
    ->dailyAt('03:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(60)
    ->onOneServer();
// --- end B2 ---

// --- B5/W4: delayed privileged role changes (docs/dev-notes/B5.md, backlog #13 / #27) ---
// Requests made while only one Admin existed apply 24 h later unless cancelled (idempotent; approvals apply at once).
Schedule::call(fn () => app(ApplyRoleChange::class)->applyDue())
    ->name('sth:peran-tertunda')
    ->everyFiveMinutes()
    ->withoutOverlapping(60)
    ->onOneServer();
// --- end B5/W4 ---
