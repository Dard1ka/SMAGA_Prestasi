# FS1 — feedback sekolah of 2026-10-12  ·  status: Paket A and Paket D built (not pushed)

Two lists of feedback from SMAGA, pasted by the lead on 2026-10-12, are applied as one wave (FS1) in five packages. The design
is `2026-10-12-feedback-sekolah-fs1-design.md` (outside the repo, in the lead's workspace `specs/` folder); the decisions are
in `docs/DECISIONS.md` §D15 (D-135, D-136 for package A; D-137, D-138 for package D). Order of the packages: **A → D → C → E → B**; each one gets its own plan and its
own `## Paket X` section in this file. Branch `feat/fs1-feedback-sekolah` (from `fix/em2-uji-email` = EM2 + RV1, merged with
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
- Paket C, E and B add their own `## Paket X` sections below (Paket D is below).

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
