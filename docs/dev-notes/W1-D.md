# W1-D — design system → code (tokens, `<x-sth.*>`, layouts, fonts, showcase)  ·  status: done

## Files (owned by me)
- `resources/css/app.css` — Tailwind v4 `@theme` (default palette OFF: only spec colours) + helmet classes as real CSS
  (`.btn* .icon-btn .card .chip .c-* .b-* .cover-* .input .pick .seg* .nav* .badge .cnt .avatar .stack .tbl .trk* .sg* .rt* .drop .stab .tabp .fchip .pub-* .tabbar .tab* .dlg* .toast* .lnk .title-page/-section/-card .muted .num`)
- `resources/js/app.js` — CSP-safe vanilla behaviours via event delegation (no inline JS, no Alpine expressions)
- `resources/views/components/sth/*.blade.php` — 38 components, each documented at the top (props + examples)
- `resources/views/layouts/{app,public,auth,mobile}.blade.php` + `layouts/partials/head.blade.php`
- `app/Support/Navigation.php` — menus per role (spec §4), current item, badges, tab bar, public links, `initials()`
- `routes/dev.php`, `resources/views/dev/{components,public,auth,mobile}.blade.php` — showcase (local only)
- `package.json` (+ `@fontsource/plus-jakarta-sans`, `@fontsource/urbanist`, tailwind ^4.1), `package-lock.json`, `vite.config.js` (bunny fonts removed, `assetsInlineLimit: 0`)
- `tests/Feature/DesignSystem/{ComponentsTest,NavigationTest,LivewireLayoutTest}.php`

## Contracts
Tokens (utilities): `bg-page surface subtle hover` · `border-line line-soft line-input line-dashed` · `text-ink ink-2 muted placeholder`
· `primary primary-hover primary-tint primary-on-tint primary-soft focus` · bidang `sains|riset|seni|olahraga` + `-bg -cover -cover-2 -chart`
· `gold gold-bg medal` · status `green orange red gray blue` + `-bg` · `font-display` · `rounded-control(12) -tile(14) -card(20)`
· `shadow-card primary pop drawer float`. Data-driven sizes without `style=""`: `w-pct-{0..100}`, `h-pct-{0..100}`.

Layouts (Livewire 4 `layouts::` namespace; named slots from a Livewire page are forwarded):
- `<x-layouts::app title subtitle role user badges notifications search active preview>` slots `actions`, `lead`
  (Livewire: `#[Title('…')] #[Layout('layouts::app', ['subtitle' => '…'])]`; `<x-slot:actions>` inside the page view works)
- `<x-layouts::public title current="beranda|prestasi|peringkat" robots>` · `<x-layouts::auth title variant="split|simple">`
- `<x-layouts::mobile title subtitle back back-label tabbar>` slots `header`, `action` (sticky bottom bar)

Components `<x-sth.*>`: `brand icon button icon-button link chip badge card stat-tile field input select textarea checkbox
radio-pick segmented file-drop table empty-state alert avatar avatar-stack tabs tab-panel tracker rumus-poin lomba-card
bidang-tile facts page-header toast flash modal sidebar topbar tabbar public-header public-footer`. Highlights:
- forms read `$errors` by `wire:model` or `name` → `aria-invalid` + `aria-describedby`; `class` goes to the wrapper.
- `chip`/`bidang-tile`/`lomba-card` take `App\Enums\Bidang` (or its value); chip tone names = `RegistrationStatus::tone()`.
- `tracker :status="$registration->status"` (phase from `RegistrationStatus::phase()`; menunggu_laporan = action, selesai = done) or `:phase :state`.
- `rumus-poin :tingkat :capaian :penyelenggara :total` formats with `App\Domain\Points\PointFormatter`; never computes points.
- `modal id title variant="dialog|sheet|drawer"` = native `<dialog>` (+`wire:ignore.self`). Open: `data-sth-open="id"`; close:
  `data-sth-close`; Livewire: `dispatch('sth-modal-open', id: 'x')`, `sth-modal-close`, `sth-toast` (message, tone).
- flash: `session()->flash('toast', 'Teks')` or `['tone' => 'error', 'message' => …]`, `session('error')`.
- Navigation: `Navigation::for($user, $role, $badges, active: 'key', preview: bool)`; candidates per item = UI-MAP name first,
  then the brief's name (`siswa.lomba.index` → `siswa.katalog`, …); missing route → `aria-disabled`, no href.
  Badges only from the layout prop or `Navigation::resolveBadgesUsing(fn ($user, $role) => ['siswa.lomba-saya' => 2, …])`.
- Topbar search = GET `?q=` to the role's list page (siswa `siswa.lomba.index`, kesiswaan `kesiswaan.lomba.index`, …).

## Commands verified
- `docker compose run --rm node sh -c "npm install && npm run build"` → OK in 4.3 s: 8 self-hosted woff2/woff (latin), CSS 72.7 kB, JS 7.4 kB; no external/data: URLs
- `docker compose run --rm --no-deps app ./vendor/bin/pest tests/Feature/DesignSystem` → 36 passed (140 assertions)
- `… ./vendor/bin/pint app/Support/Navigation.php routes/dev.php tests/Feature/DesignSystem` → pass · `php artisan view:cache` → OK
- Showcase checked in headless Chrome at 1440/1280/390 against Beranda-Siswa, Katalog, Detail, Kelola-Lomba, Masuk, Main, Lapor-Hasil-Mobile.
  `docker compose up -d app web` left running → http://127.0.0.1:8080/_dev/components (`?role=kesiswaan`, `?only=form`).

## Assumptions / deviations
- Kesiswaan menu label "Usulan lomba" (spec: "Usulan siswa"): D-27 queue holds uploads from every role; links `kesiswaan.lomba.index?tab=verifikasi`.
- Kesiswaan menu has "Import siswa" (`kesiswaan.import`, D-26); spec §4 menu lacks it.
- Showcase uses WICE report deadline **8 Nov 2026** (D-25: final 24 Sep + 45 days), not the mockup's 24 Okt.
- Modal/sheet are native `<dialog>` + vanilla JS instead of Alpine: works with `livewire.csp_safe` on or off.
- Status chips for points use `gold` only; body text 15px/500 (16px below 1024px); inputs 16px on phones (no iOS zoom).
- `@source` limited (`source(none)`): views, js, app/, pagination views. Classes used elsewhere must live in those paths.

## Requests for other owners
- @user (config owner): set `livewire.csp_safe = true` once no screen needs Alpine expressions → SecurityHeaders drops `'unsafe-eval'`.
- @wave 2: never use `style=""` or `:style` strings (CSP blocks them); build screens from `<x-sth.*>` + tokens, not mockup inline styles.
- @W1-C: login view could use `<x-sth.alert>` for the "Baru pertama masuk?" note (mockup) — optional.

## Follow-ups (not done)
- Favicon/brand SVG in `public/` (owner: public/). Chart components (bars/donut) for dashboards — wave 2 (use `h-pct-*` or SVG).
