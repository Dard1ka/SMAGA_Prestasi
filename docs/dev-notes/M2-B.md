# M2-B — data rules for Kesiswaan and Humas (QA 2026-10-05)  ·  status: done (not migrated, no browser run)

QA findings 4, 7, 8, 9 of `hasil-uji/ringkasan-temuan.md` §1 (Q2-09, Q2-14, Q2-15, Q2-10, Q1-04) and the reject
reason of Q1-18. Lead, 2026-10-05: "Dari ke 12 temuan yang paling penting, lakukan sesuai saranmu".

## Files (owned by me)
- `database/migrations/2026_10_06_110000_m2b_add_student_note_draft_to_talent_applications.php` (new) — one nullable text column
- `app/Actions/Accounts/ReopenActivationWindow.php` · `app/Livewire/Akun/StatusAktivasi.php` + view
- `app/Actions/Talent/{TalentNote,UpdateTalentAssessment,MoveTalentStage,RejectTalentApplication,RestoreTalentApplication,ApplyTalent}.php`,
  `app/Models/TalentApplication.php` (doc only), `database/factories/TalentApplicationFactory.php`, `app/Livewire/Kesiswaan/Talenta.php` + view
- `app/Actions/Publications/RecordAppreciation.php`, new `ReleaseAppreciation.php`, new `app/Notifications/Publications/AppreciationReleased.php`,
  `app/Policies/AppreciationPolicy.php` (`delete`), `app/Livewire/Humas/Apresiasi.php` + `apresiasi.blade.php` + `partials/appreciation-event.blade.php`,
  `app/Livewire/Humas/Publikasi.php` (only `panel()`: the event options)
- `app/Actions/Competitions/{CreateCompetition,UpdateCompetition,VerifyCompetition}.php`, new `CompetitionDates.php`,
  `app/Livewire/Kesiswaan/Lomba/{Index,Form}.php` + both views
- Tests: new `tests/Feature/Kesiswaan/{LombaDatesTest,TalentDraftNoteTest}.php`; changed `tests/Feature/Aktivasi/{AccountControlsTest,StatusAktivasiTest}.php`,
  `tests/Feature/Kesiswaan/{CompetitionActionsTest,KesiswaanScreensTest}.php`, `tests/Feature/Humas/{AppreciationActionsTest,HumasScreensTest,HumasPoliciesTest,HumasNotificationsTest,PublikasiScreenTest}.php`,
  `tests/Feature/Siswa/Talenta/ProfilTalentaTest.php`
- Not touched: `UpdateActivationWindow`, `ActivationReport`, `UnfreezeAccount`, `ProfilTalenta` (it only ever read `student_note`), the talent notifications, seeders, E2E.

## What changed and why
**1. "Buka ulang" never shortens a deadline (Q2-09).** New deadline = the later one of the current deadline and now + 72 h
(`ReopenActivationWindow::deadlineAfter()`). A pending student who is not frozen and whose window already runs longer has
nothing to reopen: the Action refuses ("Batas aktivasi siswa ini masih sampai … Belum perlu dibuka ulang."), the row gets no
button (`wouldChange()`), a stale or forged `openReopen` answers with that sentence as a toast. Button shown for: expired,
frozen after failed logins, pending with less than 72 h left. The dialog says the result before the click ("Dibuka lagi sampai
…", or "Pembekuan dibuka. Batas aktivasi tetap …" when only the freeze is lifted); the button is "Buka ulang" (was "Buka
ulang 72 jam"). Reason: at least 10 characters after trimming (`MIN_REASON`), in the Action and on the screen. Audit
`account.activation_reopened` gained `previous_expires_at`.

**2. Talenta: draft note and a question before "Tidak lolos" (Q2-14, Q2-15).**
- `talent_applications.student_note_draft`: what Kesiswaan typed for the student and kept with "Simpan"
  (`UpdateTalentAssessment`, new key `student_note_draft`; the key `student_note` is still ignored there). Staff only: Profil
  talenta, the guru tab, the export and every notification read `student_note`, which only a decision writes.
- A decision sends its note and clears the draft. Which note: `TalentNote::forDecision()`. The caller passed a text (panel,
  Pulihkan dialog, a typed bulk note) → that text, an empty one = no note. The caller passed none (row check icon, bulk
  "Pindahkan ke …", bulk "Tidak lolos" with an empty note) → the saved draft goes out, and the toast says "… catatan untuk
  siswa yang tersimpan ikut terkirim."
- Panel: the field shows the draft again after a reload, with "Draf tersimpan, belum terkirim." in the hint; toast after
  "Simpan" with a note: "Tersimpan. Catatan untuk siswa terkirim saat kamu menekan Lolos atau Tidak lolos."
- "Tidak lolos" (panel and bulk bar) opens the dialog `tidak-lolos-talenta` (`askReject` / `askBulkReject` → `reject`). Panel:
  name, talent, stage and the note that will be sent. Bulk: the optional note moved into the dialog, which also says how many
  rows have a saved draft. `rejectPanel` / `bulkReject` are private now; `startBulkReject` and `$bulkRejecting` are gone.

**3. One achievement, one open event (Q2-10).** `RecordAppreciation` locks the achievement row and refuses it when it has
any other row: "Prestasi ini sudah dijadwalkan di acara X (5 Okt 2026). Lepas dulu dari acara itu." or "… sudah diapresiasi
di acara X (…)." (a `selesai` row or a confirmed event wins). "Tambah prestasi" lists only achievements on no event;
Publikasi › "Masukkan ke acara" offers no event once the achievement is on one. New `ReleaseAppreciation` ("Lepas dari acara",
per scheduled row of an unconfirmed event): policy `AppreciationPolicy::delete`, lock order event → row, audit
`appreciation.released` (ids, event name and date, `notified`; no names), `AppreciationReleased` to the student after commit
when the account can log in (the rule `DeleteAppreciationEvent` uses). Screen: third mode of the dialog `apresiasi-konfirmasi`
(`confirmRelease` → `runConfirmed`).

**4. Lomba dates (Q1-04).** `CompetitionDates`: "Tanggal mulai tidak boleh sebelum batas daftar." on create (with the other
field errors), on update (a published lomba only when one of the two dates changes, so old rows stay editable) and again at
publish. Publishing a lomba whose registration deadline or final date has passed needs a yes:
`VerifyCompetition::handle(..., bool $confirmPastDates = false)` refuses under the key `CompetitionDates::PAST_KEY`
("Tanggal lomba ini sudah lewat (batas daftar 1 Sep 2026, …). Tetap publikasikan?"); audit `competition.published` gained
`past_dates`. Queue card: "Tanggal sudah lewat: …", "Publikasikan" opens the dialog `lomba-tanggal-lewat` (`publish` →
`publishPast`); a wrong order shows "Perlu diperbaiki: …" instead of the button. Drawer "Periksa lomba": tick box "Tanggal
lomba ini sudah lewat. Tetap publikasikan." (`confirmPast`), asked only by "Simpan & publikasikan". Create and "Simpan" accept
past dates without a question.

**5. Reject reason (Q1-18).** `VerifyCompetition::MIN_REJECT_NOTE = 10` ("Alasan penolakan minimal 10 karakter."), hint under
the field.

## Contracts other areas can use
- `ReopenActivationWindow::deadlineAfter(?CarbonImmutable $current, ?CarbonImmutable $now = null): CarbonImmutable` ·
  `::wouldChange(?CarbonImmutable $current, bool $frozenByFailedLogins, ?CarbonImmutable $now = null): bool` ·
  `::notNeededMessage(User, ?CarbonImmutable $now = null): ?string` · `MIN_REASON`
- `UpdateTalentAssessment` details key `student_note_draft` · `TalentNote::forDecision(?string $given, bool $wasGiven, ?string $draft): array{?string, bool}`
- `MoveTalentStage` (no `student_note` key), `RejectTalentApplication` / `RestoreTalentApplication` (`$studentNote` null) → send the draft; old calls unchanged otherwise
- `ReleaseAppreciation::handle(User, Appreciation): bool` (true = student told) · `COMPLETED_MESSAGE`, `GONE_MESSAGE` ·
  `RecordAppreciation::blocking(Collection): ?Appreciation`, `::blockedMessage(Appreciation): string` · notification kind `appreciation.released`
- `CompetitionDates::startsBeforeDeadline($startsOn, $closesAt): bool` · `::past($closesAt, $finalOn, ?CarbonImmutable $now = null): array<field, label>` ·
  `::pastMessage(array)` · `STARTS_BEFORE_DEADLINE`, `PAST_KEY`
- `VerifyCompetition::handle(User, Competition, CompetitionStatus, ?string $note = null, bool $confirmPastDates = false)` · `MIN_REJECT_NOTE`

## Commands verified (2026-10-06, Docker, in-memory SQLite; four filtered runs side by side, M2-A and M2-C editing the same tree)
Baseline before my change, 9 of these files: 183 passed (1404 assertions).
- `php artisan test --filter="AccountControlsTest|StatusAktivasiTest|LombaDatesTest|CompetitionActionsTest|CreateCompetitionNotifyTest|LombaNameWebAddressTest"` → **114 passed (856 assertions)**
- `… --filter="KesiswaanScreensTest|KesiswaanHardeningTest|ReportVerificationTest|TalentActionsTest|TalentDraftNoteTest|DatabasePrestasiTest"` → **120 passed (727 assertions)**
- `… --filter="ProfilTalentaTest|ApplyTalentTest|RequestTalentReviewTest|SaveTalentProfileTest|TalentPoliciesTest|GuruAreaTest|CspSafeTest|MailInjectionTest|ModelFactoriesTest"` → **167 passed (1007 assertions)**
- `… --filter="AppreciationActionsTest|AutoPublishTest|ConsentWithdrawnTest|GenerateFlyerTest|HumasNotificationsTest|HumasPoliciesTest|HumasRoutesTest|HumasScreensTest|PublicationActionsTest|PublikasiScreenTest"` → **195 passed (990 assertions)**
- Together 596 tests, 0 failed. `./vendor/bin/pint --test` on my PHP files and the four test folders of my area (59 files) → PASS.
- Existing tests changed only because of this work: `KesiswaanScreensTest` (3 talenta cases: `askReject` / `askBulkReject` → `reject`,
  the draft after "Simpan"), `AccountControlsTest` (reasons of at least 10 characters), `CompetitionActionsTest` (fixed "today"),
  `HumasPoliciesTest` (+ `delete`). The first run had 4 red cases, all in test code I had just written (a reopen on an active
  account answers "sudah aktif", and Carbon's Indonesian short month for August is "Agt"); fixed in the tests, then the runs above.

## Assumptions / deviations
- A pending account without any deadline (`initial_password_expires_at` null, only data from before B1) gets no "Buka ulang"
  button; the Action still gives it now + 72 h when called, as before.
- `UnfreezeAccount` (not mine) still accepts a short reason; the shared dialog asks 10 characters only for "Buka ulang".
- A saved draft is sent by the quick actions too (row icon, bulk). The other reading, "only the panel sends notes", would
  drop or keep a saved draft without a word, which is the finding itself. The bulk "Tidak lolos" dialog names the drafts.
- No shared constant for the two minimum lengths: they sit in different areas (`Accounts`, `Competitions`); both are 10.
- "Tidak lolos" from the panel checks the fields before the dialog opens, so the dialog never opens over a field error.
- `CompetitionActionsTest` and `LombaDatesTest` fix "today" at 6 Oct 2026: `KesiswaanFixtures::form()` has fixed dates, and
  publishing now depends on the clock.

## Requests for other owners
- Coordinator: `migrate` (one column, no new table, so no grant); then `reload.bat` and `npm run build` with the others (I added no class on purpose, but did not build to prove it). Until the column exists Kesiswaan › Talenta fails on the dev DB.
- `DemoSeeder`: nothing must change. Optional: one pending student with less than 72 h left and one talent row with a `student_note_draft`, so both cases show in the demo.
- `tests/E2E/support/email-scenario.php`: still valid as written. Optional new step after 8b: `ReleaseAppreciation` → mail "Jadwal apresiasi berubah" to the student (then record again for the later steps).
- `Admin\AuditLog` label map: `appreciation.released`; new properties `previous_expires_at` (`account.activation_reopened`), `past_dates` (`competition.published`), `student_note_from_draft` (`talent.stage_changed`, `talent.rejected`).
- Docs: ARCHITECTURE §4 (`talent_applications.student_note_draft`), §8b (new row: appreciation released → the student who can log in), §8a/K2 text (reopen = later of current and now + 72 h, reason ≥ 10); UI-MAP #16, #18, §4a, §4d; PANDUAN-PENGGUNA (Kesiswaan: Buka ulang, draf catatan, Tidak lolos; Humas: Lepas dari acara; lomba dates); DECISIONS: four new entries (reopen never shortens; draft note for the student; one achievement on one open event + release; lomba date order and the past-date confirmation).
- Other reason fields of Q1-18 (reject registration, reject / revise a report, unfreeze) are outside my files: same minimum there is the user's call.

## Not verified
- No browser run: the three new dialogs (`tidak-lolos-talenta`, `lomba-tanggal-lewat`, the release mode of `apresiasi-konfirmasi`), the tick box in the drawer, widths at 1440 / 390.
- The migration ran on SQLite only (tests), not on MySQL 8.4. It is a plain `ALTER TABLE … ADD`.
- The dev DB has the QA leftovers (FIKSI 2026 on two events, one confirmed): the row on "Upacara Senin, 5 Oktober 2026" can now be released by hand; no data migration was written for it.
- Full suite not run (only my areas, by instruction).
