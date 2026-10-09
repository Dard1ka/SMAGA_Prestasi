# QA9 — explore round after EM1 (2026-10-09)  ·  status: committed on local `main` (`e80c9d2`), not pushed

The lead pulled the teammate's `feat/email-brevo-dev` (EM1, D-121, one commit on top of PR 18) into the local `main`
and asked to "lanjutkan explore dan fix bug lagi" there, without a PR for now. Local `main` = `origin/main` (`53614e1`)
+ `4766b56`; the merged state was checked first: the local `.env` passes the new mail boot guard (smtp to Mailpit, no
login), the stack and workers boot, full suite **2426 passed, 1 skipped (14259 assertions)**.

Five read-only agents explored (the teammate's allowlist mailer; the "stale screen → full-screen 403/404" pattern of
QA8 on every other screen; the Admin area; Talenta and Guru; public pages and consent). Every finding was checked in the
code by the coordinator before four implementer agents fixed them in parallel, each on its own files, test-first (red
seen, then green). Fictional data only; no migration; no new decision (the fixes follow D-29, D-30, D-38, D-63, D-95,
D-109, D-119, D-121 and SECURITY §3m-9). Open choices are listed for the lead at the end.

## The pattern behind most fixes (stale screens)
A Livewire action on a record another tab, another account or a scheduled job may have changed meanwhile now checks
the state the screen SHOWED before it calls the policy or the Action. When the record moved on, the user gets a warning
toast that says what happened and that the list was refreshed, and nothing is written; a record the user may never see
still answers 403/404 (no IDOR through the toast path). How a screen remembers what it showed: a `#[Locked]` property
filled in `render()` (Kepala Sekolah › Persetujuan `$shown`, Kesiswaan › Talenta `$shownStages`), the record's own
state (Kelola lomba, Surat tugas, Humas, Arsip), or the audit row of the change (Apresiasi: `appreciation.event_deleted`;
Undangan tim: `registration.invitation_withdrawn`, so a reload or an old notification link also gets the friendly page).

## What changed (finding → fix)
**Data and privacy**
1. **Nightly archive turned a hand-disabled student into a named public alumnus** (found by two agents). The job only
   skipped archived accounts: a disabled student (hidden on public pages) became `archived` on its date, the public
   cache was flushed, and name, achievements, Alumni/Semua ranks and the old `/prestasi/siswa/{slug}` came back; "Pulihkan"
   could even make the account able to log in. `Student::scopeDueForArchive` takes active and pending accounts only and
   `ArchiveDueStudentAccounts` re-checks under the lock; the Arsip "Segera" list and the "malam ini / 30 hari" counts
   (Arsip otomatis, Batas arsip, Ringkasan) follow the same rule.
2. **Kesiswaan › Talenta moved a row twice from a stale tab**: the screen computed the target from the fresh status, so
   `MoveTalentStage`'s `from->next() !== to` guard never fired; tab B's "Lolos ke Pemetaan" moved the row on to Seleksi
   and told the student again. The screen now moves (and rejects) only from the stage it showed (`$shownStages`); bulk
   moves skip and count such rows.
3. **The student's "Minta ditinjau ulang" text landed in the sealed audit log** (`TalentApplication` logs every dirty
   column; the Action itself logged only `has_message`, D-63). `review_message` is excluded from the model log.
4. **A mail sent BY NAME bypassed the allowlist mailer** (EM1): the boot guard only checks the default mailer, and in
   allowlist mode `smtp` holds the provider login; a mailable queued while `MAIL_MAILER=smtp` (Mailpit) remembers "smtp"
   and would go straight to the provider after the switch (`queue:retry`, tinker). `App\Mail\LocalMailGuard`: one shared
   predicate for the boot guard and a `MessageSending` listener that, in `APP_ENV=local` only, cancels such a mail and
   logs `mail.local_mailer_blocked` with the mailer name (never a recipient). Mailpit, the allowlist, log and array are
   untouched.
5. **A disabled or deleted teammate's certificate stayed public** (`files.public`): `FilePolicy::viewPublic` now asks
   the "named" rule of the public pages for every member (consent, not deleted, not disabled; alumni stay allowed), and
   the answer carries `X-Robots-Tag: noindex, nofollow`.
6. **Staff "Reset akun" left a frozen account frozen** (the student reset cleared it): cleared and logged `unfrozen`.
7. **Resetting a disabled or archived account** said "masuk dengan … kata sandi ini" although login stays refused: both
   Actions refuse it with a clear sentence; the screens show that sentence instead of the button.

**Stale screens (full-screen 403/404 → warning toast + refresh)**
8. Kepala Sekolah › Persetujuan approve / reject (the ketua cancelled, or decided on the phone); bulk approve counts
   the rows decided or cancelled meanwhile instead of "Pilih pendaftaran dulu.".
9. TU › Surat tugas "Koreksi" of a letter another tab already corrected.
10. Kesiswaan › Kelola lomba publish / reject / close / edit, and the drawer, after another account decided the lomba
    (the drawer keeps what was typed when the lomba was published meanwhile).
11. Humas › Publikasi publish / schedule after the auto-publish job or another tab; a publication whose consent was
    withdrawn meanwhile leaves the queue with a toast.
12. Humas / Kesiswaan › Apresiasi after a colleague deleted the event.
13. Siswa › Undangan tim after the ketua withdrew the invitation (a page that says so, also on reload).
14. Siswa › Daftar lomba "Kirim" after the lomba was closed; Siswa › Lomba saya "Laporkan hasil" after the report was
    sent in another tab (QA8 had left this one).
15. Kesiswaan › Talenta: a choice the student cancelled meanwhile (404 before), and "Tidak lolos" from a stale tab.
16. Siswa › Profil talenta "Batalkan" / "Minta ditinjau ulang" after Kesiswaan moved or restored the choice.
17. Admin › Permintaan peran, Ganti Kepala Sekolah (cancel) and Arsip otomatis (date, restore) after another Admin or a
    scheduled job acted; approving or cancelling a role change now also refreshes the staff table.

**Honest messages and counts (the D-109 rule: never claim a notice nobody got)**
18. Talenta toasts and dialog lines say "Siswa diberi tahu" only when the student was told (`notifiesStudent()` on
    `MoveTalentStage`, `RejectTalentApplication`, `RestoreTalentApplication`); bulk counts "untuk 1 dari 2 pilihan".
19. "Ubah peran" to the role the account already holds said "Peran diubah"; now "Akun ini sudah memegang peran itu."
20. Talenta "N hasil sesuai filter" showed the rows on screen, not the matching total.
21. Guru › Siswa binaan "Pembinaan talenta" counted rows (incl. Tidak lolos) as students: now distinct students whose
    choice with this guru is not rejected.
22. Admin › Ringkasan "Aktivitas Admin terbaru" was filled by the nightly archive rows: those stay out (a new Admin made
    with `sth:create-admin` on the server, which has no causer either, stays in).
23. A manual archive showed its future `archive_at` as "Diarsipkan": the date of the `account.archived` audit row is
    shown (display only).

**Rules that were not applied**
24. Import siswa had no recent-password check (SECURITY §3m-9). The password is asked in step 2 BEFORE the upload
    (the password page does not keep an uploaded file), and `import()` checks again.
25. "Buka pembekuan" could shorten the activation window: it uses `ReopenActivationWindow::deadlineAfter()` (D-95).
26. A disabled pembina (or one who left) blocked every Talenta panel button: the unchanged current pembina is accepted
    and the panel says he is no longer active.
27. Editing a published lomba did not flush the public cache (old name / bidang up to ~20 min): `UpdateCompetition` flushes.
28. The temporary password stayed in the component state after closing its dialog with X / Esc: `on-close` clears it.
29. Staff copy "Akunmu belum punya peran" (D-119): "Akun ini belum punya peran …" (also PANDUAN-PENGGUNA).
30. Admin › Log audit "Pelaku" missed a replaced Kepala Sekolah and role-less accounts.
31. The Talenta export could duplicate or drop rows past 500 (no tie-breaker): `orderBy('id')`.
32. `.env.example`: the Brevo block broke phpdotenv when uncommented as documented (text after the values, spaces in
    the placeholders); a Bcc case was added to the allowlist tests (the code was right; guard test).

## Review of the combined change
A read-only review agent read every changed file and new test against CLAUDE.md, the policies, the vendor mailer and the
activity_log schema: **no high-confidence problem** (no 403/404 turned into a toast for a record the user could not see,
no toast path that does part of an action, no double notice, no Pest helper name clash, copy and CSP rules kept). Two
small points were fixed test-first by the coordinator: the bulk "Tidak lolos" dialog asked the database twice per
checked row on every redraw (`MoveTalentStage::studentToTell()` now uses the loaded relation and
`Talenta::checkedApplications()` loads `student.user` at once; test: 6 vs 2 checked rows cost the same queries), and the
Log audit "Pelaku" list counted a student whose student record was deleted as staff. Points below its threshold are in
"Requests for the lead" (the two Talenta paths into Peserta pembinaan now disagree about an inactive pembina).

## Browser check (the app's browser pane, demo Kesiswaan account, after the lead allowed the agent to log in)
- Import siswa: step 2 shows "Impor mengubah data akun siswa, jadi kata sandi diminta dulu." and the button
  "Konfirmasi kata sandi", no file field; the button opens the password page. Typing the password there was blocked by
  this session's safety check, so "upload field after the confirmation" is covered by the tests only.
- Talenta, two tabs (the lead's yes for one real move on demo data): tab 1 row icon "Lolos ke Seleksi/asesmen" on Galang
  Putra Mahendra's OLPAIN choice → "Dipindahkan ke Seleksi/asesmen."; tab 2 (still showing Pemetaan), same icon → the
  orange toast "Pilihan ini sudah dipindahkan ke Seleksi/asesmen. Daftar sudah diperbarui." and the row stays in
  Seleksi/asesmen. The database: status `seleksi`, exactly one `talent.stage_changed` row and one notice that day.
  Talenta page console: no error.
- Local data changed by that check: Galang Putra Mahendra's OLPAIN choice is in Seleksi/asesmen (it was Pemetaan).
- Not checked in a browser: the other roles' screens (covered by the tests).

## Files (production)
`app/Models/{Student,TalentApplication}.php`; `app/Actions/Users/{ArchiveDueStudentAccounts,ResetStaffAccount,ChangeStaffRole}.php`,
`app/Actions/Roles/RequestRoleChange.php`, `app/Actions/Accounts/{ResetStudentAccount,UnfreezeAccount}.php`,
`app/Actions/Talent/{CancelTalentApplication,MoveTalentStage,RejectTalentApplication,RequestTalentReview,RestoreTalentApplication,UpdateTalentAssessment}.php`,
`app/Actions/Competitions/UpdateCompetition.php`; `app/Mail/LocalMailGuard.php` (new), `app/Providers/AppServiceProvider.php`,
`app/Policies/FilePolicy.php`, `app/Http/Controllers/FileController.php`; Livewire: `Admin/{ArsipOtomatis,AuditLog,Ringkasan,RoleChangeRequests}`,
`Admin/Pengaturan/{Pengguna,BatasArsip}`, `Akun/ImportSiswa`, `Kepsek/Persetujuan`, `TataUsaha/SuratTugas`,
`Kesiswaan/{Talenta,Lomba/Index,Lomba/Form}`, `Humas/{Publikasi,Apresiasi}`, `Guru/Binaan`,
`Siswa/{KonfirmasiTim,ProfilTalenta,Pendaftaran/Form,LombaSaya/Index}` and their views; `routes/web.php` (copy);
`.env.example` (mail block); docs: this note, CLAUDE.md, EM1.md, PANDUAN-PENGGUNA.
Tests: 18 new `*Qa9Test.php` files (Accounts, Admin, Admin/Arsip, Admin/RoleChange, Auth, Files, Guru, Humas,
KepalaSekolah, Kesiswaan ×2, Mail ×2, Siswa ×2, TataUsaha) plus `AllowlistMailerTest` (Bcc); existing tests changed
only where the behaviour changed on purpose (AuthPages copy, AccountAdminActions archived reset, ProfilTalenta 403 →
toast, three import tests now confirm the password first).

## Commands verified
- RED: every behaviour test failed for the missing behaviour first (the 403/404, the double move, the message in the
  log, the mail reaching the provider transport, the 200 for a disabled teammate's certificate, …); guard tests that
  passed before and after are named in the implementers' reports (Bcc, the own-certificate case).
- GREEN per area (one process): Talenta/Guru 204 passed; Admin/accounts 138 + 358 related passed; stale screens 45 + 673
  related passed; mail/files 258 passed; import screen 112 + 7 passed after the password-first change; after the two
  review fixes the 17 Talenta / audit test files: 268 passed.
- `pint --test` → PASS (689 files).

## TEST-RESULTS
- Merged local `main` before any change: 2426 passed, 1 skipped (14259 assertions), exit 0 (1309 s).
- After the four implementers (one RED test of the review added during the run): 2575 passed, 1 failed (that RED
  test), 1 skipped (14962 assertions), 1344 s.
- After QA9 incl. the review fixes: `php artisan test --parallel --processes=4` → **2577 passed, 1 skipped (14963
  assertions)**, exit 0 (1193 s; +151 = the QA9 tests); `pint --test` → PASS (689 files). No front-end change, so no
  `npm run build`.
- Not run: the email scenario and the Playwright smoke (backlog #46); the browser beyond the Kesiswaan checks above;
  real mail through Brevo.

## Requests for the lead
- **Re-enable:** there is no "Aktifkan lagi" for a disabled account, and a reset of a disabled account is now refused,
  so a mistaken disable cannot be undone in the app. Add an audited action?
- **Restore after a wrong archive:** `UnarchiveStudent` sets active/pending even for an account that was disabled before
  the old job archived it (the local demo database may hold such rows: `account.archived` with `automatic: true`).
  Should "Pulihkan" bring such an account back as disabled?
- **Talenta:** moving FORWARD into Peserta pembinaan still works without a guru pembina (D-109 only covers the restore),
  and with fix 26 also with an unchanged pembina who left; should alumni rows stay in the pipeline at all?
- **Points:** `PointFormatter` shows at most one decimal (by design, §6), so 12,75 and 12,80 both read 12,8 but rank
  differently, and Portofolio rounds a 62,5 tingkat weight to 63 while public pages show 2 decimals.
- **Winners per lomba:** labels come from the live lomba, "N poin per siswa" from the first achievement; after a tingkat
  change of a lomba with verified results the header and the points can disagree.
- **Smaller notes:** the "named" rule now lives in `PublicAchievements::mapRow()` and `FilePolicy::appearsByName()`
  (one helper would be cleaner); a queued mail cancelled by `LocalMailGuard` ends its job as a success (only the log
  shows it); "Sudah diarsipkan" still sorts by `archive_at`; a Talenta stage counts as "shown" as of the last redraw;
  an import more than 15 minutes after step 2 asks again and the file is uploaded again; in Kelas mode a class with one
  achiever can be matched to the anonymous "Siswa kelas …" row; DECISIONS D-61 still quotes "Akunmu belum punya peran".
