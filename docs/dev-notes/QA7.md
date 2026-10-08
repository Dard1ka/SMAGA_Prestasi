# QA7 — explore round after UI3 (2026-10-08)  ·  status: done (branch `fix/qa7-explore-ui3`, one PR with UI3)

Exploration of the lead's local checkout of `feat/ui-kapsul-dan-lambang` (`7b0242b`: `main` with PR 15 + UI3, the
teammate's visual direction v3, capsules and the school crest), in two rounds (the lead: "Cari lagi nanti satu pr
semua"). Round 1: worst-case inputs on the new UI3 components in a headless browser, and two read-only code reviews by
agents. Round 2: every signed-in screen of every role rendered with worst-case data and measured at four widths.
Every fix test-first (red seen, then green). Fictional data only; no migration; no new decision.

## State of the local checkout (before any change)
- Local branch = `origin/feat/ui-kapsul-dan-lambang`, working tree clean; `origin/main` (PR 15) and
  `origin/feat/ui-tinta-dan-sampul` are fully contained. No open PR for the branch yet.
- No pending migration. Full suite `--parallel --processes=4` before any change: **2320 passed, 1 skipped (13992
  assertions)**, exit 0 (1334 s) — the same numbers as `UI3.md`.
- The local database had no demo posters (0 of 10 published lomba with a cover): `sth:demo:refresh-files --lengkapi`
  added the 7 drawn posters (D-118) and re-rendered the 98 demo PDFs once (72 s).
- `node_modules` held the Windows builds of the native packages (`lightningcss-win32-x64-msvc`,
  `@tailwindcss/oxide-win32-x64-msvc`: `npm` had run on the host), so `npm run build` in the `node` container failed
  ("Cannot find native binding"). `docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` (CLAUDE.md §4)
  installed the Linux builds. Run npm only through Docker (§3.1).

## How round 2 measured the signed-in screens
The app's browser pane had no session and a demo login was not possible from this session, so a scratch script (not in
the repo, deleted afterwards) did what `actingAs` does in a test, on the local demo database: inside one transaction it
gave every lomba a 55-letter name, penyelenggara and place and every registration a long branch, rendered 37 screens of
all seven roles plus 5 public pages through the HTTP kernel (`guard()->setUser()`), wrote the HTML to a temporary
folder, and **rolled the transaction back** (checked: no row changed, the public cache table too). Headless Chromium
then measured each page at 360, 390, 1280 and 1366 px. Not covered: Admin › Pengguna and Pengaturan (behind the
password page), dialogs that open on a click, the PDFs.

## Files
- `resources/css/app.css` — `body { overflow-wrap: break-word }`; `.chip-wrap` breaks a long word and stays in its box
- `resources/views/components/sth/{lomba-card,lomba-head,page-header}.blade.php` — text columns wrap a long word
- `resources/views/livewire/siswa/{beranda,portofolio}.blade.php`, `siswa/lomba-saya/index.blade.php`,
  `siswa/lomba/katalog.blade.php` — Lomba saya cards, prestasi capsules, registration head, catalog paging
- `resources/views/livewire/kesiswaan/verifikasi-hasil.blade.php` — the penyelenggara tag may take two lines
- `resources/views/livewire/sekolah/dashboard.blade.php` — chart: screen-reader table, year labels, bars
- `resources/views/livewire/tata-usaha/surat-tugas.blade.php`, `tata-usaha/partials/pratinjau-{siswa,guru}.blade.php` —
  letter tabs on a phone, paper preview
- `resources/views/livewire/humas/{publikasi,partials/publication-card}.blade.php`,
  `resources/views/livewire/publik/{beranda,partials/siswa-detail}.blade.php` — cards wrap a long word
- `app/Support/Navigation.php` — no phone tab on the account pages
- `app/Livewire/Kesiswaan/Lomba/Form.php` — the cover message names WEBP
- tests: `tests/Feature/DesignSystem/LongWordsTest.php` (new, 7), `NavigationTest` (+1), `Siswa/Hasil/PortofolioTest`
  (+1), `Siswa/Hasil/LombaSayaTest` (+2), `Siswa/Lomba/SiswaScreensTest` (+2), `Publik/PublikPagesTest` (+1),
  `Kesiswaan/KesiswaanScreensTest` (+2), `KepalaSekolah/DashboardTest` (+2), `Humas/PublikasiScreenTest` (+1),
  `TataUsaha/SuratTugasTest` (+2)

## What changed (finding → root cause → fix)
1. **One long word pushed cards and pages sideways, on the new UI3 cards and on many older screens.** With a long
   word as lomba name, penyelenggara and place, at 390 px: the capsule card spilled 349 px (page +318), the head of
   Detail lomba / Undangan tim +491 px, the public "Prestasi terbaru" cards +121 px; round 2 found the same on Siswa
   › Beranda (the screenshot of the lead: the name ran out of its "Lomba saya" card) and Lomba saya, Kesiswaan ›
   Beranda and Verifikasi hasil, Kepala Sekolah / Admin › Dashboard, Persetujuan, the TU queue and paper preview,
   Humas, Guru › detail binaan (47–321 px). Realistic words are enough in narrow columns (the head's text column is
   150 px on a phone: "Kemendikbudristek" at 20 px does not fit). Root causes: no rule broke a word wider than its line
   at all; and flex columns with `items-start`, flex items without `min-w-0`, and implicit grid tracks size
   themselves to their longest word. Fix: `overflow-wrap: break-word` on `body` (breaks only a word that does not fit
   its line and leaves min-content alone, so tables and flex rows size as before), and `[overflow-wrap:anywhere]` on
   the text columns whose min-content grew (capsule card, lomba head, page header, Portofolio, public and Humas cards,
   Lomba saya head, Beranda "Lomba saya", "Perlu perhatian", the paper preview). Normal titles keep their 1–2 lines;
   the page header still wraps the row before it breaks a word.
2. **Siswa › Beranda, "Lomba saya": a line could start with "·".** The dot between the lomba name and "Provinsi ·
   Individu" was printed after a space, so a name that filled the line pushed the dot to the start of the next one
   (the lead's screenshot). The dot is now glued to the last word (`&nbsp;·&nbsp;`).
3. **Kepala Sekolah / Admin › Dashboard scrolled 111 px sideways on a phone, with ordinary data.** The chart's table for
   screen readers carried `sr-only` itself; a table does not shrink to 1 px, so the absolutely placed table stood
   ~480 px wide. The table now sits in an `sr-only` box (a test forbids `sr-only` on a `<table>` in every view). At
   360 px the chart then still did not fit its card: four labels such as "2025/2026" may now break after the slash
   (`<wbr>`), and a year group may get narrower than its 16 px bars (`min-w-0`).
4. **TU › Surat tugas, the two letter tabs did not fit a phone (+180 px at 390 px)** while both letters were still to
   be made ("Surat siswa · belum dibuat", "Surat guru pendamping · belum dibuat"; ordinary data). On a phone the tabs
   now share the width and show the state under the name.
5. **Siswa › Lomba saya, the "Surat tugas" card was 16 px (390 px) / 46 px (360 px) too wide, with ordinary data.** A
   letter row (number such as `400.3.8/901/MRD/X/2026` + download button) set the width of an implicit grid track, so
   the ellipsis inside never applied. The grid is `grid-cols-1` (= `minmax(0, 1fr)`) below xl.
6. **Siswa › Cari lomba, the paging row was 20 px too wide on a 360 px phone** (as soon as more than 9 lomba are open).
   "Halaman" is only read out on a narrow card (`@max-[400px]:sr-only`), "1 dari 2" stays.
7. **Kesiswaan › Verifikasi hasil, the tag "penyelenggara · kategori" was `nowrap`**: a long penyelenggara (even one
   with spaces) ran out of the panel. It is a `.chip-wrap` now, and `.chip-wrap` breaks a word longer than its line.
8. **Phone tab bar: "Portofolio" was the current page on Keamanan akun and Notifikasi.** UI3 renamed the tab "Profil"
   to "Portofolio" (D-119) but kept `akun.*` in its match list. The account pages have no tab now (they open from the
   avatar menu); Profil talenta still lights "Portofolio", the closest section.
9. **Kelola lomba: a refused cover said "Pilih gambar JPG atau PNG."** although WEBP is accepted (rule and hint). Now
   "Pilih gambar JPG, PNG, atau WEBP."

After the fixes: the 37 signed-in screens and 5 public pages, all with the long words, scroll sideways at none of 360,
390, 1280 and 1366 px (168 page × width checks), and the dashboard chart stays inside its card.

## Reviewed and found sound (agents, read-only, round 1)
- Backend of D-118: `files.cover` checks the policy and that the original exists before any 304; covers of a closed,
  rejected or queued lomba only for submitter and verifiers; only `purpose = cover` files; MIME sniffed at upload and
  again before the thumbnail; ETag from the stored hash; random `.tmp` names; `Sampul::poster()` costs no query per
  card for published lomba; `Siswa\Beranda::openFor()` counts the same way as `Student::busyIn()`; the `Login` listener
  only drops an intended address under `/berkas/sampul/`.
- Screens: no damaged class string from the mass replace, no `text-[Npx]` outside the letter paper preview, no "kamu"
  on a staff screen or mail, no banned word, no inline style / script / handler, every removed duplicate link still
  reachable from the role's menu, and the QA6 work (dialogs, `?koreksi=`, "Batalkan undangan", the Talenta panel)
  intact after the type-scale pass.

## Commands verified
- RED: every new test failed for the missing class, rule, tab or message before the fix (one: the Humas test first
  failed because its card was not on screen; the fixture was corrected to a published card).
- GREEN: the touched test files and the QA7 filter (`--filter=QA7`, 17 + the dashboard set) pass; `pint --dirty` →
  pass; `npm ci && npm run build` in the container → OK (the new rules are in the CSS).
- Browser (headless Chromium via Playwright): round 1 on the guest pages and `/_dev/components`; round 2 as described
  above. The fixed words wrap inside their cards; normal titles keep their 1–2 lines.

## TEST-RESULTS
- Before any change: `php artisan test --parallel --processes=4` → 2320 passed, 1 skipped (13992 assertions), exit 0.
- After round 1: 2327 passed, 1 skipped (14012 assertions), exit 0 (1229 s); `pint --test` PASS (665 files).
- After round 2: `php artisan test --parallel --processes=4` → **2341 passed, 1 skipped (14038 assertions)**, exit 0
  (1146 s); `pint --test` → PASS (665 files); `npm ci && npm run build` in the container → OK.
- Not run: the email scenario and the Playwright smoke (backlog #46); Firefox / Safari / a real phone.

## Requests for the lead
- Portofolio shows the bidang drawing, not the poster, for a lomba that Kesiswaan has closed (`Sampul::poster()` asks
  `CompetitionPolicy::view`, and a student may not view a closed lomba). Most achievements belong to closed lomba, so
  the capsules in Portofolio will rarely show a poster. Allow the cover of a lomba the student took part in (a rule in
  `FilePolicy` / `Sampul::poster()`), or keep the drawing?
- The letter PDFs were not checked with a long word (dompdf does not break a word by default; only the guru letter's
  verification block has `word-wrap: break-word`).
- Smaller notes from the reviews, not changed: an `<img>` of a cover that loads after the session expired can replace
  the intended address before the login (only with a second open tab); a cover row whose bytes are gone is asked for
  again on every render; `Admin\Ringkasan` and `Siswa\Beranda` still build `links` the views no longer use; the medal
  icon on Siswa › Peringkat is white on gold while Beranda uses ink; menu name vs page title still differs on
  Dashboard / Dashboard prestasi, Arsip / Arsip publikasi, Import siswa / Import data siswa (backlog #76).
