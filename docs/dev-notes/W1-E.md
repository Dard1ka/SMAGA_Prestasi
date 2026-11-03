# W1-E — DevOps (prod image, prod compose, Apache vhost, CI, deploy/backup scripts) for I  ·  status: done

## Files (owned by me)
- `docker/Dockerfile.prod` — stages php-ext → vendor (`--no-dev`, authoritative classmap) → assets (node 24, `npm ci`, vite) → `web` (nginx-unprivileged 1.30) → `app` (php-fpm 8.4 bookworm, www-data, code root-owned). Base images pinned by digest.
- `docker/php/{php.prod.ini,fpm-pool.prod.conf,entrypoint.prod.sh,healthcheck.sh}` — prod ini (opcache.validate_timestamps=0, 20M/25M uploads), pool (12 children, `disable_functions` FPM-only, ping), entrypoint (config/route/event cache; `view:cache` on app, non-fatal), FastCGI healthcheck
- `docker/nginx/prod.conf` — only `/index.php` executes; dotfiles/`*.env|log|sql|lock…` → 404; `/build/` immutable cache; dynamic resolver for `app`
- `docker/compose.prod.yaml` — project `sth`: app/queue/scheduler/web/db, read-only + cap_drop ALL + no-new-privileges, RAM ≈ 4.5 GB total, web on `127.0.0.1:${STH_HTTP_PORT:-8080}`, db without ports, networks `edge` (172.30.10.0/24, gw .1) / `backend` (internal) / `egress`
- `docker/mysql/initdb/10-app-user.sh` — creates `sth_app` (SELECT/INSERT/UPDATE/DELETE only); `sth_migrate` (ALL on sth.*) for migrations; root only for backup/restore
- `docker/apache/prestasi.sman3-smg.sch.id.conf` — host vhost sample (80→443 except ACME webroot, TLS 1.2/1.3, proxy, header stripping, HSTS w/o includeSubDomains, http/1.1 only)
- `docker/env.production.example` — prod `.env` template (placeholders)
- `scripts/deploy/{deploy,backup,restore}.sh` — see DEPLOY.md §8/§11/§12/§14
- `.github/workflows/ci.yml` (jobs php, frontend, audit, docker), `.github/dependabot.yml`, `.dockerignore`, `docs/DEPLOY.md` (Bahasa Indonesia)

## Contracts other areas can use
- Images `sth-app:<git sha12>` / `sth-web:<git sha12>`; `docker build -f docker/Dockerfile.prod [--target web] .`
- Queue worker: `queue:work --queue=otp,mail,default --tries=3 --backoff=10 --max-time=3600 --memory=256` → dispatch to those queue names (or default).
- Runtime DB user has **no DDL/TRUNCATE** (`TRUNCATE` needs DROP): use `delete()` in app code; schema changes only via migrations.
- Env vars added: `STH_TRUSTED_PROXY` (=172.30.10.1), `STH_CACHE_VIEWS`, `STH_SKIP_OPTIMIZE` (entrypoint only).
- Maintenance mode (`artisan down`, file driver) lives in the shared `storage` volume, so it spans app/queue/scheduler.

## Commands verified (2026-10-01, Docker Desktop 29.4 / Compose 5.1)
- `docker build -f docker/Dockerfile.prod -t sth-app:prod .` → OK (PHP 8.4.26, Laravel 13.34.0, `php-fpm -t` OK); `--target web` → OK (`nginx -t` OK)
- `docker compose -f docker/compose.prod.yaml config -q` → exit 0
- Full stack in throwaway project `sth-localtest` (port 18080): all services healthy; migrations as `sth_migrate` (21) OK; `about`: production, debug OFF, config/events/routes/views CACHED; `id` app=www-data, db=mysql(999), web=nginx
- HTTP: `/up` `/` 200; `/.env` `/.git/HEAD` `/storage/logs/laravel.log` `/composer.lock` `/info.php` `/vendor/autoload.php` → 404; cookie `__Host-sth_session; secure; httponly; samesite=lax; path=/`; nginx sees client = `172.30.10.1` (edge gateway) → TrustProxies value confirmed
- `sth_app`: SHOW GRANTS = DML on `sth`.*; CREATE/TRUNCATE/DROP denied
- `backup.sh` → encrypted `db.sql.gz.age` + `private.tar.gz.age` + SHA256SUMS; `restore.sh --target test` → checksums OK, 39 tables imported in isolated project, archive verified, test project removed
- `deploy.sh` (scratch git clone, non-root user): fetch/checkout → re-exec → build → pre-deploy backup → maintenance → migrate → recreate `--wait` → `up` → smoke 200 → history → image prune. `deploy.sh --rollback <tag>` OK.
- Apache vhost in `httpd:2.4` (2.4.68, not 2.4.29): `httpd -t` Syntax OK; ACME 200, redirect 301 keeps path+query, TLS ≤1.1 refused, HSTS on errors; backend receives `X-Forwarded-For: <client>` only, spoofed X-Forwarded-Host/Forwarded/X-Real-IP/Proxy removed
- shellcheck (all scripts) → clean; actionlint `ci.yml` → clean; CI boot-guard step logic checked with the dev image (flag=false exit 0, flag=true exit 1)
- Test containers/volumes/images removed afterwards; `sth-app:prod`, `sth-web:prod` kept.

## Assumptions / deviations from the spec
- Port **8080** (ARCHITECTURE §9, lead) instead of SECURITY's 8081; overridable via `STH_HTTP_PORT`.
- `STH_OTP_TEST_MODE` is **not** in the prod template (the task said `=false`): CLAUDE.md rule 7 forbids the flag in prod env files; `deploy.sh` refuses any such line; compose forces `APP_ENV=production`, `APP_DEBUG=false`.
- Runtime image is Debian bookworm (same as dev) instead of SECURITY §3k's alpine sample; nginx-unprivileged **1.30** (current stable) instead of 1.28.
- db runs as `user: 999:999` with `cap_drop: ALL` (no `cap_add` needed) and `--disable-log-bin` (daily dumps instead of binlog/PITR).
- Apache also strips the client's `X-Forwarded-For` (Apache re-adds the real IP), stricter than SECURITY §3e.
- `view:cache` failure only warns (observed once: `[layouts::auth]` not found while W1-D was mid-work; fine on the next build).
- Backups use `age` public-key encryption (gpg optional) instead of restic; retention 14 days local, optional rclone off-site.
- Guide written to `docs/DEPLOY.md` (task) — CLAUDE.md §11 lists `docs/deploy/` for I; move if the lead prefers.
- CI has no Trivy/gitleaks yet; actions pinned to majors (checkout v7, setup-php v2, setup-node v7, cache v6, setup-buildx v4, build-push v7).

## Requests for other owners
- @W1-C / lead (`bootstrap/app.php`, add `use Illuminate\Http\Request;`), inside `withMiddleware`:
  ```php
  // Apache (host) → 127.0.0.1:8080 → docker-proxy → nginx → php-fpm: REMOTE_ADDR = gateway of compose network `edge`.
  $middleware->trustProxies(
      at: [env('STH_TRUSTED_PROXY', '172.30.10.1')],
      headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
  ); // never X-Forwarded-Host/-Prefix (SECURITY §3e)
  $middleware->trustHosts(
      at: fn (): array => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'],
      subdomains: false,
  ); // production: only prestasi.sman3-smg.sch.id; Laravel skips the check in local/testing
  ```
  Until then `deploy.sh` warns "foreign Host header was accepted" (verified: evil Host → 200 today).
- @lead: add `/secrets` and `/backups` to `.gitignore` (server keeps secrets in the checkout, untracked); mark `scripts/deploy/*.sh` and `docker/php/*.sh` executable in git (`git update-index --chmod=+x`); keep `docker/mysql/initdb/10-app-user.sh` non-executable (works either way).
- @lead (SECURITY.md owner): the §3e vhost sample has end-of-line `#` comments (`SSLUseStapling off  # …`, `ProxyPreserveHost On  # …`) — Apache has no inline comments, so `configtest` fails; `docker/apache/*.conf` is the corrected version. Also 8081→8080, OTP flag name.
- @backend: a CLI command to create the first Admin account (DEPLOY.md §8 has a placeholder). Protecting `activity_log` from UPDATE/DELETE (SECURITY §3h) needs per-table grants instead of the DB-wide grant in `10-app-user.sh`: tell me the table list when ready.

## Follow-ups (not done)
- Staging test on a real Ubuntu 18.04 / kernel 4.15 / Apache 2.4.29 host (static Docker binaries, cgroup v1, ufw DOCKER-USER).
- Trivy image scan + gitleaks in CI with reviewed pinned versions; pin actions to SHAs; E2E tests; user guide.
- SMTP noreply (D-15) — DEPLOY.md §9 placeholder.
