# FS1 — feedback sekolah of 2026-10-12  ·  status: Paket A built (not pushed)

Two lists of feedback from SMAGA, pasted by the lead on 2026-10-12, are applied as one wave (FS1) in five packages. The design
is `2026-10-12-feedback-sekolah-fs1-design.md` (outside the repo, in the lead's workspace `specs/` folder); the decisions are
in `docs/DECISIONS.md` §D15 (D-135, D-136). Order of the packages: **A → D → C → E → B**; each one gets its own plan and its
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
- Paket D, C, E and B add their own `## Paket X` sections below.
