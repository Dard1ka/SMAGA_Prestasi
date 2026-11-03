# UI3-A — cover pictures: `files.cover`, thumbnails, demo posters  ·  status: done

Part of UI3 (`UI3.md`, D-118). Backend files only; touches the lead's area on the lead's request of 2026-10-07.

## Files
- `app/Http/Controllers/FileController.php` — `cover()`, `coverUrl()`, `COVER_MAX_AGE`, docblock + route list
- `app/Domain/Files/CoverThumbnail.php` (new) — the small JPEG of a cover (all static)
- `app/Domain/Files/StoreUpload.php` — `discard()` also calls `CoverThumbnail::forget()`
- `routes/web.php` — one route
- `app/Providers/FortifyServiceProvider.php` — `COVER_PATH` + a `Login` listener (see "Assumptions")
- `database/seeders/Support/DemoCoverArt.php` (new), `database/seeders/Support/DemoFiles.php` (`cover()`),
  `database/seeders/DemoSeeder.php`, `database/seeders/Support/Demo/DemoFileRefresher.php`,
  `app/Console/Commands/RefreshDemoFiles.php` — demo posters
- tests: `tests/Feature/Files/CoverPictureTest.php` (new, 45), `tests/Feature/Demo/DemoCoversTest.php` (new, 7),
  `tests/Feature/Demo/RefreshDemoFilesTest.php`, one case in `tests/Feature/Errors/LogoutAndIntendedTest.php`

## Contracts other areas can use
- route `files.cover` → `GET|HEAD /berkas/sampul/{file}`, group `auth` + `active`, plus `activated`, `staff.2fa`,
  `throttle:240,1,berkas-sampul`.
- `FileController::coverUrl(?string $fileId): ?string` — null for null / '' / missing route. No auth check: use
  `App\Support\Sampul::poster($competition)`, which asks `CompetitionPolicy::view` first.
- `CoverThumbnail::MAX_SIDE` 640 · `QUALITY` 80 · `path(File)` = `sampul/<file id>.jpg` on the private `local` disk ·
  `etag(File)` · `bytes(File): ?string` · `forget(File)`.

| case | answer |
|---|---|
| allowed GET / HEAD | 200 `image/jpeg` (HEAD: same headers, no body) |
| `If-None-Match` matches | 304, sent after the policy and original-exists checks, before any read |
| purpose ≠ cover, policy denies, original missing or not a decodable JPEG/PNG/WEBP, unknown id | 404 |
| guest · frozen / archived · activation unfinished · staff without required 2FA | 302 → login · login · `/aktivasi` · `/akun/2fa` |
| more than 240 per minute per user | 429 (own counter) |

Headers on 200: `Content-Type: image/jpeg`, `Content-Disposition: inline`, `Content-Length`, `nosniff`, CSP
`default-src 'none'; sandbox`, `ETag`, `Cache-Control: max-age=86400, private`, the global `X-Frame-Options: DENY`.
**Not audited** (tested: no `activity_log` row for GET, HEAD, 304, 404; `files.show` on the same file still logs).
The variant is a progressive JPEG, never enlarged, transparency → white, written to a `.tmp` name and renamed.

## Demo data (local only, D-106 limits)
Seven of the eight published demo lomba get a drawn poster (960 × 1200 JPEG, 94–134 kB, GD shapes + the lomba's
short name, year, date, place; "CONTOH" as a stamp and in the bottom band; no organizer, person or logo); KRENOVA
2027 has none on purpose so the catalog also shows a template card. `demo/` now holds 105 files (98 PDFs + 7
covers). Tests and `STH_DEMO_FILES=placeholder` keep a 16 × 20 gray JPEG. `sth:demo:refresh-files` regenerates the
posters in place (`--berkas="sampul-*"` only them); `--lengkapi` adds missing covers to an already seeded database
(run on the local database on 2026-10-07).

## Commands verified
- `pint` on the changed files → pass.
- `php artisan test tests/Feature/Files/CoverPictureTest.php tests/Feature/Demo/DemoCoversTest.php` → 52 passed.
- Wider batch around files, uploads, competitions, seeders → 309 passed. Full suite: see `UI3.md`.
- Browser (Edge, signed-in student, through nginx): the posters show on Cari lomba and Detail lomba with the
  response's `sandbox` CSP; a guest gets 302.

## Assumptions / deviations from the spec
- SECURITY §3d says every download is audited and `no-store`. `files.cover` is the one answer that is neither
  (D-118, *sementara*): a poster in a list is not a download, and without a private cache a catalog of nine posters
  would write nine audit rows and fetch them again on every visit.
- Within 24 h a browser that has the picture does not ask again, also after logout or after the lomba was closed.
  `Vary: Cookie` would defeat the cache (Laravel re-encrypts the cookie on every response).
- A guest `<img>` request stores `url.intended`; the `Login` listener in `FortifyServiceProvider` drops an intended
  address under `/berkas/sampul/`, so a login after an expired session never lands on a bare picture.
- `MAX_SIDE` was first 960; 640 halves the weight and still serves a 160 px cover on a 3× phone.

## Requests for other owners
- @user: review the three points above and decide the status of D-118.
- @user: `UpdateCompetition` only repoints `cover_file_id`. The old `files` row, its bytes and its thumbnail stay
  (unchanged behaviour; no cover path calls `StoreUpload::discard()`). A validation failure inside the transaction
  also leaves the fresh upload behind. Clean-up is a separate, destructive step.
- @I: `storage/app/private/sampul/` is derived data (safe to delete, can be left out of backups).

## Follow-ups (not done)
- A `.tmp` file stays if a process dies between write and rename.
- The next unrestricted `sth:demo:refresh-files` on an existing database re-renders all 98 PDFs once
  (`DemoFileRefresher.php` is part of the fingerprint).
- Posters on public pages and for Humas (needs a guest rule in `FilePolicy`; today they show the bidang drawing).
