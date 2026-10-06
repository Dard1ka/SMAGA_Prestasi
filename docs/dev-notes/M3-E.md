# M3-E — Humas: no flyer generator, "Data untuk flyer" instead  ·  status: done (no browser run, no migration)

Lead, 2026-10-06: "Untuk bagian ini (Humas kemudian membuat e-flyer, caption, berita website sekolah, dan menentukan
jadwal publikasi media sosial) Itu mereka membuat flyer manual bukan di generate dari app ini, jdi web ini hanya
menyediakan data data yang bisa digunakan oleh humas untuk membuat flyer nya".

## What changed and why
- **STH draws no flyer any more.** Humas designs its flyers by hand in its own tools, so the mock-up, the template
  choice and "Download PNG" are gone, with the code behind them.
- **New block "Data untuk flyer"** at the top of the panel "Siapkan publikasi" (Humas › Antrean publikasi, also for a
  published card): one fact per line, only what is known: Nama, Kelas (a team: "Anggota tim:" then one
  "Nama (Kelas)" per line, ketua first, then by name), Capaian, Lomba, Cabang (`registrations.branch`), Tingkat,
  Penyelenggara, Bidang, Tanggal lomba ("5–7 November 2026"), Guru pembimbing (with title), Judul penelitian.
  No points, no NIS/NISN. Button **"Salin data"** = `<x-sth.button data-sth-copy="#data-flyer">` (M3-C's hook, which is
  already in `resources/js/app.js`); the copied element `<dl id="data-flyer">` holds facts only (each row a block, the
  "Label: isi" pair inline, so `innerText` gives "Capaian: Juara II" per line).
- **Consent** (D-16, D-71 unchanged): the panel still opens only for a student with publish consent (404 otherwise).
  `FlyerData` asks no viewer: a student without publish consent is never named in it. A team member without publish
  consent is left out of the list and only counted in a note ("1 anggota tim tidak mengizinkan namanya
  dipublikasikan, jadi tidak ditampilkan di sini."). Photo notes, outside the copied block: one student →
  "Siswa ini tidak mengizinkan fotonya dipakai."; a team → "Tidak mengizinkan fotonya dipakai: <nama>, <nama>.".
  The app offers no photo.
- **Kept exactly:** caption, kanal, tanggal/jam, "Jadwalkan", "Tandai dipublikasikan", the automatic status change, the
  Apresiasi part, the card chips "Izin foto ada" / "Tanpa foto". The reminder `PublicationDue` now says "Upload
  publikasinya ke <kanal> sekarang. Caption dan data untuk flyer ada di halaman publikasi." (title and link unchanged).
- **Humas › Arsip:** the Flyer column and link are gone (closes the "empty Flyer column" half of QA Q2-19, backlog #65).
  Columns: Lomba, Siswa (+ kelas, chip), Capaian, Kanal, Terbit, and a last cell **"Lihat"** → `humas.beranda?pilih=<id>`
  (the panel with caption and data); a row with withdrawn consent shows "–" (its panel answers 404).
- **No destructive change, no migration:** `publications.flyer_file_id`, `FilePurpose::Flyer`, `FilePolicy` (untouched)
  and the audit label `publication.flyer_generated` in `Admin\AuditLog` stay. Nothing writes the column or the event now.

## Removed
`App\Actions\Publications\GenerateFlyer`, `FlyerRenderer` (templates `biru/emas/putih`, `DEFAULT_TEMPLATE`, palettes) ·
`PublicationPolicy::generateFlyer` · `Publikasi::downloadFlyer()`, `$template`, `PREVIEW`, view data `templateOptions`,
`panel.flyer`, `panel.canFlyer`, `panel.photoConsent` · `AchievementFacts::{initials,slug,classLine,levelLine,mentorLine}`
(only the generator and the mock-up used them) · Arsip `flyerUrl` + eager load `flyerFile` · texts "Template e-flyer",
"Download PNG", "Tanpa izin foto, flyer otomatis memakai inisial.", "Upload caption dan flyer" · `flyer_file_id` out of
`Publication::$fillable` and of `PublicationFactory` · `tests/Feature/Humas/GenerateFlyerTest.php`.

## Files (owned by me)
- new `app/Actions/Publications/FlyerData.php` — `FlyerData::of(Achievement): self`, `rows` (`label`, `values`, `list`), `notes`, `RELATIONS`
- `app/Actions/Publications/AchievementFacts.php` — new `anonymous()` (the masked copy `visibleTo()` already built), dead helpers removed
- `app/Livewire/Humas/{Publikasi,Arsip}.php`, `resources/views/livewire/humas/{publikasi,arsip}.blade.php`, `partials/publication-panel.blade.php`
- `app/Policies/PublicationPolicy.php`, `app/Notifications/Publications/PublicationDue.php`, `app/Models/Publication.php`, `database/factories/PublicationFactory.php`
- comments only: `app/Actions/Publications/AutoPublishPublication.php`, `app/Enums/FilePurpose.php`, `app/Domain/Files/UploadLimits.php`
- tests: new `tests/Feature/Humas/FlyerDataTest.php`; changed `PublikasiScreenTest`, `ConsentWithdrawnTest`, `AutoPublishTest`, `HumasPoliciesTest`,
  `HumasFixtures` (new `teammate()`, `legacyFlyer()` = a flyer file as STH made it before, for the FilePolicy tests)
- not touched on purpose: `app/Policies/FilePolicy.php`, `app/Actions/Students/UpdateConsent.php` (its comment is still right), `Admin\AuditLog`.
- No new Tailwind class: every class of the new markup is already in the built CSS (checked against `public/build/assets/app-*.css`).

## Commands verified
- `docker compose run --rm --no-deps app php artisan test tests/Feature/Humas tests/Feature/Security/CspSafeTest.php tests/Feature/Performance/ListLimitsTest.php tests/Feature/Support/NavigationBadgesTest.php` → **256 passed (1289 assertions)**, exit 0, 449 s
  (Humas folder + `CspSafeTest` alone: 239 passed; `FlyerDataTest` 15, `PublikasiScreenTest` 24)
- `docker compose run --rm --no-deps app ./vendor/bin/pint <touched files>` → PASS, 37 files, nothing to fix
- Role × route matrix: no route or route name changed (`downloadFlyer` was a Livewire method), so not re-run.
- Not verified: no browser run (layout of the block, "Salin data" in a real browser, phone width); the copy needs a
  rebuilt bundle (`npm run build`, not run by me); full suite not run; `tests/E2E/support/email-scenario.php` not run (it
  checks the subject "Waktunya upload publikasi", which did not change).

## Sementara (for the lead)
- **(a) No upload for the finished flyer.** He did not ask for one, so STH keeps no copy of what Humas posts. Adding it =
  a file field in the panel through `StoreUpload` (JPG/PNG, re-encoded, purpose `flyer`) + an Action that sets
  `publications.flyer_file_id` (audited, replaces the old file) + the link back in the panel and Arsip; column and
  `FilePolicy` rule already exist, so no migration. About half a day with tests.
- **(b) Flyer files generated before today are kept** (private disk, `files` row, `publications.flyer_file_id`) and no
  screen links to them. `FilePolicy` still serves them at `/berkas/{file}` to Humas while consent stands and to Admin
  also after a withdrawal (D-75), for whoever has the address. Deleting them would be a separate, destructive step.
- **(c) My additions, drop them if unwanted:** "Lihat" on Arsip (one cell); the row "Cabang"; the team photo note.
- **(d) Team rules I had to choose:** a member without publish consent is not listed (the public pages show such a
  member as "Siswa kelas XI"); nothing is said about a team photo that would show that member. A team win is still one
  card per member (one `publications` row each), so Humas marks each of them published.
- **(e) "Tanggal hasil" is not shown:** STH stores no announcement date, only `starts_on` / `final_on` of the lomba.
- The photo sentence is not part of the copied text (it is not flyer text). Say so if it should be copied too.

## Requests for other owners
- @coordinator: run `docker compose run --rm --no-deps app composer dump-autoload` after integrating. The optimized
  classmap in `vendor/composer/` still lists the two deleted classes (harmless unless something asks for them by name;
  CI builds a fresh one). I did not run it while other agents were testing.
- @coordinator / S: `resources/fonts/{DejaVuSans.ttf,DejaVuSans-Bold.ttf,LICENSE-DejaVu.txt}` (1.4 MB) were only read by
  `FlyerRenderer`; the PDFs use dompdf's own DejaVu. Not my folder: delete them. @I (optional): GD's `--with-freetype` in
  both Dockerfiles was only needed to draw flyer text.
- @coordinator (decision log): a new D-entry "STH tidak membuat e-flyer; Humas membuat sendiri, STH memberi datanya",
  which narrows D-47 and the flyer parts of D-71 / D-75 to the files made before 2026-10-06.
- Backlog: #65 (Q2-19) done for Arsip; #67 "flyer PNG 3.3 s" no longer applies; #39 now only concerns the old files.

## Docs and mockups that still describe the generator (for the docs pass)
- `CLAUDE.md`: 24 (role table "e-flyer"), 423 and 426 (D-71 / D-75 summaries), 538 ("Admin flyer access"), 585 (#39), 611 (#65 Q2-19), 613 (#67 "flyer PNG 3.3 s")
- `docs/UI-MAP.md`: 123 (`GenerateFlyer` (Intervention Image; flyers stay on the private disk, D-47)), 165 ("no flyer link (Admin keeps name and link)")
- `docs/ARCHITECTURE.md`: 10 (`intervention/image` "(EXIF strip, flyer)"), 60 (`flyer_file_id`, "the flyer stays private"), 122 ("no flyer")
- `docs/PANDUAN-PENGGUNA.md`: 286 ("buat e-flyer → Download PNG"), 288, 308, 349–350 ("Admin masih bisa men-download-nya di Humas › Arsip": Admin cannot open that screen, and the link is gone)
- `docs/SECURITY.md`: 311, 391 (public disk for published e-flyers), 573, 1165 · `docs/TEAM.md`: 12, 45, 107 · `dokumentasi.md`: 135
- `docs/DECISIONS.md`: 58, 82, 86, 342–344, 494–496, 517–518 (history; a new entry, not an edit)
- `docs/design/mockup-v2/Humas-Publikasi.dc.html`: 269 (mock-up), 298–299 (template select), 307 ("flyer otomatis memakai inisial")
- `resources/fonts/LICENSE-DejaVu.txt` (names `GenerateFlyer`) · older notes `docs/dev-notes/W2-C.md` 8, 13, 40–43, 51–52, 64, `B3.md` 43, `W2-A1.md` 31, `Q1.md` 58, 70 (history)
- outside the repo: `hasil-uji/qa-2-peran-lain.md` Q2-19, `hasil-uji/cek-backlog-dan-panduan.md` 108, 152, 182, 418; `panduan-uji-manual-sth.html` (not opened)

## For the user guide (Humas)
Buka **Antrean publikasi**, lalu klik kartu prestasi. Di panel kanan ada **Data untuk flyer**: nama dan kelas siswa (untuk
tim: semua anggota), capaian, lomba, tingkat, penyelenggara, bidang, tanggal lomba, guru pembimbing, dan judul
penelitian bila ada. Klik **Salin data**, lalu tempel di aplikasi desain yang biasa dipakai Humas. Aplikasi ini tidak
membuat flyer dan tidak menyediakan foto siswa. Bila tertulis seorang siswa tidak mengizinkan fotonya dipakai, jangan
pakai fotonya; anggota tim yang tidak mengizinkan namanya dipublikasikan tidak ditampilkan. Caption, kanal, jadwal, dan
**Tandai dipublikasikan** tetap seperti biasa. Di **Arsip**, klik **Lihat** untuk membuka lagi caption dan data publikasi lama.
