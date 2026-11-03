# M2-C — app shell: letters card, sidebar counts, error pages  ·  status: done

QA pass of 2026-10-05 (`hasil-uji/ringkasan-temuan.md` §1, outside the repo), findings 5, 6 and 10
(Q1-01, Q1-05, Q1-07, Q2-30, Q2-03, Q2-17). Lead: "Dari ke 12 temuan yang paling penting, lakukan sesuai saranmu".
No migration, no new package, no CSS/JS change (only classes that the built CSS already has).

## Files
- `app/Livewire/Siswa/LombaSaya/Index.php` (`detail()`, `letterRows()`), `resources/views/livewire/siswa/lomba-saya/partials/letters-card.blade.php` — Q1-01
- `app/Providers/NavigationServiceProvider.php` (new), `app/Support/Navigation.php` (`badgeKeys()`, resolver gets `$except`) — Q1-05
- `app/Providers/ErrorPageServiceProvider.php` (new), `resources/views/errors/{layout,401,403,404,405,419,429,500,503,4xx,5xx}.blade.php` (new)
- `bootstrap/providers.php` (two providers), `bootstrap/app.php` (one `$exceptions->render()` line + two imports)
- `routes/web.php` (`GET /logout`, wider `{code}` pattern), `resources/views/letters/verifikasi.blade.php` (one sentence)
- Tests: `tests/Feature/Siswa/Lomba/LettersCardTest.php`, `tests/Feature/Support/NavigationBadgesTest.php`,
  `tests/Feature/Errors/{ErrorPagesTest,ErrorPagesWithoutDatabaseTest,LogoutAndIntendedTest}.php` (new);
  `tests/Feature/Siswa/Hasil/LombaSayaTest.php` (one existing test rewritten, it failed only because of Q1-01)

## What changed and why
1. **Student's "Surat tugas" card (Q1-01).** `LetterPolicy` is unchanged (the guru letter + Lampiran carry NIP and
   rank). The card now lists only letters TU already **sent** (`sent_at`), the siswa letter's link follows
   `Gate::allows('view', $letter)`, and the guru letter is an information row: "Surat perintah tugas untuk guru
   pendamping sudah terbit" + "Untuk <honorific name>", no link, no button. A letter that is issued but not sent
   used to show a download that answered 404 too; the card now says "Tata Usaha sedang menyiapkan surat tugas."
   Guru (`guru.binaan.show`) and staff keep their downloads.
2. **Sidebar counts on every page (Q1-05).** `NavigationServiceProvider::badges(object $user, string $role, array
   $except = []): array` is the one resolver. It counts only the badge keys of the rendered menu
   (`Navigation::badgeKeys($role)`), only when the user may see the queue, with the query the screen uses:
   `tu.surat` → `LetterPolicy::viewQueue` + `Registration::awaitingLetters()`; `kepsek.persetujuan` →
   `RegistrationPolicy::viewApprovalQueue` + `awaitingPrincipal()`; `kesiswaan.usulan` → `competition.verify` +
   `Competition::pendingVerification()`; `kesiswaan.verifikasi` → `ReportPolicy::viewQueue` +
   `Report::awaitingVerification()`; `humas.publikasi` → `publication.manage` +
   `HumasQueries::visiblePublications()` with status `siap`. One `select count(*)` per key, no rows loaded,
   nothing for guests or preview arrays. Keys a page passes itself are skipped (`$except`) and still win in
   `Navigation::for()`. `data-sth-badge` and the `sth-badges` event are untouched.
3. **Error pages (Q1-07, Q2-30).** Laravel picks `errors::<status>` (then `4xx` / `5xx`) also with `APP_DEBUG=true`.
   The pages include `errors.layout`: brand, "Kode <status>", title, one sentence, "Kembali ke beranda", optional
   "Kembali"; `lang="id"`, `noindex`, CSS through `layouts.partials.head` (nonce), no inline style/JS, no query,
   no role layout, never `$exception->getMessage()`. Links come from a view composer:
   `ErrorPageServiceProvider::links(Request, int $status): array{home, back}`; `home` = `/beranda` when signed in
   (the user the request already resolved; the guard is asked only when a session was started and never for a
   5xx), else `/`; `back` = the Referer when it is another page of this app, never for 429/503.
4. **`GET /logout` (Q2-03).** Route `logout.get`: signed in → `home`, guest → `login`; it never logs out (Fortify's
   `POST /logout` + CSRF is unchanged). `errors/405.blade.php` makes every other wrong-method request small.
5. **First page after login (Q2-17).** Solved without touching `LoginResponse`: the `Login` event flashes
   `sth.after_login` (`ErrorPageServiceProvider::AFTER_LOGIN`); the renderer in `bootstrap/app.php` calls
   `ErrorPageServiceProvider::afterLogin()`, which redirects a **GET** that answers 403 or 404 on exactly that next
   request to `home`. JSON requests and later requests get the normal error.
6. **`/verifikasi-surat/{code}`** accepts any one-segment code (`[^/]{1,128}`), so a mistyped code reaches the
   controller's own page (404 status): "Tidak ditemukan · Surat dengan kode ini tidak terdaftar."

## Commands verified (Docker, in-memory SQLite)
- `php artisan test tests/Feature/Errors tests/Feature/Support/NavigationBadgesTest.php` → 77 passed (764 assertions)
- `php artisan test --filter="LettersCardTest|LombaSayaTest"` → 15 passed (90 assertions)
- `php artisan test tests/Feature/Auth tests/Feature/Security/{CspSafeTest,StudentTwoFactorTest,SecurityLogTest}.php tests/Feature/Errors/ErrorPagesTest.php` → 252 passed (2040 assertions)
- neighbours: `SuratTugasTest` + `RevokeLetterTest` → 41 passed (406); `tests/Feature/{DesignSystem,Support,Siswa/Lomba,Notifikasi}`
  + `LombaSayaTest` → 215 passed (758); `KeamananTest`, `SuratSayaTest`, `TemplateSuratTest`, `LogoutAndIntendedTest`,
  `ErrorPagesWithoutDatabaseTest` → 61 passed (340). One larger run died once with a PHP fatal "Maximum execution
  time of 120 seconds exceeded" in `config/mail.php` while other suites ran on the same machine; the same files
  passed in the three smaller runs above.
- `./vendor/bin/pint --test <my 13 PHP files>` → PASS
- Dev site (`APP_DEBUG=true`, php-fpm **not** reloaded): `/halaman-yang-tidak-ada` → styled 404, 4.9 kB, no CSP error
  in the console; `GET /user/two-factor-authentication` → 405, 4.9 kB, 272 ms (QA: 870 kB, 6–63 s)

## Assumptions / deviations
- The Admin menu has no item with a badge key (W1-D), so an Admin-only account sees no count on Kesiswaan screens,
  as before. The resolver goes by permission: a menu that shows `kesiswaan.*` gets the counts for an Admin too.
- `siswa.lomba-saya`: no screen computes a count today, so the resolver returns none.
- An unknown address has no session (no `web` middleware), so a signed-in visitor sees "Kembali ke beranda" → `/`
  there. No `Route::fallback()` on purpose: it would write a session row for every scanner request.
- "Kembali" is a plain link to the Referer (no JS: `history.back()` would need a hook in `resources/js/app.js`).
- 401 text: "Belum masuk · Masuk dulu untuk membuka halaman ini."; 405: "Tidak bisa dibuka · Alamat ini tidak bisa
  dibuka langsung."; other 4xx: "Permintaan ini tidak bisa diproses." (my wording, not in the brief).

## Not verified
- Full suite not run (brief: filtered runs only). `npm run build` not run (no new classes expected).
  Not run, only read: `AccountControlsTest`, `AccountAdminActionsTest`, `ArchiveDueStudentsTest`, `ImportStudentsTest`
  (they log in through `POST /login`; none follows a login with a GET that expects 403/404).
- Any other test in the suite that logs in and then expects a 403/404 on the very next GET would now see a
  redirect to `/beranda` (none found by grep in `tests/`).
- Error pages in a browser: only the 404 on the dev site, before a php-fpm reload (so without the composer:
  guest link). Not looked at: 403/419/500/503, the "Kembali" button, phone width, `php artisan down`.
- `GET /logout` and the after-login redirect on the dev site (need `reload.bat`); covered by tests only.
- Production image with `APP_DEBUG=false` (QA asked to repeat Q2-03 there).

## Requests / doc edits for the coordinator
- Run `reload.bat` so php-fpm sees the new providers and routes.
- Nothing needed in `LoginResponse`, `app.js` or `app.css`. If the lead prefers the explicit variant for Q2-17:
  in `LoginResponse::toResponse()` (and Fortify's `TwoFactorLoginResponse`) drop `url.intended` when the matched
  route's `role:` middleware does not include one of the user's roles; then remove `afterLogin()` + the listener.
- DECISIONS (new entries): students never get the guru letter, only the notice (Q1-01); the student card shows
  letters only after "Kirim"; a 403/404 on the first page after login goes to the beranda (Q2-17).
- CLAUDE.md §5: `GET /logout` (`logout.get`), `NavigationServiceProvider`, `ErrorPageServiceProvider`; §7: error
  pages (`errors.layout`) and "counts come from the resolver, a page may still pass its own". UI-MAP: error pages
  row. `docs/dev-notes/W1-D.md` line 38 (resolver signature) is now out of date.
- Follow-ups: optional `data-sth-back` hook for a real history back; backlog #47 (Talenta review badge) now only
  needs a key in `Navigation` + one line in `NavigationServiceProvider::count()`.
