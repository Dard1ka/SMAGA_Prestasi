# FS1 — feedback sekolah of 2026-10-12  ·  status: all five packages built (A, D, C, E, B; not pushed)

Two lists of feedback from SMAGA, pasted by the lead on 2026-10-12, are applied as one wave (FS1) in five packages. The design
is `2026-10-12-feedback-sekolah-fs1-design.md` (outside the repo, in the lead's workspace `specs/` folder); the decisions are
in `docs/DECISIONS.md` §D15 (D-135, D-136 for package A; D-137, D-138 for package D; D-139…D-141 for package C; D-142…D-144
for package E; D-145…D-151 for package B). Order of the packages: **A → D → C → E → B**; each one gets its own plan and its
own `## Paket X` section in this file; the last section, **FS1 overview**, maps the 30 feedback items and holds the one
deploy checklist for the whole wave. Branch `feat/fs1-feedback-sekolah` (from `fix/em2-uji-email` = EM2 + RV1, merged with
`origin/main`), not pushed. Fictional data only.

## Paket A — teks dan tampilan (D-135, D-136)

No migration, no new permission, no new route. Route names, URLs, menu keys and badge keys are unchanged.

### Built
1. **Pendaftaran lomba** (`5adbba2`): the panel title on Siswa › Detail lomba (`livewire/siswa/pendaftaran/form.blade.php`) and the
   Bantuan card and sentence say "Pendaftaran lomba"; test `Fs1PendaftaranLombaTest`.
2. **Verifikasi usulan lomba** (`f3ce1c2`): the queue has one name everywhere: Kesiswaan menu (`Navigation`), tab, Beranda tile and
   card heading, the stat tile and its note ("menunggu diperiksa") on Kelola lomba, the subject and button of
   `CompetitionSubmitted`, and the Kepala Sekolah dashboard sentence ("Verifikasi usulan lomba: N belum diperiksa"). The sidebar
   label may wrap (`min-w-0 flex-1 leading-snug`; `.nav` is `min-height: 44px; padding: 6px 12px`). Tests `Fs1VerifikasiUsulanTest`,
   `DashboardTest`, and the old strings in `MailInjectionTest`, `ComponentsTest` and `tests/E2E/support/email-scenario.php`.
3. **Database talenta siswa** (`e8a4b12`): menu, `#[Title]`, subtitle "Data talenta pilihan siswa.", the Excel title row, the
   large-export notice label and the action of `TalentReviewRequested` ("Buka Database talenta siswa"); test `Fs1DatabaseTalentaTest`.
4. **Excel without Kategori** (`3662216`): `ExportAchievements::HEADER` and `dataRow()` lose the column (23 columns, "Total poin"
   under Poin, padding `range(3, 20)`); Bobot penyelenggara stays; the drawer may still show the category.
5. **Admin menu "Database prestasi"** (`6e34904`): new item in the Admin Data section → `kesiswaan.prestasi.index`, like "Kelola lomba";
   test `Fs1AdminPrestasiMenuTest` (item, current marker, forbidden roles).
6. **Red count badges** (`2d50971`): `.badge` (`app.css`), the `badge` component and the bell bubble in `topbar` use `--color-red`
   `#B42318` with white text; `.cnt` stays gray, the unread marker stays navy; the D-127 script only changes text, so the colour
   cannot be overwritten; test `Fs1BadgeColorTest`.
7. **Pending lomba oldest first** (`d1d17e8`): the catalog bucket "Menunggu verifikasi" of Kelola lomba orders by `created_at`
   ascending (`Kesiswaan\Lomba\Index`); the queue stays `oldest()`; test `Fs1KatalogUrutanTest`.
8. **Docs** (this commit): DECISIONS §D15, CLAUDE §2/§7/§8/§10, DESIGN-SPEC-v3 §2/§4, UI-MAP §4/§5/§6h, PANDUAN-PENGGUNA, this file.

### Interpretations (the strings marked *sementara* in D-135, chosen by the coordinator)
- "menunggu diperiksa" as the note of the stat tile and the Beranda tile; "Verifikasi usulan lomba: N belum diperiksa" for the
  Kepala Sekolah dashboard; the button "Buka Database talenta siswa".
- The Beranda card heading was renamed too ("Verifikasi usulan lomba"), although the feedback only named the menu.
- A long sidebar label wraps to two lines instead of being cut off (the `truncate` is gone); an item is 44 px high as before
  while it fits one line.
- The bell title "Usulan lomba baru" (the notification itself) is kept: it names the event, not the queue.
  *(2026-10-14, browser QA, BUG-LOMBA-08: the bell title now follows the mail, "Verifikasi usulan lomba: <nama lomba>";
  the body "<nama> mengusulkan <nama lomba>." stays.)*

### Not changed on purpose
- The tracker phase "Daftar" (`RegistrationStatus::phaseLabel(1)`) and the tab-list `aria-label="Daftar lomba"` of the catalog
  (it means "list of lomba").
- The auth tagline, and the page title "Kelola lomba" (that page also holds the catalog).
- `.cnt` (tab and lane counts, gray) and the unread row marker in Notifikasi (navy).
- The file name `talenta-siswa-*.xlsx` of the Excel download.
- The queue alert text "Semua upload lomba …" and the demo wording in `resources/views/dev/components.blade.php` (stat tile).

### Commands verified
TEST-RESULTS (2026-10-12, on `feat/fs1-feedback-sekolah` after Tasks 1–7):
- `docker compose run --rm --no-deps app php artisan test --parallel --processes=4` → **3466 passed, 1 skipped (21422 assertions)**,
  exit 0, 1889 s (the RV1 baseline was 3439 passed, 1 skipped: +27 tests).
- `./vendor/bin/pint --test` → pass, 763 files.
- `docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` → Vite build OK, no CSS error (`app-*.css` 103.9 kB).
- Not run: `composer audit`, the Playwright smoke, the email scenario.

### Browser check
Local stack (`docker compose up -d`, `reload.bat`), demo accounts `kesiswaan`, `kepsek`, `operator`; the Browser pane was hidden,
so everything was read from the page (text, computed styles, positions), not looked at in a screenshot. Three EM2 migrations
(`organizer_type_other`, `reminded_at`, `held_time`) were still pending on this local MySQL and were run with `php artisan migrate`.
Two lomba of fictional data ("FS1 cek urutan …", 10 and 2 days old) were made to see the order and the dashboard sentence and were
rejected afterwards (two `ditolak` rows stay in the local catalog; their audit rows too).
1. Siswa › Detail lomba and Bantuan: **not checked in a browser** (no student login: the password pattern is not typed by hand);
   covered by `Fs1PendaftaranLombaTest` and `SiswaScreensTest`.
2. Kesiswaan sidebar at 1366 px and in the phone drawer at 390 px: "Verifikasi usulan lomba" and "Database talenta siswa" whole on one
   line (every item 44 px, nothing cut). A forced long label wraps to two lines (53 px), is not cut, and keeps its badge inside.
   The badge is red `rgb(180, 35, 24)` with white text, also with two digits (88) and "99+", and it keeps the colour after the
   `sth-badges` event (what D-127 sends); "Publikasikan" itself was **not** clicked (it would publish a lomba), the event was
   dispatched by hand. Siswa sidebar: items 44 px in `/_dev/components?role=siswa` (the showcase, not a student account); the menu
   tour spots were not measured.
3. Kesiswaan › Beranda: tile and card "Verifikasi usulan lomba" (tile note "menunggu diperiksa").
4. Kesiswaan › Kelola lomba: stat tile, tab and catalog; "Menunggu verifikasi" lists the 10-day lomba before the 2-day one, although
   the 2-day one was created first. At 390 px the tile text wraps to two lines inside its tile, no sideways scroll.
5. Kesiswaan › Database talenta siswa: title, subtitle "Data talenta pilihan siswa.".
6. Admin (`operator`): Data › "Database prestasi" opens `/kesiswaan/prestasi` with the Admin menu and the item `aria-current="page"`.
7. Bell: red bubble; "99+" is 37 px wide and fits. Akun › Notifikasi (`/notifikasi`): unread markers navy `rgb(27, 42, 78)`,
   the two `.cnt` counts gray `rgb(232, 235, 240)` on `rgb(58, 66, 84)`.
8. Kepala Sekolah › Dashboard "Perlu perhatian": "Verifikasi usulan lomba: 1 belum diperiksa" (with the 10-day lomba).
9. Mailpit: a `CompetitionSubmitted` notification sent to `kesiswaan@sth.test` arrived with the subject "Verifikasi usulan lomba: FS1 cek
   urutan lama (10 hari)" and the button "Verifikasi usulan lomba" (sent by tinker, not by a student's form; stays in Mailpit).
10. Excel from Database prestasi: made with `ExportAchievements` as Admin inside a rolled-back transaction (no audit row kept) and
    read back: 23 columns, no Kategori, the "Total poin" row has the sum (3023) in the Poin column. Not downloaded through the button.
Viewport emulation was reset to desktop at the end.

### Follow-ups
- The email scenario (`tests/E2E/support/email-scenario.php`) was edited for the new subject and button (eight places) but **not run**.
- Siswa screens (Detail lomba panel title, Bantuan card, the Siswa sidebar on a real student account) were checked by tests and
  in the `/_dev/components?role=siswa` showcase only, not on a signed-in student account.
- Two rejected "FS1 cek urutan …" lomba (fictional) remain in the local database of the stack used for the browser check.
- Paket D, C, E and B have their own `## Paket X` sections below.

## Paket D — akun (D-137, D-138)

Students log in with the NISN only and see the NISN instead of the NIS; every student gets the same initial password from
config and must replace it at the first login (no "Lewati"). No migration, no new permission, no new route. Commits (branch
`feat/fs1-feedback-sekolah`, not pushed): Task 1 `0ad21ae`, 2 `91efa4c` + `e95ffdd`, 4 `cfb6b40`, 5 `17750bf`, 6 `49ba94f`,
7 `9b4cabf`, 8 `92c443d` + `cc3473d`, tests `1b7bc55`, docs = the commit that adds this section.

### Built
1. **Login by NISN** (Task 1): `LoginIdentifier::resolveStudent()` looks up `students.nisn` only; the "ambiguous" branch is gone; a
   number that matches only a NIS gets the generic error (dummy hash, HMAC bucket). Copy: login ("Pakai NISN dan kata sandi dari
   sekolah.", label "NISN atau email"), forgot password, activation, Bantuan, `lang/id*`, `validation.php` attribute.
2. **Initial password from config** (Task 2): `config/sth.php` `initial_student_password` (env `STH_INITIAL_STUDENT_PASSWORD`);
   `InitialPassword::make()` returns it, `matches()` knows it and the old birth-date pattern (K7). Used for import and the demo
   seed; never shown or logged. K7 now also fires for accounts without a birth date.
3. **Never log it** (Task 3): `SecurityEventSubscriber::identifier()` writes only a 10-digit NISN or an email as typed, and a
   10-digit value equal to the initial password is fingerprinted like everything else; the email scenario leak scan watches for it.
4. **Refused as a new password** (Task 4): `PasswordPolicy` takes `InitialPassword` and refuses an equal value (after the
   usual normalisation). D-94 and the minimum of 10 are unchanged.
5. **No "Lewati"** (Task 5): `CompleteActivation::keepPassword()`, `mayKeepPassword()` and `MSG_MUST_CHANGE` deleted (not "always
   false"); step 3 is "Buat kata sandi baru" for everyone; `EVENT_PASSWORD_KEPT` stays for old audit rows.
6. **Import** (Task 6): the cross-check "NIS sudah dipakai sebagai NISN siswa lain" is gone; NIS stays required and unique, D-81
   matching still uses NIS and NISN; the "rows to check" file and table show the NISN.
7. **NISN on screens and files** (Task 7): Status aktivasi (list, popup, CSV), Database talenta siswa (row, panel, Excel), Database
   prestasi (row, detail, Excel), Profil talenta. The Status aktivasi CSV gets a hint line next to "Download CSV" (open it in Excel
   through Data > From Text/CSV and set the NISN column to Text, so a leading zero stays).
8. **Search** (Task 8): `Student::scopeSearch`, `AchievementFilters`, `ActivationReport`, Admin Pengguna (10 digits), Arsip otomatis
   and the member search of Pendaftaran lomba take a name or a NISN; placeholders "Nama atau NISN" and, in the Admin top bar,
   "Cari nama atau NISN". New `StudentSearchTest`.
9. **Docs** (this commit): DECISIONS D-137/D-138, SECURITY (banner, §0, §1, §3a K1/K4-K7/K11/K12 + block "Keputusan sekolah 12 Okt 2026",
   §3f, §3i, §3l), CLAUDE (§1, §2, §3.3, §5, §7, §8, §9, §10), ARCHITECTURE, AGENTS, UI-MAP, PANDUAN-PENGGUNA, README, `.env.example`,
   `docker/env.production.example`. Docs never write the default password; they say "the default in config/sth.php".

### Interpretations (coordinator choices to confirm with the lead)
- `AuthenticateUser::GENERIC_ERROR` and `lang/id/auth.php` now read **"NISN atau kata sandi salah."** (it was "NIS/NISN atau kata
  sandi salah."). It stays one answer for every failure (wrong password, unknown NISN, a NIS, an unfinished-activation email).
- **Admin reset stays random**: the spec lines "reset Admin" and "Admin memakai reset yang sudah ada" do not hand out the configured
  value. The Admin reset gives a random 12-character temporary password, as before. No tool moves an already-imported account
  to the new initial password.
- The member search of Pendaftaran lomba and Arsip otomatis moved to the NISN as well (the spec named "semua placeholder").
- `keepPassword()`/`mayKeepPassword()` are deleted rather than "always false"; the audit constant stays for old rows.
- "The policy check is equality": `PasswordPolicy` refuses the initial password only when the new password equals it (after
  normalisation), not when it merely contains it.

### Follow-ups for the lead
- **(a)** The Status aktivasi download stays a CSV, with the hint line. An .xlsx file (cells typed as Text) would keep the leading
  zero without a hint: an option if the school finds the hint too fiddly.
- **(b) Hardening suggestion:** `config/sth.php` reads `env(...) ?: default`, so a value that is only whitespace, or padded with
  spaces, is used as typed. A whitespace-only `STH_INITIAL_STUDENT_PASSWORD` would make `InitialPassword` throw on every
  login (K7 asks `matches()` on each failed login) and a padded 10-digit value would be written to the security log as typed
  by a student who copies it. Trimming the env value (`trim(...) !== '' ? trim(...) : default`) in the config or in
  `InitialPassword` is the suggested fix; not done here.
- **(c)** No tool moves already-imported accounts to the new initial password (Admin reset gives a random temporary one). A mass
  command is built only if the school asks.
- **(d)** The `GENERIC_ERROR` text change is a coordinator choice; confirm it with the lead.
- Kepala Sekolah has to sign the updated risk-acceptance form (D-14, risks D-79 and D-138) before go-live.
- Email scenario: its edited steps were not part of the full run below unless the TEST-RESULTS line says so.

### TEST-RESULTS (2026-10-13, on `feat/fs1-feedback-sekolah` after Tasks 1-8 and the docs)
- `docker compose run --rm --no-deps app php artisan test --parallel --processes=4` -> **3486 passed, 1 skipped, 1 failed (21371 assertions), 1832 s; the one failure was my own test cleanup (`LoginLockoutQa10Test` used a constant defined in another test file, absent when the file runs in its own worker), fixed in `9439eaf`; the file then passes alone (10 passed, 662 assertions) and `pint --test tests/Feature/Auth` passes; the whole suite was not run a second time**.
- `./vendor/bin/pint --test` -> PASS, 765 files.
- `docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` -> `built in 13.06s`, exit 0.
- Not run: `composer audit`, the Playwright smoke, the email scenario (DEPLOY §18).

### Browser check (2026-10-13)
Local stack (`docker compose up -d`, `docker/php/dev-cache.sh`), read from the page with the built-in browser (text, positions,
DOM), no screenshots looked at. Demo staff accounts only (`kesiswaan`, `operator`; README); no student was logged in or
activated and no account, password or setting of the local database was changed (a failed login for a fake NISN only
bumps a rate-limit counter).
1. Guest `/login`: "Pakai NISN dan kata sandi dari sekolah.", label "NISN atau email", hint "Sudah aktivasi? Bisa juga masuk pakai email.
   Guru dan staf: username atau email.", "Baru pertama masuk? Nanti diminta menghubungkan email dulu, lalu membuat kata sandi baru."
2. Guest `/lupa-kata-sandi`: label "NISN atau email", "Guru dan staf: username atau email."
3. A fake NISN with a wrong password: "NISN atau kata sandi salah."
4. Kesiswaan `/kesiswaan/aktivasi` (Status aktivasi): rows read "X-3 . 0101482657" (class . NISN), the CSV hint line is under "Download CSV", the search
   placeholder is "Nama, atau NISN lengkap", a search for a fictional NISN finds the row, the class popup ("Lihat" on XI-4) has the column "Siswa, NISN, email"
   and "NISN 0098765432"; email masked; the word "NIS" does not appear.
5. Kesiswaan Database talenta siswa: placeholder "Nama atau NISN", `?q=` with a NISN finds the student, the panel reads "XI-4 . NISN 0098765432".
6. Kesiswaan Database prestasi: placeholder "Nama atau NISN", search by NISN finds the three rows, the detail drawer has a "NISN" row; the word "NIS" does not appear.
7. Admin `/admin`: the top bar search placeholder is "Cari nama atau NISN"; Admin Pengguna & peran asks "NISN" (10 digits) for the student field and the staff search keeps "Cari nama atau nama pengguna".
8. Both staff accounts were logged out at the end; the viewport emulation was cleared (it was never set: the checks ran at the pane's own width, so no phone width was seen).
Not browser-checked (covered by feature tests): the student screens (activation step 3 copy and header "NISN ... . kelas", the Profil talenta NISN line,
Paket A's Pendaftaran lomba panel and Bantuan), the Excel and CSV files as downloaded files, the import dialog with a one-row file, a 9-digit NIS in the login field
(covered by `LoginTest`), and the phone width.

## Paket C — verifikasi hasil (D-139, D-140, D-141)

Humas verifies laporan hasil next to Kesiswaan on the same screen under its own addresses and becomes a privileged role; the
screen offers only "Verifikasi dan beri poin" and "Minta revisi"; new reports are mailed to Kesiswaan and Humas and the texts name
the role that decided; every appreciation event gets "Cetak daftar". Plan: `2026-10-12-fs1-c-verifikasi-hasil-plan.md` (lead's
workspace `plans/`), design §4. No migration. One permission grant (`report.verify` for `humas`), three routes
(`humas.verifikasi.index`, `humas.verifikasi.show`, `apresiasi.cetak`), one sidebar key (`humas.verifikasi`), one dialog removed
(`tolak-laporan`). Commits (branch `feat/fs1-feedback-sekolah`, not pushed; each task reviewed by a fresh reviewer, all clean):
Task 1 `e8b2748`, 2 `771935d`, 3 `578ccf2`, 4 `e639113`, 5 `398560f`, 6 `8a23afa`, 7 `f13835a`, docs = the commit that adds this
section.

### Built
1. **`App\Support\StaffRoleLabel`** (Task 1): `of(User)` = the label of the highest staff role by `PRIORITY` (`kepala_sekolah`,
   `tata_usaha`, `kesiswaan`, `humas`, `admin`, `guru`; '' without one), `forDecision(?User)` = that label or `FALLBACK` "sekolah".
   Pure and static; Paket B reuses it. Test `StaffRoleLabelTest`.
2. **Humas holds `report.verify` and is privileged** (Task 2): `RoleName::defaultPermissions()` (humas), `PrivilegedRoles::all()`
   appends Humas (docblock: an account that is Humas already keeps role and access); the Admin hints in Pengguna & peran (create
   form, "Ubah peran") and Perubahan peran name Humas. As a consequence `ReportPolicy::viewQueue/view/verify`,
   `FilePolicy::viaReport` (evidence files) and `HumasQueries::badges()` answer for Humas. New cases in `SeedersTest` and
   `RoleChangeRequestTest` (a second Admin before an account becomes Humas, taken away at once; an existing Humas account needs no
   new approval and keeps `report.verify`); changed cases in `RoleChangeRequestTest`, `PrivilegedStaffAccountTest`,
   `UsersActionsTest` (the non-privileged example is now Guru), `ReportVerificationTest`, `ReportPoliciesTest`,
   `VerifikasiHasilTolakTest`, `HumasScreensTest`, `PublikasiScreenTest`.
3. **Humas verification area** (Task 3): `App\Support\VerificationArea` (`KESISWAAN` / `HUMAS`, `forUser()`, `resolve()`,
   `indexUrl()`, `showUrl()`); routes `humas.verifikasi.index|show` in the Humas group; menu item "Verifikasi hasil" (icon `shield`,
   after "Antrean publikasi") with the count `humas.verifikasi` (`Navigation::BADGES`, `NavigationServiceProvider::count()`,
   `HumasQueries::badges()`); `VerifikasiHasil` gets `#[Locked] public string $area` (set in `mount()` from the route name), which
   picks the `sth-address` URLs, the badge payload (`verificationBadges()`) and the catalog-gap sentence (Humas: "… Kesiswaan
   melengkapinya di Kelola lomba."). View: "Penetapan sekolah", own binaan "Laporan binaan sendiri diverifikasi petugas lain.". New
   `HumasVerifikasiTest` (role × route matrix for both families, a Humas user kept inside `humas.*` on the screen, after a decision
   and after a colleague decided, own binaan refused with a forged call → 403, counts in the menu / other pages / live counts / after
   a decision, 2FA before the screen); changed `HumasRoutesTest`, `NavigationBadgesTest`, `HumasScreensTest`,
   `PublikasiScreenTest`, `VerifikasiHasilTolakTest`.
4. **No "Tolak"** (Task 4): `askReject()`, `reject()`, `REJECT_DIALOG`, `$rejectingId`, `rejecting()`, `rejectNotice()`,
   `closeRejectDialog()` and the dialog are gone; hint "Wajib untuk minta revisi."; the component no longer imports `RejectReport`
   (its docblock says "The rejection Action stays for old data and has no caller on a screen", because a test forbids the class
   name in that file). `VerifikasiHasilTolakTest` rewritten (two buttons only; `RejectReport` still closes a report for old data:
   `ditolak`, `selesai` without points, team and guru told); `KesiswaanScreensTest` (`verify`, `requestRevision`).
5. **Mails** (Task 5): `App\Actions\Reports\ReportVerifiers::recipients()` (Kesiswaan or Humas, `canLogIn()`, one row per
   account); `SubmitReport::notifyVerifiers()`; `ReportSubmitted` builds its link per recipient (`VerificationArea::forUser()`;
   button "Buka verifikasi hasil"); `ReportVerified::for(…, $decider)`, `ReportRevisionRequested::for(…, $decider)`,
   `BinaanReportDecided::revision(…, $decider)`, filled from `StaffRoleLabel::forDecision($actor)` in `VerifyReport` and
   `RequestReportRevision`. Email scenario: step 0a now creates the second Humas account `fitri.h` through a role request that is
   applied at once (the only demo Admin puts it in delay mode; the scenario moves `effective_at` back and runs `ApplyRoleChange`),
   and steps 7a, 7c, 7e expect the report mails for both Humas accounts too. Tests: `SubmitReportTest` (Kesiswaan, Humas, an account
   with both roles once, a disabled Humas and a guru not told, link per area), `ReportVerificationTest` (decider text for
   Kesiswaan, Humas, Admin), `MailInjectionTest`.
6. **Screen texts** (Task 6): Lomba saya status card ("Catatan <peran>", "Diverifikasi <peran> <tanggal>.", "Laporan sudah
   diverifikasi <peran>.", "Sekolah akan memverifikasi laporanmu."; key `decider`, eager load `report.verifier.roles`), Laporkan
   hasil (fallback "Sekolah meminta kamu memperbaiki laporan.", toast), Portofolio, Bantuan, Guru › detail binaan ("Catatan <peran>
   ada di Laporan hasil."), the empty state of Humas › Antrean publikasi. New `DeciderTextTest`.
7. **Cetak daftar** (Task 7): `AppreciationEventPolicy::printList` (= `viewAny`, `appreciation.manage`),
   `App\Actions\Publications\AppreciationList::for()` (rows that pass `NamedStudent` at print time, `omitted` count, "Alumni" / "–",
   sort class → "Alumni" → "–" → name), `PrintAppreciationList` (`viewData()`, `fileName()`, `handle()`: authorize, render through
   `PdfRenderer`, audit `appreciation.list_printed` with `event_id`, `rows`, `omitted`), `AppreciationListPrintController` (inline PDF,
   `no-store, private`, `nosniff`, `frame-ancestors 'none'`), route `apresiasi.cetak` (`area.staff`, `role:humas|kesiswaan|admin`),
   template `resources/pdf/daftar-apresiasi.blade.php`, `Humas\Apresiasi::event()` (`canPrint`, `printUrl`), the button "Cetak
   daftar" (quiet, icon `external`, new tab, after "Hapus acara"), Log audit label "Daftar apresiasi dicetak". New
   `CetakDaftarApresiasiTest` (who may be named, order, no NIS/NISN/points/inline style; headers and audit for Humas, Kesiswaan,
   Admin; other roles, guests and unknown events refused; the button on both Apresiasi routes).
8. **Docs** (this commit): DECISIONS D-139…D-141 (+ dated notes on D-07, D-34, D-61, D-110, D-128), SECURITY (banner, §3a, §3c,
   §3m-7, §3m-9, checklist §4), ARCHITECTURE (§2, §4 `role_change_requests`, §6, §8b, §8h, new §8j), CLAUDE (§1, §2, §5, §7, §8,
   §9, §10), UI-MAP (#17, §4d, Apresiasi rows, §6f, new §6i), PANDUAN-PENGGUNA (§1, §2, §3, §4, §7, §8, §9), this section.

Later on the same screen, not part of Paket C: the FS1 QA pass of 2026-10-13 (`ec743ac`, QA-05) tags a report the ketua sent again
after "Minta revisi" as "Laporan diperbaiki" (`RequestReportRevision::wasRevised()`, test `VerifikasiHasilRevisedChipTest`).

### Interpretations (coordinator choices to confirm with the lead)
- **Kesiswaan keeps verifying.** The feedback had both "verifikasi hasil dipindah ke humas" and "verifikasi hasil ada di humas dan
  kesiswaan"; design §4.1 and the §8 matrix keep Kesiswaan and Admin on `kesiswaan.verifikasi.*` and add Humas on its own
  addresses. Nothing moved away from Kesiswaan. An account that is Kesiswaan and Humas opens both families; its mails and the
  links built without a route use the Kesiswaan area (`VerificationArea::forUser()`).
- **Admin may print.** Design §8 names Humas and Kesiswaan for "Cetak daftar"; the policy is `appreciation.manage`, which Admin
  holds, so Admin may print too (tested). Admin has no Apresiasi menu item; it reaches `/kesiswaan/apresiasi` by address only.
- **PDF stylesheet.** Design §4.4 says the PDF uses "stylesheet layout PDF yang ada". The template has its own `<style>` block in
  the pattern of `resources/pdf/laporan-prestasi.blade.php` (DejaVu Sans 10 pt, kop lines from the letter template, page counter);
  the letter layout (`resources/pdf/layout.blade.php`) holds the letters' fixed measures and was not reused. No `style=""`.
- **The deciding Humas is told too.** `VerifyReport` still sends "Prestasi baru siap dipublikasikan" to every Humas who can log in,
  including the Humas who just verified the report.
- **Role order and wording.** One label per account by the order Kepala Sekolah, Tata Usaha, Kesiswaan, Humas, Admin, Guru (a
  Kepala Sekolah who is also guru reads as "Kepala Sekolah"); "sekolah" without a decider or a staff role; "Penetapan sekolah" on
  the verification screen; the own-binaan warning without a role ("petugas lain").
- **Recipients.** `ReportVerifiers` keeps the `canLogIn()` scope the old Kesiswaan query had (an account still in activation is
  included; D-68 decides whether its mail goes out). Admin is not mailed.
- **Unchanged on purpose:** the chip "Diverifikasi Kesiswaan" on Siswa › Detail lomba (about the lomba); the Bantuan lines that
  send students to Kesiswaan for lomba proposals; `BinaanReportDecided::verified()` (it names no role); the old `ditolak` texts.

### Deploy notes
1. Re-run the role seeder after the code is in place: `php artisan db:seed --class=RoleSeeder --force` (idempotent, syncs the
   permissions of every role and clears the permission cache). Without it Humas has no `report.verify`: no count, and its new screen
   answers 403.
2. Existing Humas accounts keep their role and access: `PrivilegedRoles::needsApproval()` is false for a role the account already
   holds, so no request is created and nothing is taken away. Tell the school that a **new** Humas account (or a staff account
   that should become Humas) now waits for a second Admin, or 24 h when there is only one Admin.
3. 2FA: `staff.2fa` already applied to Humas; in production every Humas account has TOTP before it can open any staff page, so
   nothing new locks anyone out. Check that each Humas account has 2FA set up before the school starts using the new screen.
4. Drain the queue before the deploy (stop accepting work, let `queue:work` empty the `mail` and `default` queues). The
   notification classes kept their properties, so an old payload still unserializes, but a notice queued before the deploy keeps
   the old wording ("Kesiswaan") and a report sent just before it reaches Kesiswaan only. After the deploy restart `queue` and
   `scheduler` so the workers load the new classes.
5. No migration, no asset build needed for this package (no CSS or JS changed).

### Checks run
- Per task (SDD ledger, focused filters only, Pint clean on touched files each time): Task 1 13 passed; Tasks 1-2 the brief's
  filter 145 passed; Task 3 195 passed, then `HumasVerifikasiTest|PageWiringTest|ViewWiringTest` 15 passed; Task 4 83 passed (with
  `PageWiringTest|ViewWiringTest|LivewireRootTest|NoNativeConfirmTest`); Task 5 148 passed; Task 6 235 passed (incl.
  `PageWiringTest`, `ViewWiringTest`, mail tests); Task 7 19 passed + 162 passed (incl. `PageWiringTest`, `SecurityHeadersTest`).
- Browser QA of Paket A, D and C by the coordinator on 2026-10-13, items C1-C7 of the checklist in the lead's workspace
  (`notes/2026-10-13-fs1-browser-qa-checklist.md`: Humas menu and count, verify and revision as Humas, 403 on the Kesiswaan
  address, no "Tolak", mails to Kesiswaan and Humas with the right link, "Diverifikasi Humas" / "Catatan Humas", "Cetak daftar",
  Humas among the second-Admin roles). The coordinator's follow-up note (`notes/2026-10-13-fs1-fix-wave.md`) records every item of
  that pass as passed, with five findings (QA-01…QA-05); the one on this screen (QA-05, the tag of a resent report) was fixed in
  `ec743ac`. This docs pass did not repeat the browser check.
- TEST-RESULTS of this docs pass (2026-10-14, on `ec0925e` = Paket C + the merged Paket B tracks + the FS1 QA fixes, run from
  the container filesystem, one process): `--filter="StaffRoleLabelTest|HumasVerifikasiTest|VerifikasiHasilTolakTest|
  VerifikasiHasilRevisedChipTest|CetakDaftarApresiasiTest|DeciderTextTest|SubmitReportTest|ReportVerificationTest|
  RoleChangeRequestTest|PrivilegedStaffAccountTest|SeedersTest|HumasRoutesTest|HumasScreensTest|NavigationBadgesTest|
  ReportPoliciesTest|MailInjectionTest|KesiswaanScreensTest"` → **283 passed (1704 assertions)**, 37.5 s, exit 0.
- Not run in this pass: the full suite (the last full run was on `d076688`, before Paket C: 3487 passed, 1 skipped; one full
  run is planned after the remaining FS1 packages are merged), `pint --test` (docs only), `npm run build` (no CSS or JS in
  Paket C), the email scenario (steps 0a, 7a, 7c, 7e were edited in Task 5; neither the Paket C tasks nor this pass ran it), the Playwright smoke, `composer
  audit`, and a new browser check.

### Follow-ups (minor, deferred by the task reviews; nothing blocks)
- Tests not written: the area per route for an account that holds both Kesiswaan and Humas, and the `InvalidArgumentException`
  of `VerificationArea`; a render of a report rejected before D-140 on the deep link and the guru screens; a decided report
  whose `verified_by` is null on the screens (the helper itself is tested); "Cetak daftar" with a soft-deleted student or
  account (`NamedStudent` excludes both), and the Admin button on `kesiswaan.apresiasi.index` (the route test covers Admin).
- Docblocks that still say "Kesiswaan" for the decider: `RequestReportRevision` (class) and `VerifyReport` (class); the
  `PrivilegedRoles` docblock calls the five roles "2FA-mandatory", while `staff.2fa` covers every staff role.
- `AppreciationList` eager-loads every `AchievementFacts::RELATIONS` path, including the mentor the PDF never prints (batched,
  not an N+1). `PrintAppreciationList::fileName()` can end in "-" before ".pdf" for a long name and is
  `daftar-apresiasi-.pdf` for a name without ASCII letters or digits (only the file name).
- Run the email scenario once for the edited steps (0a, 7a, 7c, 7e) together with the other FS1 packages.
- Questions for the lead: the *sementara* points under "Interpretations" (also CLAUDE §9).

## Paket E — talenta (D-142, D-143, D-144)

Talent choices become a plain record (no stages, no verification, an optional guru pembina), a student can choose
"Lainnya" with an own text, and a kelas X student with no talent is held on Profil talenta. Plan:
`2026-10-12-fs1-e-talenta-plan.md` (lead's workspace `plans/`), design §6. One migration, no new permission, no new route.
Commits (branch `feat/fs1-e`, merged `04074de`, then together with Paket B through `feat/fs1-int` into
`feat/fs1-feedback-sekolah` at `01b379b`; not pushed): Task 1 `d1e4f14`, 2 `e1d40a3`, 3 `2b84bbe`, 4 `efee77c`, 5 `8fc1ac2`,
6 `fae8764`, 7 (demo data) `97c3607`; reconcile with Paket B `a72a6b5` (tests: the gate before Usulkan lomba, a Lainnya
talent on the Beranda lomba suggestions) and `c7360b8` (`MailGreetingTest` floor 46 → 42 concrete notifications after
the four talent notices were deleted); docs = the commit that adds this section (branch `feat/fs1-docs`).

### Built
1. **Schema and the type "Lainnya"** (Task 1): migration `2026_10_12_100000_fs1_add_other_text_to_talent_applications`
   (`other_text` string 60 nullable; the plain index (student_id, talent_type_id) is added before the unique
   (student, type, branch) index is dropped, because in MySQL that unique index carries the `student_id` foreign key;
   `talent_types.bidang` nullable); `TalentTypeCode::Lainnya` (`bidang()` null, `requiresBranch()` false),
   `Bidang::forTalentCode(): ?self`, `TalentBranch::OTHER` / `isOther()`, `TalentApplication::title()` /
   `branchLabel()`, `other_text` in `logExcept`; `TalentSeeder` = 7 types and the branch "Lainnya" last in OSN, Riset,
   FLS3N, O2SN, Debat; `TalentTypeFactory` never picks Lainnya at random. Every screen that prints a bidang handles null.
2. **"Sebutkan" and duplicates** (Task 2): `TalentOtherText` (3–60, `clean()`, `key()`), `ApplyTalent::handle(…, ?string
   $otherText)`, `needsOtherText()`, `DUPLICATE_OTHER_MESSAGE` (`REJECTED_MESSAGE` deleted); the duplicate check runs
   under the lock of the student row; audit `talent.applied` has `other_text` as a boolean only.
3. **Profil talenta** (Task 3): `RequestTalentReview` + `TalentReviewRequested` deleted; field "Sebutkan"; "Hapus" for
   every own choice (`CancelTalentApplication` and policy `cancel` without a status condition); "Urungkan"; a choice shows
   "Guru pembina …" or "Ditambahkan …".
4. **Database talenta siswa** (Task 4): `MoveTalentStage`, `RejectTalentApplication`, `RestoreTalentApplication` and the
   notices `TalentStageChanged`, `TalentApplicationRejected`, `TalentApplicationRestored` deleted (the folder
   `app/Notifications/Talent/` is gone); `UpdateTalentAssessment` takes `mentor_id` + `note` only; `TalentNote` keeps
   `MAX` + `clean()`; policy `update` replaces `move` / `restore`; `Kesiswaan\Talenta` rewritten (tabs `semua` / `belum`,
   panel, `EXPORT_HEADER`, export without argument). Tests: new `TalentaFs1Test`; rewritten `TalentActionsTest`,
   `TalentaQa9Test`, parts of `KesiswaanScreensTest`, `KesiswaanHardeningTest`, `GuruAreaTest`, `TalentPoliciesTest`;
   deleted `TalentDraftNoteTest`, `TalentRestoreStageTest`, `TalentaM3Test`, `TalentaPindahkanDialogTest`,
   `RequestTalentReviewTest`. Log audit label of `talent.assessment_updated` → "Guru pembina atau catatan talenta diubah";
   the old talent labels stay for sealed old rows.
5. **Other screens** (Task 5): Guru › Binaan (no Tahap / Asesmen, `dibina` counts every student once), Portofolio and its
   PDF (no status), Kesiswaan beranda tile "Talenta tercatat"; `TalentStatus` keeps its cases and `label()` only;
   `TalentApplication::restoreTarget()`, `hasOpenReviewRequest()`, `scopeInStatus()`, `scopeReviewRequested()` deleted.
6. **Kelas X gate** (Task 6): `EnsureTalentChosen` (alias `talent.chosen`, last in `area.siswa`), the alert on Profil
   talenta, `tests/Support/TalentGate::pass()` for tests whose actor is a kelas X student; new `EnsureTalentChosenTest`.
7. **Demo data** (Task 7): every demo talent is `terdaftar`, mentors kept, Keisha has "Lainnya (Kaligrafi)"; the demo kelas
   X students (Aurelia, Hana, Galang) have a talent, so the page crawl passes the gate. Email scenario 9b/9c: Kesiswaan
   sets a guru pembina, a student adds a Lainnya talent and deletes another; nobody is mailed.

### Interpretations and accepted deviations (reviewed; to confirm with the lead, CLAUDE.md §9)
- **Urungkan keeps the guru pembina.** Restoring a deleted choice (Urungkan, or choosing it again later) keeps
  `mentor_id` and `note`; the plan said "restore as today", which cleared them. Also months later, also when that guru is
  no longer active, and without a notice to the guru (D-142 *sementara*).
- **The gate lets every Livewire update through** (`str_ends_with($route, 'livewire.update')`; not a persistent Livewire
  middleware). Spec §6.3 exempted only components on exempt pages. A student who deletes the last talent in one tab can
  keep acting on a page already open in another tab; every new page load is gated. The gate keeps no intended address,
  so an invitation link opened from a mail lands on Profil talenta and must be opened again (D-144 *sementara*).
- `resources/views/siswa/bantuan.blade.php` had no stage line (the plan's line 11); the stage text was in
  `profil-talenta.blade.php` and went there.
- Copy beyond the plan: the jenis tile "Lainnya" is a gray pencil tile with the chip "Tulis sendiri"; the empty state
  "Pilih jenis talenta di bawah."; Riwayat subtitle "Dibaca Kesiswaan"; the panel note "Tidak ada notifikasi yang
  dikirim." next to "Simpan".

### Deploy notes (Paket E; the whole order is in "FS1 overview")
1. **Drain the queue first**: a queued `TalentStageChanged`, `TalentApplicationRejected`, `TalentApplicationRestored` or
   `TalentReviewRequested` job fails to unserialize once the class is gone. Old bell rows are safe (Notifikasi never reads
   `type`; they render from their stored data).
2. `php artisan migrate --force` (the `other_text` migration; forward-only; its `down()` fails while a type without bidang
   exists).
3. `php artisan db:seed --class=TalentSeeder --force` (idempotent; adds the type and the five branches "Lainnya").
4. No new table: `scripts/deploy/grants.sh` needs nothing new. Existing kelas X students without a talent are held at
   their next page (on purpose); tell Kesiswaan before the deploy.

### Follow-ups (minor, left by the reviews; state of `01b379b`)
- `Kesiswaan\Talenta::export()` does not look at `$tab`: the button is hidden on "Belum mengisi", but a forged call there
  downloads the Semua list (same permission, password, audit and cap).
- Stale copy: the button "Daftar penjaringan" and the line "Penjaringan pembinaan talenta <tahun>" on Profil talenta, the
  Portofolio empty text, the audit label "Pilihan talenta dibatalkan siswa" (`talent.cancelled`).
- Tests not written: a real Livewire update through `EnsureTalentChosen` (the `livewire.update` guard is untested), a
  row-level check that the gray "Lainnya" replaces the bidang tile (`TalentLainnyaTest` only proves no crash).
- No browser check by the Paket E implementer (no browser in that session): checklist items E1–E5 are open.
- `PasswordConfirmResumeTest` still uses a harmless `?tahap=seleksi` URL in a session fixture.

## Paket B — alur lomba (D-145 … D-151)

Only students (full form) and Admin add a lomba; weights come from the jenis penyelenggara; duration-based dates and a
required juknis; a student's registration for the own queued lomba waits as `menunggu_lomba`; participant data and an
editable phone; Kepala Sekolah **or** Tata Usaha approves; laporan hasil without a deadline. Plan:
`2026-10-12-fs1-b-alur-lomba-plan.md`, design §5. One migration (`registrations.answer_until`), permission changes
(re-run `RoleSeeder`), two new routes (`siswa.lomba.usulkan`, `tu.persetujuan.index`). Commits (not pushed):
- `feat/fs1-b` (Tasks 1–11 + 10a, merged `1d7ee01`): Task 1 `e3f62ec`, 2 `da5d7fd`, 3 `3674009`, 4 `268cdd3`, 5 `3bcb60f`,
  6 `81113cf`, 7 `37a88d7`, 8 `f726021`, review B1 `8d15243`, 9 `8f4fbbc`, 10 `f9144b8`, 10a `6aac760`, 11 `e377553`,
  `30fc316` (answer deadline on Beranda, Undangan tim, expiry notice), review B2 `8ae9836`.
- `feat/fs1-b3` (Tasks 12–16, merged `c2dad71`): 12 `1c3314c`, 13 `598f437`, 14 `ee14316`, 15 `22242b5`, 16 `2a1cc6d`;
  leftovers `5bf48c9` (Log audit label, Status sistem approvers, `ReportReminder` payload defaults, dev showcase, Pengaturan
  subtitle).
- Reconcile of the two halves `a72a6b5` (a `menunggu_lomba` registration is no approval case yet; lomba decision notices
  name the deciding role; tests for Tata Usaha resuming and the D-147 labels from Usulkan to Detail lomba).

### Built
1. **Who adds a lomba** (Task 1, D-145): `competition.create` = siswa + Admin; `Index::create()`, `Form::open(null)` and
   `CreateCompetition` check `create`; Guru › Lomba without "Upload info lomba" / "Upload saya". Test `LombaCreateAccessTest`.
2. **Weights** (Tasks 2, 3, D-146): `OrganizerWeight::for()` / `isOverride()`, `OrganizerWeightResult` (a value object instead
   of the spec's array tuple); `weight_category` / `event_status` out of `$fillable` (`CreateCompetition::WEIGHT_FIELDS`,
   `forceFill()`; `DemoSeeder` uses `forceFill` too). Tests `OrganizerWeightTest` (10 types × Puspresnas), `ModelFactoriesTest`.
3. **Juknis and date wording** (Task 4, D-147): `JUKNIS_MISSING` on create, update (an old lomba without one is refused at
   its next save) and publish (`REQUIRED_TO_PUBLISH`); messages "Lomba tidak boleh dimulai sebelum pendaftaran ditutup.",
   "Pendaftaran ditutup tidak boleh sebelum pendaftaran dibuka."; `CompetitionFactory` carries a juknis. Test
   `LombaJuknisWajibTest`.
4. **`CompetitionFields` + drawer** (Task 5): the Form Object and the shared partial; "Durasi lomba"; "Hasil peta" + override
   ("Diubah dari hasil peta."), audited as `weight_override` on `competition.published` / `.rejected` (the spec's
   "competition.verified" is these two events). Test `LombaDrawerFieldsTest`.
5. **`SubmitCompetition` takes the whole lomba** (Task 6): `REQUIRED`, weights from the map, `default_mentor_id` and the
   weights dropped from the input, daily limit before the files are stored. Tests `SubmitCompetitionFullTest`,
   `UsulanLombaTest`.
6. **Page Usulkan lomba** (Task 7): `Siswa\Lomba\Usulkan`, route `siswa.lomba.usulkan` (declared before
   `/lomba/{competition}`), step 2 = the Pendaftaran lomba panel + "Nanti saja"; `Lomba\UsulanForm` and its view deleted.
   Test `UsulkanPageTest`.
7. **Usulan saya** (Task 8): `Katalog` `?tab=usulan`, oldest first, name search, own empty states. Test `UsulanSayaTabTest`.
8. **`menunggu_lomba`** (Tasks 9, 10, 10a, D-148): the status, `isOpenForRegistrationBy()`, `CreateRegistration` without
   invitations or notices, `CancelRegistration` silent from it; `ResumeWaitingRegistrations` + `WaitingOutcome` inside
   `VerifyCompetition`; one combined rejection notice; the three-day answer window (`answer_until`, migration
   `2026_10_12_100100_fs1_add_answer_until_to_registrations`, `answersCloseAt()`, `graceText()`); the guru never sees a
   registration he was not told about (`scopeKnownToMentor()`); Undangan tim 404 before the invitation. Tests
   `MenungguLombaTest`, `ResumeWaitingRegistrationsTest`, `ResumedTeamAnswerWindowTest`, `MenungguLombaReconcileTest`.
9. **Data peserta** (Task 11, D-149): `PhoneNumber` (also used by `ImportStudents`), `UpdateStudentPhone`,
   `StudentPolicy::updatePhone`, the block on the panel and the field on Undangan tim. Tests `UpdateStudentPhoneTest`,
   `DataPesertaTest`.
10. **Tata Usaha approves** (Task 12, D-150): `RoleName` (TU + `registration.approve`), route `tu.persetujuan.index`,
    `ApprovalQueue`, TU menu item "Persetujuan lomba", approver links per recipient. Test `PersetujuanTuTest`.
11. **Wording** (Task 13): label "Menunggu persetujuan", neutral sentences, the decider named
    (`RegistrationApproved` / `RegistrationRejected` with `$decider`). Test `DeciderWordingTest`.
12. **Date labels on read screens** (Task 14): `LombaPresenter::range()`, `pendaftaran()`, `REPORT_LINE`; Detail lomba, Undangan
    tim, Guru › Lomba, catalog card. Test `LombaDateLabelsTest`.
13. **No report deadline** (Task 15, D-151): screens, mails, `SubmitReport` (`late` = false, `marksLate()` deleted),
    Batas & arsip, dashboard KPI. Test `ReportNoDeadlineTest`.
14. **Reminders H+3 / H+14** (Task 16): `SendReportReminders` (`CATCH_UP_DAYS`, `dueReminder()`), `ReportReminder`. Test
    `ReportReminderAfterFinalTest`; email scenario 6a–6c moved to "3 days" / "14 days after the final".
15. **Email scenario**: section 2 (students send the whole lomba with a juknis, a guru is refused, Admin's
    `CreateCompetition` with a juknis; isolated run on the B1 branch: 134 mails, 0 missing, 0 unexpected), the approval
    mails to Tata Usaha (Task 12, step 3k), 6a–6c (Task 16). Not run on the merged tree.

### Interpretations and accepted deviations (reviewed; to confirm with the lead)
- **Invite window of a resumed team.** `RegistrationPolicy::teamWindowOpen()` takes the registration and reads
  `answersCloseAt()`, so during the three days the ketua may also invite replacements and withdraw invitations
  (`InviteMembers`, `WithdrawInvitation`, `ConfirmMembership` use it); the hint under "Undang anggota pengganti" names the
  deadline. The spec named only `respond` and the expiry. A missing lomba (practically unreachable, soft deletes) now
  counts as an open window.
- **Lomba decision notices name the deciding role** (`CompetitionRejected::role()`: "Kesiswaan" or "Admin"; "Alasan Admin:
  …", "Admin tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu."), and so do "Usulan saya" and Lomba saya. Detail lomba
  (`LombaPresenter::unpublishedNotice()`) and the Pendaftaran lomba panel still say "Kesiswaan"; without a decider the
  notices say "Kesiswaan" while Katalog / Lomba saya say "sekolah". Not unified yet.
- **`menunggu_lomba` on the panel and Lomba saya**: the steps start with "Kesiswaan memverifikasi lomba"; the Lomba saya card
  is headed "Verifikasi lomba"; Persetujuan and its count leave such registrations out until the lomba is published.
- **ApproveRegistration does not send the TU notice to the approving account**; its TU colleagues get it.
- **`SubmitReport` writes `late` = false** for every report, also when an old late report is sent again.
- **Reminders** skip any registration that has a report (also one waiting for its revision) and catch up a missed day for
  7 days, sending only the latest due reminder; a deploy sends no burst to old rows.
- `ReportReminder` keeps `dueOn` / `daysLeft` declared and gives `$daysAfterFinal` a default, so a reminder queued before
  D-151 unserializes and is sent with today's wording. (A later review noted that `SerializesModels` ignores unknown keys
  anyway; the default is what matters, the two old properties are only harmless.)
- TU has its own address `tu.persetujuan.index` instead of opening `kepsek.persetujuan.index` to two roles; Admin's
  Status sistem warns only when no Kepala Sekolah **and** no Tata Usaha can approve.
- `CompetitionFields::$max_team_size` / `$default_mentor_id` are `int|string|null` (the browser sends '' for an emptied
  field); `addActionErrors()` returns the errors that belong to no field; `remove_cover` lives in the form object;
  `SubmitCompetition` treats a missing `starts_on` as a one-day lomba; Usulkan keeps the sheet's limiter (10 sends an hour,
  key `usulan:<user id>`) and cleans the text fields first.
- `MailGreetingTest` counts at least 42 concrete notifications (was 46; the four talent notices are gone).

### Deploy notes (Paket B; the whole order is in "FS1 overview")
1. `php artisan migrate --force` (`answer_until`; column only, no backfill, no new grant).
2. `php artisan db:seed --class=RoleSeeder --force`: TU gets `registration.approve`; guru, Kesiswaan, Kepala Sekolah, TU and
   Humas lose `competition.create`. Without it TU has no Persetujuan lomba and Kesiswaan still sees "Tambah lomba".
3. Drain the queue before, restart `queue` + `scheduler` after: `ReportReminder`, `ReportSubmitted`, `ReportWindowOpened`,
   `RegistrationApproved`, `RegistrationRejected`, `CompetitionPublished`, `CompetitionRejected` changed their constructors
   or properties (defaults keep most old payloads readable, but a drained queue is the safe way), and the scheduler must
   load the new `sth:reports:remind`.
4. Old data: lomba without juknis stay visible (their next save needs one); `reports.late` stays true on old rows and is
   not read; `registration_reminders` rows of the old kinds stay.

### Follow-ups (minor, left by the reviews; state of `01b379b`)
- `Admin\AuditLog::EVENT_LABELS` has no label for `registration.resumed` (the log shows the key).
- `UpdateCompetition::assertTeamsStillFit()` does not refuse a change to "Tim" while individu registrations run; through
  D-148 Kesiswaan can now correct a queued lomba that has a waiting individu registration, which would then be resumed
  onto a team-only lomba.
- The change notice "Batas daftar sekarang … Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu."
  ignores a resumed team's `answer_until`.
- The Kelola lomba card counts anggota of a `menunggu_lomba` team who were never invited.
- The Kesiswaan drawer does not say that a queued lomba has waiting registrations before "Publikasikan" / "Tolak".
- Old wording: "Batas daftar" (Kelola lomba table, change notice), "Juknis/brosur diganti.", `FilePurpose::Juknis` label
  "Juknis/brosur".
- `ResumeWaitingRegistrations::handle()` refuses to run outside a transaction; untestable under `RefreshDatabase`. The two
  JSON audit lookups (`properties->from`, `properties->reason`) ran on SQLite only, not on MySQL.
- No browser check by the Paket B implementers: checklist items B1–B9 are open; QA-04 of the A/D/C pass ("Kirim
  pendaftaran" works only on the second click after ticking "Data yang saya isi benar.") is not fixed either.

## FS1 overview

### The 30 feedback items
The school's two lists as numbered in the coordinator's QA checklist (`notes/2026-10-13-fs1-browser-qa-checklist.md` in the
lead's workspace). "Built" = in `feat/fs1-feedback-sekolah` at `01b379b`, tested; the browser QA of A/D/C passed on
2026-10-13, the one of E and B is still open (items E1–E5, B1–B9).

| # | feedback (as written by the school) | package · decision | where it is built | status |
|---|---|---|---|---|
| 1 | kesiswaan hanya verifikasi | B · D-145 | Kelola lomba: no "Tambah lomba" for Kesiswaan; check, correct, publish or reject | built |
| 2 | input lomba hanya dilakukan siswa | B · D-145 | `competition.create` = siswa + Admin; Guru › Lomba without upload | built (Admin also adds) |
| 3 | password default di permudah | D · D-138 | one initial password from config, replaced at the first login | built; Kepala Sekolah signs the risk |
| 4 | sort by date input lomba (terlama di atas) | A + B · D-135, D-145 | verification queue and pending catalog bucket oldest first; "Usulan saya" oldest first | built |
| 5 | input bagian kesiswaan ditambahkan ke siswa | B · D-145 | Siswa › Usulkan lomba = the whole lomba form (`CompetitionFields`) | built |
| 6 | bobot lomba tampil otomatis di siswa | B · D-146 | `OrganizerWeight`; "Kategori B · bobot 0,80" + estimate on the form | built |
| 7 | tanggal opsi berdasarkan durasi | B · D-147 | "Durasi lomba" 1 hari / Lebih dari 1 hari | built |
| 8 | revisi penamaan tanggal | B · D-147 | Pendaftaran dibuka/ditutup, Tanggal lomba/mulai/selesai; Pendaftaran · Pelaksanaan · Laporan hasil | built (a few old labels left) |
| 9 | juknis/brosur/undangan wajib | B · D-147 | create, save and publish refuse a lomba without it | built |
| 10 | ganti nama di kesiswaan (verifikasi usulan lomba) | A · D-135 | menu, tab, tiles, mail, dashboard | built |
| 11 | setelah simpan langsung ke pendaftaran lomba | B · D-145, D-148 | Usulkan step 2 = Pendaftaran lomba; `menunggu_lomba` | built |
| 12 | "daftar lomba" → "pendaftaran lomba" | A · D-135 | panel title, Bantuan | built |
| 13 | validasi kepala sekolah ATAU TU | B · D-150 | `registration.approve` for TU, `tu.persetujuan.index` | built; TU-approves-and-issues risk accepted |
| 14 | perbaikan template surat | ST1 · D-154 (2026-10-16) | student letter: Dasar = the template's regulation text (no invitation rows), kop and spacing of the school's printed letter, TU preview at the PDF's scale (`docs/dev-notes/ST1.md`) | **built for the top of the student letter** (the lead's photo shows kop to intro); the table (NIS column), Pelaksanaan, signature and the guru letter wait for the rest of the school's template |
| 15 | laporan hasil tanpa batas waktu, "segera laporkan" | B · D-151 | no deadline anywhere; reminders H+3 / H+14; KPI "Belum lapor > 14 hari" | built |
| 16 | verifikasi hasil dipindah ke humas | C · D-139 | Humas verifies on `humas.verifikasi.*` (Kesiswaan keeps verifying, see 21) | built |
| 17 | button verifikasi hasil: verifikasi + revisi saja, tanpa tolak | C · D-140 | Verifikasi hasil without "Tolak" | built |
| 18 | notif verifikasi hasil (email) | C · D-139 | new and resent reports mailed to Kesiswaan and Humas; decisions name the role | built |
| 19 | cetak fisik list murid yang diumumkan | C · D-141 | "Cetak daftar" per appreciation event | built |
| 20 | hilangkan kolom kategori di excel | A · D-135 | Database prestasi Excel without Kategori | built |
| 21 | verifikasi hasil ada di humas dan kesiswaan | C · D-139 | both roles, first decision wins | built |
| 22 | login awal dll pakai NISN | D · D-137 | login by NISN (or the bound email) | built |
| 23 | NIS hanya disimpan di database | D · D-137 | NISN on screens, files and searches; NIS required at import | built |
| 24 | daftar lomba: input no HP, tgl lahir, nama, kelas, NISN | B · D-149 | "Data peserta" + editable No HP; No HP on "Ikut tim" | built |
| 25 | admin: database prestasi | A · D-135 | Admin menu item Database prestasi | built |
| 26 | warna notif merah | A · D-136 | red count badges | built |
| 27 | talenta wajib di awal kelas 10 | E · D-144 | `EnsureTalentChosen` | built |
| 28 | talenta bisa pilih lainnya | E · D-143 | type and branches "Lainnya" + "Sebutkan" | built |
| 29 | kesiswaan: "talenta siswa" → "database talenta siswa" | A · D-135 | menu, title, Excel title row | built |
| 30 | verifikasi database talenta dihilangkan | E · D-142 | no stages, no verification; Kesiswaan records | built |

### Deploy FS1 (one checklist for the whole wave)
Run on the server in this order (DEPLOY.md for the commands of the stack; production migrations are forward-only):
1. **Before the deploy**
   - The Kepala Sekolah signs the updated risk-acceptance form (D-14 with the risks of D-79 and **D-138**: one initial
     password for every student who has not activated yet; mention also D-150, Tata Usaha approving and issuing letters
     signed for the Kepala Sekolah, SECURITY §3m-5).
   - `STH_INITIAL_STUDENT_PASSWORD` is set in the production env (`docker/env.production.example`; without it the default
     of `config/sth.php` is used). The value is never written in a doc, a ticket or a chat log; Kesiswaan tells students
     outside the app. Avoid a value that is only spaces or padded with spaces (Paket D follow-up b).
   - Every existing Humas account has TOTP set up (`staff.2fa`; D-139 makes Humas privileged). Existing Humas accounts keep
     their role: no second-Admin request is created for them.
   - Tell Kesiswaan that existing kelas X students without a talent will be held on Profil talenta at their next page
     (D-144), and that Kesiswaan no longer adds lomba (D-145).
   - **Drain the queue**: stop new work, let `queue:work` empty `otp`, `mail` and `default`, then stop `queue` and
     `scheduler`. Deleted notice classes (`TalentStageChanged`, `TalentApplicationRejected`, `TalentApplicationRestored`,
     `TalentReviewRequested`) cannot be unserialized after the deploy; changed ones (`ReportReminder`,
     `ReportSubmitted`, `ReportWindowOpened`, the approval and lomba decision notices) keep defaults, but an empty queue
     is the safe state.
2. **Deploy the code**, then `php artisan migrate --force`: `2026_10_12_100000_fs1_add_other_text_to_talent_applications`
   (`talent_applications.other_text`, index swap, `talent_types.bidang` nullable) and
   `2026_10_12_100100_fs1_add_answer_until_to_registrations` (`registrations.answer_until`). Both add or change columns
   only: `scripts/deploy/grants.sh` needs no new table.
3. `php artisan db:seed --class=RoleSeeder --force` (idempotent; syncs every role's permissions and clears the permission
   cache): Humas gets `report.verify`; Tata Usaha gets `registration.approve`; guru, Kesiswaan, Kepala Sekolah, TU and Humas
   lose `competition.create`.
4. `php artisan db:seed --class=TalentSeeder --force` (idempotent; the type "Lainnya" and the five branches "Lainnya";
   Admin-added branches stay).
5. Restart `queue` and `scheduler` so the workers load the new classes and `sth:reports:remind` runs with H+3 / H+14.
   The first reminder run sends at most the latest due reminder per registration within 7 days of its day; older
   registrations get none.
6. **After the deploy**: open Kesiswaan › Database talenta siswa (tab "Belum mengisi (kelas X)"), TU › Persetujuan lomba,
   Humas › Verifikasi hasil and Admin › Status sistem once; run the email scenario on a staging copy if one exists
   (DEPLOY §18).

No FS1-specific asset step beyond the normal `npm run build` of the image (Paket A changed CSS; E and B use existing
utility classes in their views).

### Checks of the merged tree (`01b379b`)
- Full suite, this docs pass (2026-10-14, `wt-fs1-docs`, `bash fs1-test.sh … --parallel --processes=8`): **3588 passed,
  1 skipped (21604 assertions)**, 139 s. The previous full run was `d076688` (before C, E, B): 3487 passed, 1 skipped.
- Not run in this pass: `pint --test` and `npm run build` (docs only), the email scenario on the merged tree, the
  Playwright smoke, `composer audit`, a browser check of E and B.

### Follow-ups of the whole wave (also CLAUDE.md backlog #83)
- Browser QA of E and B (checklist E1–E5, B1–B9) at 1366 and 390 px; QA-04 of the A/D/C pass.
- The review leftovers listed under "Paket E" and "Paket B" above.
- Email scenario: steps for a `menunggu_lomba` registration whose lomba is published and one whose lomba is rejected, a
  Tata Usaha approval, a resumed team; one run on the merged tree.
- Lead's choices: CLAUDE.md §9, "Questions for the lead from FS1-E and FS1-B" (and the FS1-A/D/C ones before them).
- Item 14 (letter template): waits for the school.
- Browser QA of 2026-10-14, BUG-N5b (Pendaftaran lomba panel at 390 px): the names in the member rows and the member
  search wrap now, like Undangan tim (BUG-N5). The NISN next to the class in the member search, asked for in the same
  finding to tell two classmates with the same first words apart, was **not** added: SECURITY §3m-3 says the search answers
  name and class only, never NIS or NISN, and does not show a searched number back. With D-138 (one initial password for
  every student) a search that shows the NISN beside "belum aktivasi akun" would list login names of accounts that can
  still be taken over. The whole name now shows; adding the NISN (or a part of it) is a decision for the lead, with
  §3m-3 and the test `returns only name and class from the member search` changed together.

### Final polish (2026-10-14, tests `tests/Feature/Lomba/Fs1Qa5PolishTest.php`)
- Kesiswaan › Kelola lomba, tab "Verifikasi usulan lomba": the hint already read "Setiap lomba baru, dari usulan siswa
  atau dari Admin, diperiksa di sini sebelum tampil di katalog." since QA round 1 (`4d48fe4`, LOMBA-02). Kept: Admin's
  "Tambah lomba" also starts at `menunggu_verifikasi` and lands in this queue (D-145), so "Semua usulan lomba …" would
  call an Admin's lomba an usulan.
- Kepala Sekolah / Admin › Dashboard: the empty chart says "Grafik muncul setelah sekolah memverifikasi laporan hasil."
  (Kesiswaan or Humas verifies since D-139; spec §4.1: general sentences say "sekolah").
- Siswa › Detail lomba: the chip of a published lomba names its verifier's role through `StaffRoleLabel::forLomba()`
  ("Diverifikasi Kesiswaan" / "Diverifikasi Admin", Kesiswaan when the verifier is unknown); `verifier.roles` is eager-loaded.
- Siswa › Cari lomba: the subtitle follows the tab (`Katalog::SUBTITLES`): the catalog keeps "Lomba di katalog sudah
  diperiksa Kesiswaan.", "Usulan saya" reads "Usulan lombamu dan statusnya.". The layout draws it on a page load
  (`layoutData`); a tab picked on the page sends the window event `sth-subtitle {text}`, and `resources/js/app.js` puts it
  into `[data-sth-subtitle]` of `<x-sth.topbar>` (the top bar is rendered once per page load, as for `sth-badges`).
- Kepala Sekolah menu: "Persetujuan" → "Persetujuan lomba", the name of the Tata Usaha item and of the page title (D-119);
  route names, menu key `persetujuan` and count key `kepsek.persetujuan` unchanged.
- Admin › Log audit: `talent.assessment_updated` = "Data talenta diubah". Rows written before FS1 under that key may hold a
  changed assessment date, rows since D-142 the guru pembina or the note; Admin holds `talent.manage` too, so the label
  names no role (the Pelaku column does). The user guide and UI-MAP also lost the stale line that `registration.resumed`
  shows its key: it has had the label "Pendaftaran dilanjutkan setelah lomba dipublikasikan" since FS1-B.
- OBS-STEP2-TEAM, Pendaftaran lomba panel (Detail lomba and step 2 of Usulkan lomba): after "Kirim pendaftaran" for a team
  whose own lomba still waits (`menunggu_lomba`), the ketua sees "Anggota tim" with every member, read-only: the member
  chip "Diundang setelah lomba diverifikasi" (the words of Lomba saya), no ×, no "sisa N tempat", no invite search; the
  rows keep the wrapping name of `76e7553`. The withdraw dialog is drawn only where the ketua may invite.
- `docker/php/dev-cache.sh` (reload.bat, start.bat with `--force`): step 5 runs `php artisan queue:restart`, so the
  `queue` worker drops old notification, mail and job code after its current job (compose starts it again). On the
  Windows drive `php artisan` takes about 20 s without CLI opcache, so in the `app` container it runs in the background
  (log `/tmp/sth-cache/queue-restart.log`) and reload.bat stays at about 3 s; in a one-off `run` container it runs in
  the foreground. Checked by hand: plain run (worker restarted, `Up 3 seconds`), `--force`, the `queue` service stopped
  (the key is written, nothing else happens), the database unreachable (`-e DB_HOST=nohost.invalid`: the warning, exit
  0) and a one-off `docker compose run --rm --no-deps app` (foreground, exit 0). CLAUDE.md §4 "Page speed" says so.
