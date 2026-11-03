# UI3 — visual direction v3 "Tinta dan Sampul" (D-117…D-120)  ·  status: done (branch `feat/ui-tinta-dan-sampul`, then the lead's review on `feat/ui-kapsul-dan-lambang`)

The lead's request of 2026-10-07: apply the UI audit of 2026-10-06 to the current `main` (PR 15), every work package,
with the choices that were still open following the worker's recommendation. Spec: `docs/design/DESIGN-SPEC-v3.md`.
Sub-notes: `UI3-A.md` (cover pictures, backend), `UI3-B.md` (copy pass). Audit report with before/after pictures
(outside the repo): https://claude.ai/artifact/91hVTZ6Dn25ZgwoNYfdTDq. Screenshots of the built UI, every role at both
widths: https://claude.ai/artifact/Jzsm71QEeRvDkvUw2KUwoY (both links are private to the lead).

## Files (by package)
- **B tokens** — `resources/css/app.css` (rewritten: tokens, `--text-*` scale, helmet classes, `.sampul*`, `.spanduk*`,
  `.chip-wrap`), `package.json` / `package-lock.json` (`@fontsource/public-sans`; Plus Jakarta Sans and Urbanist
  removed), `layouts/partials/head.blade.php` (theme colour), `public/favicon.{svg,ico}`, `public/apple-touch-icon.png`,
  `auth/mail/otp-code.blade.php` and `livewire/siswa/portofolio/pdf.blade.php` (colours only).
- **C type scale** — 78 view and Livewire files: 461 `text-[Npx]` → `text-xs … text-5xl`, `font-extrabold` → `font-bold`,
  hand-written radii → `rounded-card` / `rounded-control`. Left alone: `livewire/tata-usaha/partials/*` (paper preview).
- **D covers** — `components/sth/sampul.blade.php` (new), `app/Support/Sampul.php` (new), `resources/images/sampul/*.svg`
  (24) + `resources/images/motif/*.svg` (2), `components/sth/lomba-card.blade.php`, `Siswa\Lomba\LombaPresenter::card()`
  (`poster`), `Siswa\Lomba\Detail` (`poster`, "Lihat poster"), `Siswa\Portofolio` (`seed`, `poster`), `Humas\Publikasi`
  (`bidang`, `seed`), views: siswa katalog / beranda / detail / konfirmasi-tim / portofolio, guru lomba, publik
  beranda, humas publication-card, kesiswaan lomba form ("Sampul di katalog sekarang"), `resources/js/app.js` (drops
  a poster that fails to load).
- **E cover route** — see `UI3-A.md`.
- **F components** — `stat-tile` (`zero`, phone layout), `sidebar` (no button above the menu), `facts`, `avatar` /
  `avatar-stack` (28 px), `table` (`table-class`), `topbar` (phone title wraps), `App\Support\Navigation` (no `cta`
  key, tab icon), `dev/components.blade.php` + `dev/public.blade.php` (showcase).
- **A / G screens** — `layouts/auth.blade.php`, `errors/layout.blade.php`, siswa beranda (banner), publik beranda and
  partials/siswa-detail, kepsek persetujuan, kesiswaan lomba index / talenta / database-prestasi, guru binaan / lomba,
  tata-usaha surat-tugas, siswa tur-menu + `Siswa\TurMenu` (positions, names), 16 screens with stat tiles (grid).
  Copy: see `UI3-B.md`.
- **H docs** — `docs/design/DESIGN-SPEC-v3.md` (new), `docs/DECISIONS.md` §D11, `docs/SECURITY.md` §3d note,
  `docs/UI-MAP.md` §6e, `CLAUDE.md` §2 / §3.9 / §5 / §7 / §10, these notes.
- **Tests** — `tests/Feature/DesignSystem/SampulTest.php` (new), `ComponentsTest`, `NavigationTest`, the tests of
  `UI3-A` and `UI3-B`.

## Contracts other areas can use
- `<x-sth.sampul :bidang :seed :src bentuk="lebar|tegak|utuh" tone="terang|pekat" alt class>` · `<x-sth.lomba-head :bidang
  :seed :poster :facts alt>` (slot = the text beside the poster) · `<x-sth.facts … plain>` · `App\Support\Sampul::variant(seed): int` ·
  `::key(bidang): string` · `::templateClass(bidang, seed): string` · `::poster(?Competition): ?string` (asks
  `CompetitionPolicy::view`, so no request is made for a picture the route would refuse).
- `<x-sth.lomba-card … :seed :poster>` · `<x-sth.stat-tile … zero="quiet|hide|show">` · `<x-sth.table … table-class>`.
- CSS: `.spanduk`, `.spanduk-tinggi`, `.sampul-<bidang>-<1..3>`, `.sampul-pekat`, `.chip-wrap`; utilities `text-md`
  (15 px), `rounded-tag`, `border-line-strong`, `text-link`.
- `Siswa\TurMenu::desktopTips()` positions = sidebar metrics (first item 112 px, items 48 px apart, second section
  394 px). Measure again when `.nav`, `.nav-sec`, the sidebar gaps or `.tabbar` change.

## Commands verified
- `docker compose run --rm --no-deps node sh -c "npm run build"` → OK (CSS 101 kB, 24 cover SVGs hashed).
- `./vendor/bin/pint --dirty` → pass. Full suite: TEST-RESULTS below.
- Browser (headless Edge 154, local demo data, all seven roles + guest): 77 pages at 1440 px and at 390 px, plus 50
  dialogs, popups, tour steps and special states (first login, team invitation) = 204 screenshots. Measured on every page: no sideways scroll, one font family,
  sizes 13 · 14 · 15 · 16 · 18 · 20 · 24 · 28 · 36 · 44 only, no text below 13 px, no gradient, no rotated
  element. Exempt and unchanged: the paper preview of a letter (Arial, small type, tilted "PREVIEW" mark).

TEST-RESULTS (2026-10-07, final code): full suite `--parallel --processes=4` → **2315 passed, 1 skipped (13923 assertions)**, exit 0 (559 s); `pint --test` clean (664 files); `composer audit` clean; `npm run build` OK. The skipped test is the slow demo-file render (`STH_DEMO_FULL=1`).

TEST-RESULTS (2026-10-07, after the lead's feedback below, capsules included): full suite `--parallel --processes=4` → **2320 passed, 1 skipped (13992 assertions)**, exit 0 (511 s, 2026-10-08; with the brand mark, the Portofolio capsules and the banner count); `pint --dirty` pass; `npm run build` OK. Browser: the text scan (text that collides, is cut or leaves its box, sideways scroll) on 76 pages at 1440, 1366, 1280, 390 and 360 px; the capsules, both lomba heads (with a poster, with a drawing), Undangan tim and the Kelola lomba form looked at by hand at 1440, 1280, 820, 390 and 360 px.

## After the lead's check on localhost (2026-10-07, two rounds of feedback)
- **No floating cards**: the public home hero is one `.spanduk` banner (the three sample cards, then one card, are
  gone; "Masuk" only in the header); the login panel lost its three sample cards (brand, headline, motif).
- **One way to each place**: scanned every page for a label that appears twice with the same target. Removed: the
  banner button "Cari lomba", the links "Katalog" and "Peringkat" on Siswa › Beranda, "Lihat siswa per bidang"
  on the public home (the tiles go there), "Dashboard" on Kepala Sekolah › Persetujuan, "Surat atas nama saya" on
  Kepala Sekolah › Laporan, the column "Pintasan" on Admin › Status sistem, and the empty-state buttons that
  repeated the page's own button (Guru › Lomba "Upload info lomba", Siswa › Cari lomba "Usulkan lomba"). Kept on
  purpose: breadcrumbs, "see all" links next to a short list, dashboard tiles that link to their queue, the
  empty-state "Cari lomba" of a student without any lomba.
- **Same-size boxes**: `auto-rows-fr` on the 16 stat-tile grids, the bidang tiles of the public home and the grids
  of `<x-sth.radio-pick columns>` (capaian picks in Verifikasi hasil and Laporkan hasil).
- **Top three of Peringkat** (publik and siswa, Siswa and Kelas): medal + name in one row, points and count below a
  line; silver and bronze tokens `--color-medal-2`, `--color-medal-3`; no avatar in these cards.
- **Second sweep for the same kinds of problem** (every page, automatic): repeated buttons, uneven boxes, and new:
  text that touches or covers other text, is cut, or leaves its box, at 1440, 1366, 1280, 390 and 360 px (the first
  round only looked at 1440 and 390). Found and fixed: Siswa › Beranda banner and points card now share one grid
  row (same height) and the trackers of the "Lomba saya" cards line up; on phones the siswa top bar lost its
  search icon (the tab "Lomba" opens the same page); `.pick` text is centred, two-line tiles are `.pick-2` and no
  longer grow when chosen, the ten capaian show two per row on phones (`phone-columns`); `<x-sth.rumus-poin>` keeps
  "Penyelenggara" inside its tile (13 px labels were wider than the mockup's 11 px); the year labels of the public
  "Perkembangan per tahun" chart touched each other (column 260 → 300 px); at 1280 / 1366 px the five facts of
  Detail lomba overlapped, the talent type tiles and a "Perlu perhatian" link left their box (container queries,
  wrapping). After it: no finding on 76 pages × 5 widths besides three things that are meant to overlap (chart axis
  labels beside the plot, the avatar stack, the "PREVIEW" mark on the letter paper).

## Capsules (2026-10-07, the lead's third round: "poster di kiri, poster portrait, lalu di kanannya keterangannya")
- **`<x-sth.lomba-card>` is a capsule**: the cover stands on the left in one 3:4 box (`<x-sth.sampul bentuk="tegak">`,
  104 px wide on a card under 440 px, 148 px from there; a poster fills the box, one `<img class="sampul-isi">`), right of
  it status, title, tags, penyelenggara, date and place; under both the registered students and "Lihat detail",
  stretched over the card. Its three grids (Siswa › Cari lomba, Beranda › Rekomendasi, Guru › Lomba) take their
  columns from their own width (`@container` + `@min-[900px]:grid-cols-2 @min-[1360px]:grid-cols-3`): two per row
  from a 1280 px laptop, one on a phone. `Siswa\Beranda` recommends up to four; the fourth shows only where it
  completes a row of two.
- **`<x-sth.lomba-head>` (new, 41 components)** heads Detail lomba and Undangan tim: the poster whole on the left
  (`bentuk="utuh"`; with `preview` in the `poster` array the poster and "Perbesar" are one link to the preview
  popup, the href stays the download), beside it the slot (status, name, penyelenggara, who registered) and the key
  facts (`<x-sth.facts plain>`); on a narrow card the facts run under the poster. The old "Lihat poster" button and
  the tingkat tag on the cover are gone (the fact "Tingkat" says it).
- **Template drawings in a portrait box**: the same 24 SVG files; a `background-position` per drawing frames its
  subject (`app.css`, "A template drawing is 360 x 160 …"). `/_dev/components` shows the 12 portrait crops, both
  tones, and a sample head.
- Kesiswaan › Kelola lomba: the upload hint says a portrait poster (A4 or 4:5) fits best, and "Sampul di katalog
  sekarang" shows the card's own box.
- **Portofolio** (the lead: "iya dong di porto juga"): the prestasi cards are capsules too (cover left; hasil, name,
  tags, penyelenggara, date; points and the certificate under both), two per row from a 700 px list.
- Not changed: the public "Prestasi terbaru" cards and the Humas queue keep a wide band (`bentuk="lebar"`, drawings only).

## Banner count (2026-10-08, the lead: "masih ada 5 lomba yang bisa kamu daftar")
- Siswa › Beranda said "Ada 6 lomba yang bisa kamu ikuti sekarang" and counted every open lomba, also one the
  student had already registered for. Now `Siswa\Beranda::openFor($student)` (open, and the student is not in a
  running registration of it as ketua or as a member who has not declined) feeds both the sentence and the
  recommendations: "Masih ada N lomba yang bisa kamu daftar."; at 0 either "Kamu sudah terdaftar di semua lomba
  yang sedang dibuka." or, when nothing is open, the old "Belum ada lomba yang dibuka. …". Test: `SiswaScreensTest`
  ("counts on the banner only …").

## Brand mark (2026-10-07, D-120: the lead's request "pasanglah menggantikan logo centang")
- `smaga.jpeg` (233 × 320, white ground, put in the repo root by the lead and left there) → the white ground that
  touches the edge was made transparent, nothing redrawn: `resources/images/brand/lambang-sma3.png` (119 × 160,
  palette PNG, 8 kB), `public/favicon.ico` (16/32/48), `public/icon-192.png`, `public/apple-touch-icon.png` (180, on
  white); `public/favicon.svg` is gone. The script is outside the repo (`ui-audit-smaga/impl/lambang.py`).
- `<x-sth.brand>`: `.brand-mark` (a CSS background, so Blade builds no asset address and tests need no Vite
  manifest) instead of the `shield` icon; on ink a white tile. `aria-label` = "Lambang SMA Negeri 3 Semarang".
- @user: the file is small. Ask the school for a larger or vector crest before it is shown above about 56 px (a
  print header, a splash screen). `smaga.jpeg`, `sma_unggul.jpeg` and `spectacular.jpeg` in the repo root are
  untracked and were not touched: decide whether they belong in git.

## Assumptions / deviations from the spec
- The audit promised six type sizes (13, 14, 16, 18, 20, 28). Built: ten. 15 px stays for controls and table cells, as
  in the audit's simulation (at 16 px a dense table such as Talenta gets tighter on a 1366 px laptop; not measured), 24 px is the page title on phones, 36 / 44 px are the points
  and the hero headline.
- "Zero stat tiles are hidden" became: quiet at 0 by default (gray icon, muted number), hidden only for warning
  tiles on Status aktivasi, Arsip otomatis and Import siswa. A dashboard keeps its four tiles in place.
- The cover variant comes from the lomba id, so two neighbours of one bidang can still share a drawing (one in
  three). The audit's "never twins" would need the list position, and then a lomba would change its picture.
- "Cabang ilmu" = the four bidang (D-11). Finer categories need a new field on lomba.
- Kelola lomba: the icon tile per row is gone (the bidang column says it) and the row actions carry text.
- The e-flyer templates of the audit plan no longer exist (D-107). The portfolio PDF got the new colours only
  (dompdf keeps DejaVu). The mockup boards are not redrawn. The school crest was not used at first (lead, 6 Oct); since D-120 it is the brand mark.
- Posters only inside the app: public pages and Humas show the bidang drawing.

## Requests for other owners
- @user: D-118 is *sementara* on three points (`UI3-A.md`). `FortifyServiceProvider`, `routes/web.php`,
  `FileController`, `StoreUpload` and the seeders were changed on your request; please review them as backend owner.
- @I: `storage/app/private/sampul/` is derived data. `npm ci` picks up the font change; no Docker or CI change.
- @S: the screenshots for the PR are not in the repo (they hold demo names only, but they are 204 files).

## Follow-ups (not done)
- Preview popup for the cover in Kesiswaan › "Periksa lomba" and Guru › Lomba; posters on public pages.
- Publik › Prestasi per bidang still opens with an empty right panel (audit note: show the top student).
- Kepala Sekolah › Laporan and the letter PDFs still have no "sedang dibuat" text (#67).
- Menu name vs page title, formal words in staff mails (`UI3-B.md`).
- At 360 px the tab label "Lomba saya" takes two lines (it fits, the other four take one). The dashboards of
  Kepala Sekolah, Kesiswaan and Admin still show a queue both as a stat tile and in "Perlu perhatian": kept, the
  tile is the count and the list says what is most urgent; the lead may want one of them removed.
- The login panel headline "Prestasimu, tercatat rapi." addresses students; staff see it too.
