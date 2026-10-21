# UX1: masukan lead 19 Okt 2026 (D-158…D-161) · status: committed 2026-10-21

Six requests of the lead on 2026-10-19, built on the Smaga4 checkout (`feat/fs1-feedback-sekolah` at `f220861`, the
demo copy from machine NDIKZZ installed with `demo-impor.bat`). Decisions: DECISIONS §D20.

## 1. Photo boxes outside their card (bug fix, no decision)
The lead's screenshot showed Siswa › Laporkan hasil › Bukti: the upload rows (long file names) ran past the right edge.
Cause: `<div class="grid gap-4 @if ($inline) lg:grid-cols-2 @endif">` had no column below `lg`, so its implicit track
took the min-content width of the `.ellip` (nowrap) file name. Fixed in
`resources/views/livewire/siswa/lomba-saya/lapor-hasil.blade.php` (`grid-cols-1`, `min-w-0` on the columns) and swept
the app: every grid that set columns only at a breakpoint got `grid-cols-1` (48 grids in 29 views, the CLAUDE.md §7
rule "a one-column grid is `grid-cols-1`, never an implicit track"). Humas › Arsip foto was already guarded.

## 2. TU queues newest first (D-161)
- `TataUsaha\SuratTugas::queueQuery()`: `orderByDesc('principal_decided_at')`, then `created_at`, `id`; the first
  (newest) one opens on `tu.beranda`.
- `Kepsek\Persetujuan::visibleQuery()`: `orderByDesc('created_at')`, `id`. One screen for the Kepala Sekolah and TU
  (D-150), so both see the new order. A registration resumed from `menunggu_lomba` or a team confirmed late sorts by its
  registration date (there is no column for "entered the approval queue").
- Test: `tests/Feature/TataUsaha/AntreanTerbaruTest.php`.

## 3 + 5. Bantuan akun siswa: name search, password-only reset, easy temporary password (D-158)
- `Admin\Pengaturan\Pengguna::findStudent()`: a digits-only input must be a full NISN (exact match); otherwise a part of
  the name, at least `NAME_MIN` (3) letters, `LOWER(name) LIKE %…%` with `%`, `_` and `\` dropped (the escape character
  differs between MySQL and SQLite). One match opens it (`openStudentAccount()`), several fill `#[Locked] $studentMatches`
  (at most `MATCH_LIMIT` 10, `$studentMatchesMore`), `chooseStudent(int)` opens one (404 for a non-student id).
- `ResetStudentAccount`: only the password fields change (password, `must_change_password`, `password_changed_at`,
  `initial_password_expires_at` +72 h, `temporary_password_issued_at`), plus freeze/lock/2FA cleanup, OTPs cancelled,
  sessions revoked. Email, `email_verified_at`, `activated_at`, `status` stay. `detachesEmail()`: an account frozen with
  `frozen_reason` = not_me still loses its email (audit `email_detached`). An active student then lands on the password
  step only (`DetermineActivationStep`: email verified, profile step only for pending accounts, password step because
  `must_change_password`); `CompleteActivation::changePassword()` → `SetNewPassword` (now also clears
  `initial_password_expires_at`) → `activate()` is a no-op for an active account.
- `DetermineActivationStep::boundInTestMode()`: an `account.reset` row with `email_detached` false no longer reopens the
  email step in local OTP test mode.
- `GenerateTemporaryPassword`: `([bdfghjkmnprstvwyz][aeiou]){3}[2-9]{4}` ("bakuro4827"), about 31 bits; enough for a
  password handed over face to face, valid 72 hours, replaced at the next login, behind the login limits.
- View: input "Nama atau NISN", hint under the form, match list, button and dialog "Reset kata sandi" ("Email, data diri,
  pilihan talenta, dan data lain siswa tidak berubah." or the K8 warning), the shown-once dialog ("Semua huruf kecil,
  diikuti empat angka."). Status aktivasi › "Buka pembekuan" points at "Reset kata sandi" for a "Bukan saya?" freeze.
- Tests: `tests/Feature/Admin/BantuanAkunSiswaTest.php` (new); updated `AccountAdminActionsTest` (password-only reset,
  pending student, K8 case), `AccountsQa9Test`, `UsersActionsTest`, `AccountControlsTest` (generator shape),
  `ActivationTest` (password step only), `ActivationQa10Test` (code limits after a K8 reset), `Fs1QaStaffCopyTest`.

## 4. Student notifications from the report to the appreciation (D-160)
A read-only audit agent traced every step (12 test files, 257 passed) and found the gaps fixed here:
- `App\Notifications\Verification\ReportSent` (new, a `Notice`): sent by `SubmitReport::notifyMembers()` to
  `RequestReportRevision::memberUsers()`: receipt for the ketua, "<ketua> mengirim …" for the others, first send and
  resend have their own titles. No change for the guru.
- `ReportVerified::for(…, ?string $note)`: "Catatan <peran>: …" when the verifier wrote "Catatan untuk siswa";
  `status-card.blade.php` prints it on the Capaian card.
- `PublishPublication`: `teammates()` (same report, `siap`/`terjadwal`, `publish` allowed = NamedStudent) are published
  in the same transaction with the clicked card's caption and channels (`together_with` in their audit rows), every
  student told; `$team` holds them for the toast. `Humas\Publikasi` dialog names them and counts who is told.
- `RecordAppreciation::withTeammates()` + `Humas\Publikasi::recordAppreciation()` now calls `RecordAppreciations` with the
  free teammates (verified, publish consent, on no event); the panel names them; toast counts rows and notices.
- `Siswa\Portofolio`: "Dijadwalkan diapresiasi di …" / "Diapresiasi di …" (`EventWhen::long`) and "Dipublikasikan
  Humas <tanggal>." on each prestasi card.
- `ReportRevisionRequested::for(…, bool $leaderCanResend)`: members read "Akun ketua tim tidak bisa dipakai masuk, jadi
  hubungi Kesiswaan …" when the ketua cannot log in (half of backlog #77).
- Email scenario: 7a/7c/7e expect the `ReportSent` mails; section 8 reordered (Bayu scheduled + reminded in 8a, "Tandai
  dipublikasikan" in 8b publishes Galuh's and Laras's cards too and mails all three, Galuh's appreciation in 8c); 10o tests
  the frozen account with the appreciation notice (her card went out with the team in 8b).
- Tests: `tests/Feature/Notifications/SiswaNotifikasiD160Test.php` (9).
- Not changed on purpose: no notice when an appreciation event is confirmed as held (D-87), no reminder for a report
  sent back for revision (both product questions for the lead).

## 6. Status aktivasi (D-159)
- `Akun\StatusAktivasi`: `PREVIEW` 10, `STEP` 20 for both tables (`$limit`, `$classLimit`), `BOX` 3 / `BOX_STEP` 10 for the
  two boxes (`$frozenLimit`, `$k7Limit`), `#[Url(as: 'tampil')] $tampil` (`kelas` | `siswa`, `setView()`); a list filter in
  the address or a change of one opens the list; `export()` and `returnUrl()` keep `tampil=siswa`.
- View: first row = tiles (2–4, `zero="hide"` for Kedaluwarsa and Dibekukan; 3 tiles → `sm:grid-cols-3`, 4 → 2×2 at
  `xl`) next to the aside (340 px, 400 px from 1536 px) with the two boxes (`xl:flex-1`); a `seg` tablist "Per kelas |
  Daftar siswa" with counts; one panel; footers "Menampilkan X dari Y kelas/siswa" + "Tampilkan lebih banyak". The list has
  five columns (flags under the status, activation and login in one cell), `table-class="min-w-[760px]"` so a phone
  scrolls inside the card. Jendela aktivasi (Admin) moved to the bottom. The class dialog is unchanged.
- Checked in the app's browser as the demo Kesiswaan account: 1280 px (tiles about 200 px each, list table 927 px wide
  without sideways scroll, 10 of 21 rows), 390 px (no page scroll sideways).
- Tests: `StatusAktivasiTest` (setView in four tests, three new D-159 tests).

## Checks
See TEST-RESULTS below.

## TEST-RESULTS
- Focused runs: the 16 affected files (406 tests) before the fixes, then every changed or new file green.
- Full suite `--parallel --processes=6` → **3803 passed, 1 skipped, 1 failed (22772 assertions)**, 772 s. The failure
  (`Fs1QaCopyLayoutTest`: the list's search box is on `?tampil=siswa` since D-159) was fixed and is green alone; after the
  full run only the Admin › Pengguna hint position, the dialog button label and the constants' place changed, and the
  six Admin/Kesiswaan files that cover them passed again (62 tests). `pint --test` clean (834 files).
- `pint` run (3 style issues fixed). `npm run build` OK. `reload.bat` equivalent run and `queue` + `scheduler` restarted on
  the lead's stack.
- Browser (the app's own pane, read and looked at): Status aktivasi (Kesiswaan) at 1280 and 390 px; Admin › Pengguna name
  search ("putri": 2 matches), "Pilih", the reset dialog opened and closed without a reset.
- Siswa › Laporkan hasil on the isolated project `sth-e2e` (its web container published on 127.0.0.1:8091 for the
  check, then `down -v`), as the fictional E2E student: an upload row with a 100-character file name injected into the
  Bukti box stays inside the card at 1280 px (row right 832 px, card 853 px) and 390 px (350 / 370 px), no page scroll
  sideways; with the old classes put back in the page the same row ran to 1280 px. Measured, not looked at (the pane was
  hidden, so no screenshot of that page).
- Not checked in a browser: the TU queue order (tests only), the Humas dialog and panel texts (Livewire tests only), the
  Portofolio lines (Livewire test only).
- Email scenario on `sth-e2e` (`STH_OTP_TEST_MODE=true` on the scenario process, `STH_DEMO_FILES=placeholder` for the
  seed): **147 mails, 0 missing, 1 unexpected, 0 leaks, 0 failed jobs**. The new mails arrived as expected (7a: receipt
  to Galuh, "Laporan hasil dikirim" to Bayu and Laras; 7c: "Perbaikan laporan terkirim" + two "Laporan hasil
  diperbaiki"; 7e: receipt to Wulan; 8b: three "Prestasimu sudah dipublikasikan"; 10o: no mail to Laras, the guru's
  "Binaan masuk daftar apresiasi"). The one unexpected mail is unrelated: step 6b sent the E2E student a reminder
  "Laporan LCT MIPA Kota Semarang 2026 ditunggu" for a demo registration whose fixed final date now lies 3 days back
  (the demo dates drift with the real date, CLAUDE.md §9 RV1 question; reminders were not changed here). Expect it to
  come and go with the date until the demo data uses relative dates.
