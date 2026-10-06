# M3-A — Talenta siswa: restore to a chosen stage, "Semua" with every status, download with a status filter  ·  status: done (no browser run)

Lead, hand test of 2026-10-06 (Kesiswaan › Talenta siswa, `/kesiswaan/talenta`):
1. "kesiswaan, profil talenta, fitur tidak lolos bisa dipulihkan itu tolong bisa diarahkan kembali ke pemetaan , seleksi atau perserta pembinaan atau terdaftar, dan bisa muncul jg di semua lengkap dengan statusnya"
2. "Gambar 2 download data nya bisa memilih mau download data yang mana, semua, atau yang terdaftar saja, atau yang status lainnya (dikasih filter)"

## Files (owned by me)
- `app/Actions/Talent/RestoreTalentApplication.php` — target stage as a parameter, `TARGETS`, `TARGET_MESSAGE`, audit `rejected_from`
- `app/Notifications/Talent/TalentApplicationRestored.php` — own sentence for a restore into Peserta pembinaan
- `app/Livewire/Kesiswaan/Talenta.php` + `resources/views/livewire/kesiswaan/talenta.blade.php` — stage choice in the "Pulihkan" dialog, new dialog `download-talenta`, bulk "Tidak lolos" dialog counts honestly
- Tests, new: `tests/Feature/Kesiswaan/TalentRestoreStageTest.php` (20 cases), `tests/Feature/Kesiswaan/TalentaM3Test.php` (39 cases)
- Tests, changed: `tests/Feature/Kesiswaan/KesiswaanScreensTest.php`, two assertions in two Talenta cases only (the panel sentence and the dialog sentence, both reworded; `restoreTo` asserted)
- Not touched: `ProfilTalenta` (it shows whatever stage the row is in), `TalentStatus`, `TalentApplication`, the policy, `RequiresRecentPassword`, `resume-download.blade.php`, `PasswordConfirmResumeTest`, the evidence link in the panel, seeders, E2E. No migration.

## What changed and why
**1. "Pulihkan" to a chosen stage.** `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null, ?TalentStatus $to = null)`.
`$to` must be one of `TARGETS` (Terdaftar, Pemetaan, Seleksi, Peserta pembinaan), else `ValidationException` under `to`; null = the stage before
the rejection (`restoreTarget()`), so every old call behaves as before. Still only from `tidak_lolos` (checked under the lock), same policy
(`restore`), same transaction; the open "Minta ditinjau ulang" is closed, the note for the student (typed, or the saved draft) is sent, pembina /
assessment date / note for the guru stay. Audit `talent.restored`: `from` (tidak_lolos), `to`, new `rejected_from`. The student's notification
names the stage ("…dibuka kembali, sekarang di tahap Pemetaan." / "…dibuka kembali. Kamu sekarang peserta pembinaan.").
Stage requirements: I read `MoveTalentStage` and `UpdateTalentAssessment`; no stage requires a pembina or a date there (both optional at every
stage, also for "Lolos ke Peserta pembinaan" and the bulk move), so a restore requires none either. Nothing new was invented.
Screen: the dialog `pulihkan-talenta` shows four tiles "Kembalikan ke tahap" (`restoreTo`), the earlier stage preselected and marked "Tahap
sebelumnya" (the others "Tahap 1…4"); unknown earlier stage → Terdaftar preselected with a hint. `restore()` re-authorizes and refuses any other
value. The panel of a tidak-lolos row now says "Tahap sebelumnya: X. Pulihkan untuk mengembalikannya ke tahap yang kamu pilih."

**2. "Semua" with every status.** Finding: the tab already listed `tidak_lolos` rows with the chip "Tidak lolos", counted them, and showed the
recycle icon instead of the check (since M1-D); I changed no query. What I checked and now cover with tests: rows + chip + count, row actions
(restore, no forward action, a forged `advanceRow` does nothing), the panel, a restore from "Semua" (row stays, new chip, counts follow).
Possible reason the lead missed them: inside "Semua" the tidak-lolos rows sort last, so with more than 30 rows they sit behind "Tampilkan lebih
banyak". Mixed bulk selection: final rows (Peserta pembinaan, Tidak lolos) were and are skipped ("N dilewati (tahap final)"); fixed: the bulk
"Tidak lolos" dialog counted them as "ditandai tidak lolos", it now counts only what it rejects and names the skipped ones, and does not open for
a selection of final rows only. An earlier rejection is never overwritten (`rejected_from` stays).

**3. Download with a filter.** The button calls `askExport`: dialog `download-talenta` with five tick tiles (status + number of rows), "Pilih
semua status", one line naming the other active filters, "Download". Preselected: the status of the open tab, all five on "Semua".
`download()` keeps only real `TalentStatus` values (none → "Pilih minimal satu status.") and calls
`export(AdminRecipients, string $status = '')`: `''` = what the open tab lists (old behaviour, old tests), `'semua'`, or values joined by commas;
anything else is a 404. Everything of QA4 is unchanged (title, "Diekspor oleh", bold header, text cells, row cap, audit, Admin alert). The password
gate passes the call to resume as a literal rebuilt from enum values, e.g. `export('terdaftar,tidak_lolos')`, so after the password page
`wire:init` starts the same download; `returnUrl()` keeps tab and filters. "Filter:" line: `Tahap: Terdaftar, Tidak lolos`, or `Tahap: semua;
Kelas: X-8`, or `semua talenta`. Audit `talent.exported.filters.tahap` holds the chosen statuses joined by commas (absent = all).

## Contracts other areas can use
- `RestoreTalentApplication::TARGETS` (list of `TalentStatus`), `::TARGET_MESSAGE`, `handle(…, ?TalentStatus $to = null)`
- `Talenta::EXPORT_DIALOG` (`download-talenta`), `Talenta::EXPORT_ALL` (`semua`); wire methods `askExport`, `selectAllExport`, `download`, `export('<statuses>')`

## Commands verified (2026-10-06, Docker, in-memory SQLite, while the other M3 agents were editing the same tree)
- Baseline before my change: `docker compose run --rm --no-deps app php artisan test --filter="TalentActionsTest|TalentDraftNoteTest|PasswordConfirmResumeTest|KesiswaanScreensTest|KesiswaanHardeningTest"` → 89 passed (619 assertions)
- `… php artisan test tests/Feature/Kesiswaan/TalentRestoreStageTest.php tests/Feature/Kesiswaan/TalentaM3Test.php` → **59 passed (415 assertions)**
- `… php artisan test --filter="TalentActionsTest|TalentDraftNoteTest|TalentaM3Test|TalentRestoreStageTest|KesiswaanScreensTest|KesiswaanHardeningTest|PasswordConfirmResumeTest|CspSafeTest|ProfilTalentaTest|ApplyTalentTest|RequestTalentReviewTest|SaveTalentProfileTest|TalentPoliciesTest|GuruAreaTest|MailInjectionTest"` → **281 passed (1970 assertions)**, exit 0 (770 s)
- `… ./vendor/bin/pint <my 3 PHP files + 3 test files>` → PASS, nothing to fix
- The new tests passed on their first run; I did not run them against the old code. The restore cases cannot pass there (PHP drops the extra argument, the row would land in the earlier stage).

## Not verified
- No browser run: the look of the tiles in the 440 px dialogs (and at phone width), the tick state after "Pilih semua status", the real round trip password page → automatic download (tested at Livewire level only: redirect, `url.intended`, `wire:init="export(…)"`, then that call).
- No `npm run build`: I added no class that other views do not already use (`radio-pick` with `sub`, `multiple` and `:columns="2"` is the component's own markup).
- MySQL not exercised; full suite not run (by instruction); `email-scenario.php` not run (it has no restore step).

## Decisions the lead may want to change (sementara)
- Dialog says "Status" (the lead's word, the table column); the file says "Tahap: …" (QA4's text, the file's own column header). One word everywhere is a small change.
- `export()` without an argument still means "what the open tab lists". The button never calls it that way; it keeps old calls and a resume pending across the deploy working.
- A restore straight into Peserta pembinaan needs no pembina, as "Lolos ke Peserta pembinaan" needs none today.
- The dialog does not ask for pembina or date; Kesiswaan edits them in the panel, which follows the row. The kept assessment date is not repeated in the notification (it may be past).
- Tiles show "Tahap 1…4" and "Tahap sebelumnya"; the sentence "Kamu sekarang peserta pembinaan." for that target.
- Tidak-lolos rows stay last in "Semua"; no bulk "Pulihkan" (backlog #56).

## Requests for other owners
- Lead / coordinator, DECISIONS: a new entry that replaces the "to its earlier stage" part of D-86 (Kesiswaan chooses the stage; earlier stage preselected) and one for the download choice. Then CLAUDE.md §5 (Talent paragraph), UI-MAP #18, PANDUAN-PENGGUNA (Kesiswaan).
- Owner of `app/Enums/TalentStatus.php` and `app/Models/TalentApplication.php`: the doc comments still say a restore goes "back to the stage before the rejection"; that stage is now the default / preselected one.
- `resources/views/livewire/partials/resume-download.blade.php`: its comment says "a literal call such as \"export\""; Talenta now also passes `export('terdaftar,seleksi')`. No code change needed.
- `Admin\AuditLog` (optional): the new property `rejected_from` of `talent.restored`; `filters.tahap` of `talent.exported` can list several statuses.
- `tests/E2E/support/email-scenario.php` (backlog #46): a restore step could pass a target stage.
- Coordinator: the evidence link in the panel is untouched, as agreed.

## For the user guide (plain)
- A choice marked "Tidak lolos" can be put back with "Pulihkan" (recycle icon on the row, or the button in the panel). A small window asks which stage it returns to: Terdaftar, Pemetaan, Seleksi/asesmen or Peserta pembinaan. The stage it had before is already selected and marked "Tahap sebelumnya". A note for the student is optional. The student gets a notification that names the stage; a "Minta ditinjau ulang" request is closed.
- The tab "Semua" shows every choice, also the ones that are "Tidak lolos", each with its status. Those rows have "Pulihkan" instead of the check.
- "Download data (Excel)" first asks which statuses go into the file. The status of the tab you are on is ticked; tick more, or "Pilih semua status". The filters jenis, cabang, kelas and the search still apply, and the file's "Filter:" line says what was chosen.
- If the app asks for your password, the download starts by itself afterwards, with the same choice.
