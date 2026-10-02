# PERF1 — slow page loads in local development (6–16 s → ~0.7 s)  ·  status: done (one follow-up open)

## The complaint
Every page change took "terlalu lama". Measured on the running dev stack, three public pages, three
runs each: `/` 12–16 s, `/prestasi` 6.5 s, `/peringkat` 6.4 s. TTFB equalled total time, so none of it
was network or asset download — it was all server time.

## Root cause (measured, not guessed)
Layered probes ruled out the usual suspects first:

| Layer | Time |
|---|---|
| nginx static file | 7–23 ms |
| bare PHP through php-fpm (one file) | 9–18 ms |
| MySQL connect | 13–47 ms |
| 10 MySQL queries | 13–33 ms |
| **Laravel framework boot (713 files)** | **3 437 – 10 744 ms** |

So the cost was loading PHP files, not the database and not application code. The reason:

```
stat() on the bind-mounted repo (Windows drive)   5.938 ms per file
stat() on the container's own filesystem          0.002 ms per file   (~3000x)
```

`vendor/` alone holds 11 429 PHP files. With `opcache.validate_timestamps = 1` opcache re-`stat()`s
every cached file, so a request paid thousands of 5.9 ms syscalls across the Windows/WSL2 filesystem
boundary. Controlled check — only that one setting changed, large opcache memory in both runs:

- `validate_timestamps = 1` → `/` 7.2–15.5 s
- `validate_timestamps = 0` → `/` 0.79–0.95 s

Laravel's own `config:cache` / `route:cache` / `view:cache` added almost nothing on top; the stat
storm was the whole problem. The default `opcache.max_accelerated_files` (10 000) was also below the
file count, so opcache was evicting and recompiling.

## Files (owned by me)
- `docker/php/php.dev.ini` (new) — dev-only opcache/realpath tuning. The header explains the trade-off
  and the single line to change after the repo moves to a Linux filesystem.
- `compose.yaml` — mounts the above as `conf.d/zzz-sth-dev.ini` (loaded after the image's `zz-sth.ini`).
  No image rebuild needed: edit the ini, `docker compose restart app`.
- `reload.bat` (new) — `docker compose exec -T app sh -c "kill -USR2 1"`, measured at 783 ms.
- `app/Support/Links.php` (new) + `tests/Feature/Support/LinksTest.php` (new, 39 passing).
- `resources/views/components/sth/{sidebar,tabbar,topbar,public-header,link,button,icon-button,brand}.blade.php`
  — emit the attribute `Links::navigateAttribute()` returns.

## What changed, and why it is two separate fixes
1. **Server time** — opcache no longer re-checks timestamps, and its limits now fit the project.
   `/` 0.87–1.02 s · `/prestasi` 0.63–0.73 s · `/peringkat` 0.79–0.94 s.
2. **Navigation** — before this, 167 `href=` in the views carried exactly 3 `wire:navigate`, so every
   menu click was a full browser reload that re-downloaded the CSS/JS bundle and the self-hosted
   fonts. `resources/js/app.js` was already written for SPA swaps (it ends with
   `document.addEventListener('livewire:navigated', boot)`), so only the attribute was missing.
   The chrome components now emit the attribute `Links::navigateAttribute()` picks.

   **Prefetch is deliberately not global.** The public pages are `throttle:60,1` per IP
   (`routes/web.php`, SECURITY §3m-7) and a school browses from one public address, so prefetching
   them would roughly double the requests counted against that limit and could hand students 429s.
   Links to `/`, `/prestasi*`, `/peringkat*` and `/verifikasi-surat*` therefore get plain
   `wire:navigate`; everything else (the role areas, `/login`, `/notifikasi`, …) gets
   `wire:navigate.hover`. The rate limit itself was left untouched.

## Contracts other areas can use
- `App\Support\Links::navigable(?string $href): bool` — true when a link stays in this app and answers
  with HTML. False for `/berkas/...` (FileController streams files — AGENTS.md rule 4 routes every
  download through it), other hosts, `mailto:`/`tel:`/`javascript:`/`data:` and bare `#`.
  `/berkasku` is still navigable: the check is per path segment, not `str_contains`.
- `App\Support\Links::prefetchable(?string $href): bool` — navigable **and** not one of the
  rate-limited public paths.
- `App\Support\Links::navigateAttribute(?string $href): ?string` — `'wire:navigate.hover'`,
  `'wire:navigate'`, or `null`. This is the one the components call.
- `<x-sth.{link,button,icon-button,brand}>` take `navigate` purely as an opt-out: leave it off and
  `Links` decides, pass `:navigate="false"` to force a normal browser navigation. A caller that writes
  its own `wire:navigate*` attribute is not double-tagged. PDF/XLSX exports are returned by Livewire
  actions (`wire:click`), never by a link, so they were never at risk.

## Trade-off to know about (only while the repo sits on a Windows drive)
`validate_timestamps = 0` means opcache does not re-read a file it has cached. Verified by experiment:
editing a `.blade.php` and reloading the page showed the **old** text; after `reload.bat` it showed the
new one. So **both `.php` and `.blade.php` edits need `reload.bat`** (783 ms). CSS and JS are
unaffected — Vite/nginx serve those, not PHP. A cold container also pays a one-time ~45 s on the first
page while Blade compiles and opcache fills.

## Open follow-up — the real cure
Moving the repo onto a Linux filesystem removes the cause instead of working around it: stats become
effectively free, so `validate_timestamps` goes back to `1` in `docker/php/php.dev.ini`, `reload.bat`
stops being necessary, and pages should land well under the current ~0.7 s.

This was attempted and is **blocked**: the `Ubuntu` WSL2 distro is registered but its disk is gone
(`CanonicalGroupLimited.Ubuntu_.../LocalState/` is empty, `wsl -d Ubuntu` fails with
`ERROR_FILE_NOT_FOUND`). Only Docker's own internal distro exists. Reinstalling it needs an interactive
first-run and a Docker Desktop "WSL integration" toggle, so it was left for a human.
