# W1-F — project documentation  ·  status: done

## Files (owned by me)
- `CLAUDE.md` (350 lines) — project context, 12 hard rules, Docker commands, architecture map, conventions,
  design system, decisions not to undo + lead decisions 2026-10-01, open questions, **Status** + open tasks
  for the lead, ownership + agent protocol. Replaces the Laravel Boost boilerplate (no remote PHP installs).
- `AGENTS.md` — pointer to CLAUDE.md + the critical rules, for non-Claude agents.
- `README.md` — Bahasa Indonesia: what STH is, Docker quick start (Windows/Linux), dev URLs, demo accounts
  (local only), docs index.
- `CONTRIBUTING.md` — Bahasa Indonesia: branches `<type>/<area>-<topic>`, commits, PR checklist (tests, Pint,
  data, security §3 pointers, screenshots vs mockup, infra), review/merge.
- `docs/TEAM.md` — Bahasa Indonesia: user 40 / S 35 / I 25, milestones M0–M4 + acceptance criteria, hand-off.
- `docs/UI-MAP.md` — 24/24 boards + non-mockup screens → route name/URL → Livewire class → permission/policy
  → Actions → owner → milestone/wave; reconciled with W1-C (auth routes/classes) and W1-A (permissions).
- `docs/dev-notes/README.md` — notes protocol + template.

## Commands verified (2026-10-01)
- `docker compose run --rm --no-deps app php artisan test` → 394 passed (1167 assertions), SQLite
- `docker compose run --rm --no-deps app ./vendor/bin/pint --test` → 1 issue: `bootstrap/providers.php` (not mine)
- `docker compose config -q` → OK · `docker compose -f docker/compose.prod.yaml config -q` → exit 0
- `docker compose run --rm --no-deps app sh -c "php -v; php -m; php artisan --version"` → PHP 8.4.26, pdo_sqlite +
  pdo_mysql, Laravel 13.34.0

## Conflicts found (documented in CLAUDE.md §8/§10, not mine to edit)
- Landing route names: W1-C `/beranda` redirects to `<area>.beranda`; W1-D `Navigation` looks for
  `kepsek.dashboard`, `admin.dashboard`, `tu.surat.index`, `humas.publikasi.index`, `guru.binaan`.
  UI-MAP now uses `<area>.beranda`; Navigation needs those as first candidates (wave 2, S).
- Stale text: SECURITY `sm4g4_DD-MM-YYYY`, `STH_OTP_DEV_BYPASS`, port 8081, vhost inline comments; DESIGN-SPEC
  + mockup 30 days / WICE 24 Okt (demo = 8 Nov 2026); ARCHITECTURE Livewire 3 / Intervention v3, D-11/U-02 refs.

## Requests for other owners
- @user: log the 2026-10-01 decisions in DECISIONS.md and fix the stale refs above; decide K10 vs the current
  `ResetStudentAccount` (initial password); dev `queue` worker should drain `otp,mail,default`.
- @S: when creating `<area>.beranda` routes, add them to `App\Support\Navigation` candidates; keep UI-MAP in sync.
