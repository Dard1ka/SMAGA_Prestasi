# UI3 — visual direction v3 "Tinta dan Sampul" (D-117…D-119)  ·  status: done (not committed)

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
- `<x-sth.sampul :bidang :seed :src tone="terang|pekat" alt class>` · `App\Support\Sampul::variant(seed): int` ·
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
  (dompdf keeps DejaVu). The mockup boards are not redrawn. The school crest is still not used (lead).
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
- The login panel headline "Prestasimu, tercatat rapi." addresses students; staff see it too.
