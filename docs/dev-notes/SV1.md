# SV1: fixes for the school's server (D-170…D-172) · status: built 2026-10-30, not committed (waiting for the lead)

The lead's request of 2026-10-30, after the analysis of 2026-10-29 that compared Smaga4 with the sibling folder
`smaga_server` (the copy that runs on the school's server, see `UX3.md` §1): "benerin bug tersebut yg di server", four
points of the analysis (restart after a power cut, more php-fpm workers, the signer question, mail), and "cek keseluruhan
dengan teliti terutama smaga_server … pastikan diserver ga ada error functional". Applied to Smaga4 and `smaga_server`
alike; only `compose.server.yaml` (the worker count of the server) is `smaga_server`-only. The email sender, the `.env`
files and the data of the two folders stay different on purpose.

## 1. Every Livewire control was dead with APP_DEBUG=false (root cause)
- Symptom (lead): on `smaga_server`, Kesiswaan › Status aktivasi "Daftar siswa" could not be clicked and Import siswa ›
  "Download template Excel" did nothing. The analysis showed it was every Livewire control of every role (tabs, filters,
  searches, dialogs opened by an action, saves, downloads, uploads, the student activation, "Lupa kata sandi", the talent
  gate's tiles), also on the school's server, and it would have been the same in production.
- Cause: `AppServiceProvider::configureLivewireEndpoint()` (backlog #17, CSP build) forced
  `livewire.asset_url = EndpointResolver::scriptPath().'?build=csp'`, and `scriptPath()` defaults to `livewire.js`.
  Livewire 4.4.7 registers its script route as `livewire.js` only while `app.debug` is on and as `livewire.min.js` when it
  is off (`FrontendAssets::boot()`), and prints the configured asset URL instead of its own route. Smaga4 runs with
  `APP_DEBUG=true`, so the names matched by chance; `smaga_server` and the school's server run with `APP_DEBUG=false`
  (`.env` written 2026-10-21 23:06), so every page asked for a 404 and Livewire never started.
- Fix: `EndpointResolver::scriptPath(minified: ! config('app.debug')).'?build=csp'`. The address is baked into the php-fpm
  config cache, so a stack picks it up only after `docker/php/dev-cache.sh --force` (or a recreated `app` container; since
  SV1 a plain `dev-cache.sh` also rebuilds when a file under `app/Providers` is newer); production rebuilds its caches at
  every start (`docker/entrypoint.prod.sh`).
- Why the tests never saw it: `phpunit.xml` did not set `APP_DEBUG`, CI copies `.env.example` (`true`), and
  `CspSafeTest` fetched `scriptPath()` itself (the debug-on name) instead of the address a page prints. Now:
  `phpunit.xml` forces `APP_DEBUG=true` (a developer `.env` with `false`, as in `smaga_server`, no longer changes the
  suite), `tests/Support/WithAppDebugOff.php` boots a file's app with debug off, `tests/Support/LivewireScript.php` reads
  the printed script address, `tests/Feature/Security/LivewireScriptDebugOffTest.php` (only `livewire.min.js` is
  registered with debug off; six pages of four layouts/roles print it and it answers 200 with the CSP build), and
  `CspSafeTest` loads the printed address. Red without the fix (6 failures), green with it.

## 2. The app's dialog for a 419 (D-170)
- Livewire answered a 419 (session over, a tab left open for hours, a login or logout in another tab; with debug off also a
  stale or tampered snapshot) with the browser's English confirm "This page has expired. Would you like to refresh the
  page?". The lead chose the app's own dialog.
- `<x-sth.flash>` (rendered by every layout that loads Livewire) carries `<x-sth.modal id="sth-sesi-habis">`: "Sesi sudah
  berakhir" / "Muat ulang halaman untuk melanjutkan." / one sentence that unsaved input must be typed again and a login
  may be asked / "Tutup" + "Muat ulang". `app.js`: `watchExpiredPage()` registers a `Livewire.interceptRequest()` whose
  `onError` calls `preventDefault()` on a 419 and opens the dialog; the hook `data-sth-reload` reloads the page. After
  "Tutup", later 419s of the same page (the `wire:poll` of Import siswa every 2 s and of the activation countdown every
  5 s) only keep one error toast up instead of reopening the dialog; `livewire:navigated` resets that.
- Plain forms (Masuk, Keluar) keep the app's 419 error page. The empty-200 case of Livewire ("The server returned an empty
  response") is not handled: no action of the app returns an empty body.
- Test: `tests/Feature/Support/SessionExpiredDialogTest.php` (every layout with `@livewireScripts` renders the flash
  component; four pages of three layouts carry the dialog once; the `watchExpiredPage()` function itself intercepts the
  419, prevents Livewire's default and opens the dialog; the copy fallback of §5.10).

## 3. Restart after a power cut (lead: "tambahkan reboot di compose dev")
- `compose.yaml` (both folders): `restart: unless-stopped` also for `app`, `web`, `mysql` and `mailpit` (`queue` and
  `scheduler` had it). After a power cut, a reboot or a Docker restart the stack comes back by itself; a service stopped by
  hand (`docker compose stop`, `stop.bat`) stays stopped. Seen for real on 2026-10-30: Docker Desktop was off after the
  laptop restarted, and when it started, all four stacks (smaga4, smaga_server and the two verification copies) came back
  without a `docker compose up`, with their php-fpm config caches intact.
- The server also needs the Docker service enabled at boot (`systemctl is-enabled docker`), see the rollout steps.
- `queue` and `scheduler` may still start before MySQL and log a few connection errors until it is up (backlog #44); they
  restart until MySQL answers. The dev nginx now resolves `app` per request (`resolver 127.0.0.11`, as `prod.conf`), so
  recreating only `app` no longer leaves `web` on a stale IP answering 502 (found by the code review of SV1).

## 4. More php-fpm workers on the server (lead: "5-10 maybe")
- `docker/php/fpm.dev.conf`: `pm.max_children = ${STH_FPM_WORKERS:-3}` (php-fpm reads the default syntax itself since PHP
  8.3; checked with `php-fpm -tt`; an EMPTY value is not the default and stops php-fpm, so leave the variable unset rather
  than empty). `smaga_server/compose.server.yaml` sets `STH_FPM_WORKERS: "10"` for `app`: the server has 6 CPUs and 62 GB
  RAM (2026-10-23), and a class using the app at once no longer queues behind 3 workers.
- The laptops keep 3: every new worker starts with an empty realpath cache, which costs seconds on the Windows bind
  mount (the reason written in the file). Counted on 2026-10-30: 10 workers on the server-like stacks, 3 on both laptop
  stacks.

## 5. Browser verification and the defects it found
Method: two throwaway compose projects built from the `smaga_server` folder (`sth-verif-trial`, `sth-verif-demo`) with
their own `.env` (mounted over the folder's), storage, bootstrap cache, MySQL and Mailpit, so nothing touched the real
folders or sent real mail: the allowlist mailer with an empty list and SMTP to Mailpit. Server-like settings: APP_ENV=local,
APP_DEBUG=false, staff 2FA and `/_dev` off, real 6-digit OTP codes, 10 php-fpm workers; `trial` holds the
`DataUjiSekolahSeeder` data of the server (and, in the second run, APP_URL = the server's public address), `demo` the local
demo data. Agents drove headless Chromium with Playwright inside each stack's Docker network (so the browser reached the app
as `http://web`: plain http, no `Sec-Fetch-Site`, like the server), recorded every console error, failed request, HTTP
≥ 400 and native dialog, and every finding went to a second agent that tried to reproduce it and find the root cause. The
first run (7 finders: a two-part journey over the whole flow, accounts/admin, three role sweeps on demo data, a read-only
pass on the real `smaga_server` stack, a code review) was cut short by a laptop restart after all finders but the second
journey half had reported; its findings were then fixed by four implementers on disjoint files, each reviewed by an
independent agent, plus two follow-up implementers for the reviewers' points.

### 5.1 The CSRF token was rotated mid-page (D-171; blocker: every first login, every password change)
`Auth\Activation::verifyCode()` / `savePassword()`, `Akun\Keamanan::changePassword()` and `Auth\TwoFactorSetup::confirm()`
called `session()->regenerate(true)`, which also regenerates the CSRF token while the open page keeps the old one. Laravel
13's `PreventRequestForgery` skips the token check when the browser sends `Sec-Fetch-Site: same-origin`, which browsers do
only for https and localhost; on plain http to an IP (the school's server) the next Livewire request and the logout form
answered 419: "Sesi sudah berakhir" twice during every activation (after the code, and on "Mulai"), the typed Data diri or
new password lost, and Keluar after a password change showed the 419 page. Three agents found it independently. Now
`session()->migrate(true)`: new id, old row destroyed (D-93 kept), token unchanged (it was rotated at login by Fortify, so no
fixation). `tests/Feature/Auth/SessionRenewalCsrfTest.php` binds `PreventRequestForgery` without its unit-test exception
and replays what the browser sends (new session cookie, the page's meta token, no `Sec-Fetch-Site`).

### 5.2 Deadlocks in the rate limiter (sporadic HTTP 500)
Every limiter counts in the `database` cache store; `DatabaseStore::add()` deletes an expired row and INSERT IGNOREs, and two
requests of one user at the window rollover deadlocked (SQLSTATE 40001 / 1213, logged twice in the first run, once
organically). `App\Support\RetryingRateLimiter` (bound with `extend()` in `AppServiceProvider::register()`) repeats each
refused storage call on its own (3 tries, 5-25 ms × n), never inside an open transaction and never after a lock wait
timeout; each retry logs `cache.limiter_retried` (attempt and code only). `tests/Feature/Security/RateLimiterDeadlockTest.php`
(12 tests; it pins a hash of the Laravel methods it mirrors: after a Laravel update that changes
`Illuminate\Cache\RateLimiter`, compare and update). A real MySQL deadlock was provoked by the stress test of the second run
(§6).

### 5.3 Three phone photos were refused with 413; upload errors named internal fields
Livewire sends every photo of one pick in one request; the dev stack allowed 12 MB (nginx) / 10M-12M (PHP). Now 30 MB in
`docker/nginx/default.conf` and `docker/php/php.dev.ini` (`upload_max_filesize` 20M, `post_max_size` 30M; bind-mounted, no
image rebuild). Upload errors read "<field> gagal di-upload. Periksa ukuran file (batasnya ada di bawah kolom), lalu coba
lagi." with readable field names (`lang/id/validation.php` attributes for every upload property, guarded by
`UploadErrorMessageTest`), and a too-large pick of Foto dokumentasi says "Foto dokumentasi gagal di-upload. Pilih lebih
sedikit foto sekaligus, maks. 5 MB per foto." (`LaporHasil::_uploadErrored()` override, generic branch only). Production keeps
25 MB on all three layers, where five photos of exactly 5 MB are at the edge (follow-up below).

### 5.4 Search boxes ate the space typed before a pause (10 screens)
The `updatedQ()` / `updated()` hooks wrote the trimmed term back, and Livewire put it into the focused input: "Nadia " +
pause + "Putri" became "NadiaPutri". Fixed on Kesiswaan › Kelola lomba, Database talenta siswa, Database prestasi, Guru ›
Siswa binaan and Lomba, Admin › Arsip otomatis and Log audit, Humas › Arsip, TU › Arsip surat, Kepsek › Surat atas nama saya:
the box keeps what was typed (cut only past 100 characters), the queries, links, exports and audit filters trim. The
empty states read the trimmed term.

### 5.5 Letter QR and the "Bukan saya?" link on the request host (D-172, *sementara*)
`LetterPdf::verificationUrl()` and the signed link of `SendEmailBindingNotice` now use `App\Support\PublicAddress::root()`:
APP_URL, unless it is empty or a loopback address (localhost, 127.x, ::1, *.localhost), where the request host stays (a
laptop copy reached through the LAN or a tunnel). `tests/Feature/Security/PublicAddressLinksTest.php`.

### 5.6 "Data contoh" on the school's real data
The chip was tied to `APP_ENV=local`, which the lead's server instances keep (D-168). Now `App\Support\DemoData::present()`:
local AND a `files` row under `demo/` (the demo seed's files; `DemoData::DIRECTORY` must equal
`Database\Seeders\Support\DemoFiles::DIRECTORY`, tested).

### 5.7 Smaller fixes
- Admin › Bobot poin: Enter saved without the question (the dialog's button was the form's only submit button). The form now
  submits `askSave()` (also checks the note length and "nothing changed" before asking, so an unchanged tab answers "Tidak
  ada bobot yang berubah." without the dialog); the dialog names who else is told (Kepala Sekolah / other Admins, from the
  recipients) and its button runs `save()`. Dialog `bobot-konfirmasi` joins the D-128 list.
- TU opening an old "Surat tugas perlu dibuat" notice of a registration that left the queue (D-155) got a 404 page; now the
  queue (`tu.beranda`) with the warning toast `MSG_CANCELLED_ELSEWHERE` (unknown or invisible registrations keep their 404).
- "Batal ganti email" (Keamanan) and "Ganti email" on the activation left the code waiting (a reload came back to the code
  form, a corrected address waited 60 s). Both end the code now (`IssueOtpCode::cancel()`); the 60 s cooldown only waits
  while the newest code is still waiting, the hourly and daily caps still count every code.
- Kepsek/Admin Dashboard "Download laporan" opens Laporan for the shown year (`?tahun=` or `semua`).
- Pendaftaran lomba › "Batalkan pendaftaran" named "anggota tim" for an individu registration; the sentence now follows who
  `CancelRegistration` really tells (D-109).
- TU "Hapus dari antrean": the field hint no longer contradicts the description about who gets the reason.
- Laporan hasil: "Pilih hasil yang diraih." once; a refused "Kirim laporan" sends `sth-form-invalid`, so the first invalid
  field scrolls into view (inline card and full page).
- Pagination: `lang/id/pagination.php` holds « and », so the aria-labels read "Sebelumnya" / "Berikutnya".
- Status sistem › Aktivitas Admin terbaru also lists the activation-window, class and unfreeze events.
- Beranda "Rekomendasi untukmu": the subtitle says what the cards are (interest first, other open lomba, or "Kamu sudah
  terdaftar di semua lomba bidang minatmu (X) yang dibuka.").
- Import siswa: button, dialog, result, tiles and row chips agree (a phone or archive-date correction is "Data diperbarui",
  blue; `StudentImportReport::kindOf()`); a queued import reaches "Selesai" at step 3.
- Admin top-bar search "Cari nama atau NISN": a student's name opens the student in Bantuan akun siswa; a staff name leaves
  that box untouched (no red "Siswa tidak ditemukan.").
- Verifikasi hasil: the result banner names the report it belongs to (lomba + ketua), also for Humas.
- "Salin data" copies on plain http (the old copy command after the missing Clipboard API); Humas › Publikasi on a phone
  scrolls the panel into view after a card tap (`sth-scroll-to`).

## 6. Second and third verification (after the fixes)
Same throwaway copies as §5 (trial: APP_URL = the server's public address; demo: a loopback APP_URL with the demo seed), same
Playwright in Docker, eight agents: two journeys over every role, a retest of the account fixes and of the UI fixes, a
regression crawl, the laptop stack read-only, and three gap runs the first critic had asked for (a proposal rejected or
published late while its submitter's registration waits, D-148, with expiring invitations; Admin saves that change data for
every role; the import at school size with the yearly class move, D-81). A separate verifier reproduced every finding.
- The first fixes held: of 150 checks, 112 worked as intended, 10 partly (the path was not reachable in that run, not a
  failure) and 28 were left to another agent; none of the first fixes regressed. Every page loaded `livewire.min.js` and about
  300 Livewire requests answered 200; activations and Keamanan akun never answered 419; 64 simultaneous filter clicks on one
  throttle key all answered 200 (no deadlock 500); a 25 MB request reaches PHP and a 35 MB one gets nginx's 413; mail and QR
  links follow APP_URL on trial and the request host on demo.
- It found 39 more defects (several by two or three agents), 36 confirmed, 3 rejected (a Livewire temporary-upload refusal
  that names no field: by design; the TU top-bar search on Persetujuan lomba: D-90; "Tidak menjawab" after an expiry: shown).
  None was caused by the first fixes, but the lead wants no functional errors on the server, so a third round fixed them
  (§6.1). Not fixed: §8.

### 6.1 Third round (R3), 2026-10-30/11-01
Five implementers on separate files, each change reviewed by an independent reviewer; two short rounds then settled the
reviewers' minor findings (also reviewed). The fixes:
- **Laporan hasil** (`Siswa\LombaSaya\LaporHasil`): after one refused photo "Tambah foto" refused every later photo (Livewire
  appends each pick to `fotoBaru`, and the refused file stayed in it). `updatedFotoBaru()` now empties `fotoBaru` first, checks
  every photo on its own and adds the good ones up to `SubmitReport::MAX_PHOTOS`; refused photos and photos past the fifth are
  named ("Ukuran foto maksimal 5 MB. Tidak ditambahkan: …", "Maksimal 5 foto. Tidak ditambahkan: …") in a live region under the
  photo list (`{form}-foto-pesan`, `aria-live="polite"`) that the picker points at; a pick Livewire refused as a whole adds
  "Tidak ada foto dari pilihan ini yang ditambahkan.". The messages of hasil, the tick (now `wire:model.live`), judul and
  abstrak go once the field is right (a refusal of `SubmitReport` under hasil stays, `$hasilRefusedBySend`). A refused single
  file (sertifikat, pengumuman, laporan, artikel, poster), at the pick or by the Action's content check, is dropped, so its drop
  zone shows the reason instead of "Siap dikirim".
- **Talenta saya** (`Siswa\ProfilTalenta`): the same for bukti and Sertifikat pendukung; the messages of an earlier try go with
  the next try or a good save.
- **Cari lomba** (`Siswa\Lomba\Katalog`): the in-page search box below lg ate the space before a pause (the eleventh screen of
  §5.4); `q` keeps what was typed, `term()` trims where the query is built.
- **Peringkat**: the footnote no longer claims a consent rule (D-162). **Beranda**: "Belum ada lomba berjalan" (with "Lihat
  semua" on the Selesai tab) for a student whose lomba are all over.
- **Lomba saya**: "Batalkan pendaftaran?" names who is told, from one rule (`CancelRegistration::audience()` / `noticeLine()`,
  used by the Action, by Detail lomba and by Lomba saya); a team short of members after an expiry or a decline (D-36) says so
  ("Tim kurang anggota", one rule in `ConfirmMembership`) instead of "Semua anggota sudah konfirmasi."; a team member sees a
  neutral chip and no "perlu tindakan" while only the ketua can report (tracker `state` override; also on the Beranda and for
  the guru on Siswa binaan); the unanswered or never-invited anggota of an ended registration no longer say "Menunggu
  konfirmasi".
- **Import siswa**: after a queued import the next file shows its import button again; a file read while an import ran is
  read again before the dialog; the Done banner of a queued import names only what the job wrote; the step-3 tiles take their
  columns from a container query and the row status stands under the name; an empty file says "File ini tidak berisi data
  siswa. …"; a valid row with cleaned cells carries the note "Sel dibersihkan: …" (so it is under "Ada catatan" and in the
  download); progress texts; the password button and hint stay while a file waits; an import started in another tab is taken
  over with its banner.
- **Admin**: no duplicate field id in the always-rendered dialogs of Pengguna & peran and Arsip otomatis (`PageWiringTest` now
  fails on any duplicate id of a crawled page); "Ganti Kepala Sekolah" no longer says the Kepala Sekolah can cancel; Log audit
  prints reason labels instead of codes (`competition_rejected`, `letter_queue` + the note, `not_me`, `failed_attempts`).
- **Status aktivasi**: "Tampilkan lebih banyak" stops at the 1.000-row cap with a note. **Database talenta siswa**: the panel
  follows the filtered list (first shown row, or closed), and a "Simpan" that names a row the panel no longer shows saves
  nothing and warns (`savePanel($shownId)`).
- **File sizes**: one rule, `App\Support\FileSizeLabel` (one decimal, decimal comma: "1,6 MB", "1.023 KB"), on Arsip foto, TU
  surat tugas, Admin › Surat, the upload rows and the two older helpers; `FileSizeTextTest` forbids `Number::fileSize` and
  hand-made `/ 1024` size text.
- **Coordinator**: the pagination `<nav>` says "Navigasi halaman" (the Livewire views are published to
  `resources/views/vendor/livewire/`, `PaginationNavLabelTest`); a confirm dialog without body text has no empty band; Humas ›
  Publikasi also scrolls when only a sliver of the panel shows; every HTML page for a signed-in user is `no-store`
  (`PreventCredentialPageCaching::signedInPage()`), so Back after "Keluar" no longer shows Siswa › Bantuan with the student's
  name.

### 6.2 Third verification (after R3), 2026-11-01/02
Five agents on the same copies (Laporan hasil + Talenta saya, the student and guru screens, Import siswa, the staff screens,
a crawl of every role at 1366 and 390 px plus the laptop stack read-only), each finding reproduced by a verifier. Of the R3
fixes 53 worked, 2 partly (the riset title, the ended team outside Lomba saya: both fixed below) and 2 not: the pagination label had never reached `smaga_server` (the sync compared the folders
with `diff -x vendor`, which also skipped `resources/views/vendor/`; the two Livewire views are copied now, and the sync is a
script that skips only the top-level `vendor`, `node_modules`, `storage`, `.git` and `bootstrap/cache`). 13 more findings,
all confirmed, 2 of them caused by R3 (a photo message after "Hapus foto", the banner of a finished queued import over
another file); fixed by three implementers + reviewers and the coordinator:
- Laporan hasil: the riset title is `wire:model.live.blur` (in Livewire 4 a bare `.blur` sends nothing, so "Isi judul
  penelitian." stayed; `WireModelSendsTest` refuses a bare `.blur` / `.change` / `.enter` model in every view); "Kirim laporan"
  in a second tab after the report was sent in another one answers "Laporan hasil sudah dikirim. Halaman sudah diperbarui."
  and opens Lomba saya (no error toast, no old form); "Hapus foto" clears the photo messages; the ketua's Beranda action asks
  for the revision while the report is perlu_revisi, and a member's report card is titled "Laporan hasil".
- One member-state rule for an ended team (`LombaSaya\Index::memberState()`): "Tidak ikut" / "Tidak diundang" also on Undangan
  tim and Guru › detail binaan; "Tim kurang anggota" also on Detail lomba › Pendaftaran lomba and in the guru's chip; the
  student's "Pendaftaran dibatalkan" notice opens that registration.
- Import siswa: two imports of the same new students at once no longer answer 500 (each new row is checked again inside the
  transaction with a savepoint, a row the other import registered is left out and named, and a unique-index refusal still
  reads the file again); the banner of a finished queued import over another file names its own file and says the file on
  screen is not imported yet; a queued result makes no "Semua baris sudah sesuai dengan file." claim (the job does not count
  rows another import took); "Download baris yang perlu dicek" wraps inside its card at 1024 px.
- A name that ends with a degree gets one full stop in "Ubah peran" and on Surat & tanda tangan (`MentorChoice::closing()`).

A browser re-check of these fixes (two agents, a verifier for their findings) confirmed 12 of 13 in the browser, among them
the import race on MySQL (two staff importing the same new students at once: no 500, every student created once) and the
pagination label. It found three older defects, fixed by the coordinator:
- Undangan tim kept "Menunggu konfirmasi anggota" in the header of a short team: it now takes the chip of Lomba saya
  (`teamChip`: "Tim kurang anggota", orange for the ketua).
- After "Batalkan pendaftaran" on Lomba saya (desktop) the list stayed on an empty Berjalan tab beside the cancelled card:
  `cancel()` moves the list to Selesai.
- After losing the import race the rows the other import created still read "Baru" under the result: `ImportStudents`
  reports them as skipped with `NOTE_TAKEN` ("Sudah didaftarkan oleh impor lain, jadi tidak dibuat dari file ini."),
  `StudentImportReport::$taken` counts them, and the rows under a synchronous result are the rows the commit checked.

A last browser check confirmed the three (Undangan tim at both widths, the cancel on Lomba saya, four import races: no 500,
no duplicate student, "Dilewati" with the note on the losing screen). It found one regression of that last fix: the line
"N baris adalah akun alumni (sudah diarsipkan) …" counted the taken rows as alumni; it now counts only skipped rows without
`NOTE_TAKEN` (`alumniCount`).

## 7. Questions of the lead answered without code
- Signer of the letters: the fields (Nama, Pangkat/Golongan, NIP) and the signature + stamp upload already exist in Admin ›
  Pengaturan › Surat & tanda tangan; they were only dead because of §1. The lead: leave it ("biarin aja"). The letter takes
  the values of that tab at "Buat PDF"; on `smaga_server` they are still the seeded placeholders and the sample image, so
  the Admin types the real data and uploads the real image before TU issues real letters.
- QR codes and links: with the school's domain in APP_URL and everyone using it there is no problem; since D-172 the QR
  follows APP_URL even when TU uses another address. Letters made before the domain keep the IP address in their QR, so real
  letters should wait for the domain.
- Mail: the server sends through the new Brevo account (the school's own Gmail sender, set up 2026-10-27); a test mail
  (`sth:mail-test`) already arrived. The lead tests the OTP and notice mails on the server after this fix.
- Not done (the lead: later): https and the domain (wait for the school), and the trial passwords of the staff accounts
  (change them in Keamanan akun once Livewire works on the server).

## 8. Left open (not fixed on purpose, or for later)
- Plain http on the server: Chrome logs the COOP warning on every page, the Clipboard API is missing (the fallback copies),
  and per-address limits (login 60/min, guest Livewire 120/min) are shared by everyone behind the school's NAT. With the
  domain and Apache in front: pin the compose network and set `STH_TRUSTED_PROXY` to its gateway (the default 172.30.10.1 is
  the production network), else every visitor has one IP.
- Production upload limit 25 MB on PHP, nginx and Apache: five photos of exactly 5 MB in one pick are at the edge (owner I,
  with SECURITY §3d).
- Cosmetic, known: CSP console errors after browser Back/Forward following `wire:navigate` (Livewire re-inserts its progress-
  bar style); the Kelola lomba drawer names errors in two rounds when "Lebih dari 1 hari" lacks "Tanggal mulai".
- `wire:poll` keeps polling after a 419 (each answer only keeps the toast up); a reload stops it.
- Found by the second verification, left for the lead: after the 15-minute password confirmation of a sensitive Admin save
  (Bobot poin and the others) the typed changes are gone and must be typed again; an account that is Kepala Sekolah and guru
  at once has no menu path between the two areas (it types the address).
- R3 leftovers: text typed in the Database talenta siswa panel within the 400 ms search debounce before "Simpan" is not saved
  (the warning toast says so); "Simpan" on Riwayat talenta and "Kirim laporan" name no file when the Action refuses one of
  several files for its content; refused temporary uploads stay in Livewire's tmp folder until its own 24 h cleanup; one render
  of Status aktivasi › Daftar siswa at the 1.000-row cap takes several seconds (Blade components per row); a queued import
  whose new rows another import registered first counts only what it created and cannot name the taken rows (a column on
  `student_imports` would need a migration); Lomba saya's team card still prints its member labels itself (equal to
  `LombaSaya\Index::MEMBER_STATE_CHIPS`).
- For the owner of the Actions (user): `PublishWeightVersion` also tells the acting Admin about the own save (the dialog
  names only the others), and `Bobot` mirrors its recipient rule; `ResumeWaitingRegistrations` writes the reason
  `competition_rejected` as a literal that `AuditLog` and `Registration::cancelledWithLomba()` repeat (a constant would be the
  one source); `ImportStudentsJob` does not count corrections (phone, archive date), so the Done banner of a queued import
  names only new and moved students.
- Not checked: a real school printer, Gmail on a phone, the school's own network (NAT hairpin to the public IP).

## TEST-RESULTS
- First wave, focused: `CspSafeTest` + `LivewireScriptDebugOffTest` 35 passed; the debug-off test fails without the fix (6
  failures) and passes with it. `SessionExpiredDialogTest` + `ViewWiringTest` 11 passed. Full suite (`--parallel
  --processes=6`): **3929 passed, 1 skipped (23307 assertions)**, exit 0, 1058 s. `pint --test` clean (854 files).
- Fix wave and follow-ups: focused runs per implementer all green (core 222 + 577 neighbours, search 492, ui-1 109 + guards,
  ui-2 359, follow-ups 16 + 13 + 93 + 2 and 5 + 6 + 61 + 135 + 184 + 100); the two red guards of the wave (`ViewWiringTest`
  floor, `AdminAccessTest` labels) settled. `pint --test` clean (875 files). `npm run build` OK.
- R3 (third round and its two review rounds): focused runs per implementer all green (Laporan hasil + Talenta saya 22 + 83 +
  65, then 31 + 126, then 175; Cari lomba / Peringkat / Beranda 151 + 59 guards incl. `PageWiringTest`; Import siswa 220 + 109
  guards + `PageWiringTest`, then 25 + 111, then 97 twice; Lomba saya 26 + 362, then 30 + 438 (`tests/Feature/Siswa`); Admin
  50 + 234 + `PageWiringTest` with the duplicate-id check; Talenta 143 + 79 + 4, then 58). Two Laporan hasil tests failed once
  while another agent's test container ran at the same moment (their temporary uploads share a folder) and passed alone.
- Full suite after R3 (`--parallel --processes=6`): **4239 passed, 1 skipped, 1 failed (25834 assertions)**, 931 s. The
  failure was a real regression of the coordinator's `no-store` change: `signedInPage()` called `$request->user()`, which
  loads the user, also for a 500 page (`ErrorPagesTest`: a 5xx page must not open the database). It now reads only a user the
  guard already resolved or the login key of the session (new test in `CredentialPageCachingTest`); `ErrorPagesTest` +
  `tests/Feature/Auth` 285 passed. `pint --test` clean (894 files). `npm run build` OK (the container-query classes of the
  import tiles are in the CSS).
- Full suite again after that fix (`--parallel --processes=6`): **4241 passed, 1 skipped (25840 assertions)**, exit 0,
  1113 s (it ran next to the third browser verification).
- Fixes of the third verification: focused runs per implementer green (Laporan hasil 39 + 135 + `PageWiringTest`, team states
  incl. `tests/Feature/Siswa/Lomba`, `Guru`, `Registrations`, import 8 files + the coordinator's change 142 in
  `tests/Feature/Accounts` + `ImportQueueTest`; names 10). The import race is tested on SQLite only (no row or gap locks
  there); the browser re-check repeats it on MySQL. Full suite (`--parallel --processes=6`): **4283 passed, 1 skipped (26143
  assertions)**, exit 0, 1036 s. `pint --test` clean (900 files). `npm run build`: same assets.
- After the re-check fixes: the affected folders 516 passed; full suite **4286 passed, 1 skipped (26160 assertions)**, exit 0,
  1056 s, `pint --test` clean; after the alumni-line fix `tests/Feature/Accounts` 133 passed and the view guards + import
  screens 75 passed. `smaga_server` matches Smaga4 apart from the intended differences (compose files, `start.bat`, the
  server seeders, `AKUN-UJI.md`, the demo scripts).
