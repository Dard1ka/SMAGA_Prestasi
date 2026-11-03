# M3-D — realistic sample documents for the local demo data  ·  status: done

Lead: "… untuk sertifikat olimpiade, laporan penelitian, piagam, sertifikat juara, sertifikat krenova, dan yang lainnya
berikan contoh yang real yang bisa seperti dipakai di lomba real jangan gada isinya …". Branch `feat/ui-uji-manual-m3`,
nothing committed. Every demo file was the same 1 kB placeholder; now each one is a document of its kind.

## Document kinds and which demo file uses which
Worked out from what the `files` row is attached to (`DemoDocumentResolver`), the same way for a fresh seed and for
the refresh command. Counts = fresh seed (98 files; the lead's database has 97, see "Refresh command").

| kind (template in `database/seeders/demo/`) | demo files | look |
|---|---|---|
| Sertifikat, landscape A4 (`sertifikat`) | 60: report evidence of non-sports lomba (53), talent evidence (7) | six themes, picked from the lomba: `emas` = black and gold corners, medal in the centre (the lead's picture; yayasan/swasta: LPB, NSIF, OSEBI) · `nasional` = navy double frame, ribbons, shield (government + sains: OSN, olimpiade sains) · `kampus` = patterned band on the left (PTN/PTS/organisasi profesi) · `internasional` = English text, blue waves (level internasional: AYRC, WSEEC) · `festival` = diamond pattern strips (seni budaya) · `inovasi` = green honeycomb (riset by a government body: KRENOVA, OPSI, FIKSI) |
| Piagam penghargaan, portrait A4 (`piagam`) | 19: reports of bidang olahraga and files named `piagam-…` (18), Fajar's talent evidence (1) | formal frame, table Nama / Asal sekolah / Cabang, banner with the result; red (Puspresnas), green (pemda: Popda), navy (federasi) |
| Laporan penelitian, portrait, 15 pages (`laporan-penelitian`) | 1: new file `laporan-penelitian-bioplastik-dari-limbah-kulit-pisang.pdf`, kind `laporan_riset`, on Keisha's LKTI report (the only demo report with a `research_title`) | cover, lembar pengesahan, abstrak + kata kunci, kata pengantar, daftar isi with page numbers, BAB I–V (three tables), daftar pustaka; margins 4-3-3-3, Times 12, roman / arabic page numbers |
| Juknis, portrait, 3 pages (`juknis`) | 8: the seven catalog juknis + the proposal `juknis-robotik-jateng.pdf` | latar belakang, tujuan, ketentuan peserta, jadwal (from the lomba's own dates; a proposal without dates: from the day it was proposed), mekanisme, kriteria/penentuan pemenang, penghargaan, tata tertib, narahubung (`panitia@example.test`, `https://example.test/…`, "0800-000-0000 (nomor contoh)"), signature; wording per kind of lomba (olimpiade, riset, robotik, seni, film, debat, olahraga, bela diri) |
| Brosur / Poster, one page (`brosur`) | 1 + 1: `brosur-ispo-2027.pdf`, `poster-film-pendek.pdf` (by file name) | light page with info boxes / dark page with large title |
| Surat tugas siswa, Surat perintah tugas guru (`surat-siswa`, `surat-guru`) | 4 + 4 demo letters | the school's **real** templates (`resources/pdf/surat-*.blade.php`, unchanged, wrapped with `@extends` + `@parent`), `LetterContent::build()` from the letter's own columns as the TU preview does, the letter's number and verification code in the QR |
| Dokumen umum (`umum`) | 0 today | fallback for a letter that cannot be built and for report file kinds without a sample (foto, pengumuman, poster) |

Not resolvable → a realistic document of the purpose from the file name, never an error (certificate for the fallback
student, juknis from the name); the summary says why. Two junior-high certificates used as talent evidence have fixed
facts (`DemoDocumentResolver::HINTS`). A certificate a student uses twice (report + talent evidence) is one document.

## The limits and how each is enforced
- **Only fictional people.** A name is printed only if `DemoPeople::isFictional()` knows it (`DemoSeeder::STUDENTS` /
  `STAFF`, now public). Otherwise: fallback student of the spec, or no letter at all. The Kepala Sekolah on the lembar
  pengesahan is the constant `DemoPeople::PRINCIPAL`; on a letter the signer of the settings is replaced by it when it is
  not that name. Test "never prints a name from outside the demo set".
- **Signers** of certificates: 14 + 6 invented names, roles only "Ketua Panitia", "Ketua Dewan Juri", "Ketua Panitia
  Pelaksana", "Koordinator Pertandingan" (never an office of a real body; test). **No real signature, stamp or logo:**
  everything is drawn in `DemoArt` (shapes only); the pen strokes are doodles from a hash; the round stamp reads CONTOH;
  every certificate number contains `/CONTOH/`. The two emblems of `resources/pdf/assets` appear only on the school's own
  letters, with the repo's sample signature image ("CONTOH TTD + STEMPEL"), never the one an Admin uploaded.
- **Footer on every page**: `DemoPdfRenderer::FOOTER`. Tests count it per page for every kind and for the letters.
- **Nothing copied**: layouts, wording and drawings are mine. No web search or fetch was done; the academia.edu link was
  not opened. The research text and its numbers are invented and say so ("data contoh"); the five references are invented
  on purpose (publishers and journals carry the word "Contoh"), with a note above them.
- **Local only**: `DemoSeeder` (as before), `DemoFiles::realistic()`, `DemoFileRefresher` and the command refuse unless
  `APP_ENV=local`; `DemoPdfRenderer::render()` additionally refuses in production. Templates live in
  `database/seeders/demo` (not `resources/pdf/demo`: my renderer does not need them there, and so they are in no view
  path of the app). Tests for each guard.
- **Upload scanner**: every sample goes through `StoreUpload::store()` (UploadRules + `PdfScanner`) in the test; all 98
  files of a full seed and the 97 of the dev database were scanned too (accepted).

## Cost
Measured in a Pest test on SQLite (`STH_DEMO_FULL=1`, container `docker compose run`), not with `migrate:fresh` on MySQL:
`DatabaseSeeder` with placeholders **2.7–4.2 s** → plus **30.2–34.9 s** to render 98 files (0.3 s per certificate, 0.2 s
piagam/juknis, 0.8 s the report, 0.4–1 s a letter). Demo folder 74 kB → 2.1 MB (certificate 7–12 kB, report 25 kB, a letter
155 kB like every real letter: emblems + signature). Core fonts only, pictures are SVG. The render sandbox and font cache
are in the system temp folder: on the Windows bind mount a certificate took 0.8 s. Identical documents render once.
**Tests keep the placeholder**: `DemoFiles::realistic()` is false in the test process, so the three tests that seed the
demo data cost what they did (demo test of `SeedersTest`: 3.8–4.3 s). My two test files: about 60 s of worker time.

## Refresh command (run on the dev database on 2026-10-06)
`docker compose exec -T app php artisan sth:demo:refresh-files` (`--force` render all again · `--berkas="piagam-*"` only
such names · `-v` one line per file · `--lengkapi` see Requests). Rewrites, at the **same path**, only `files` rows with
`disk=local`, path `demo/<name>.pdf`, MIME PDF; updates `size`, `sha256`. Output of the last full run:
```
Berkas demo di folder demo/: 97
  dibuat ulang · Brosur lomba: 1
  dibuat ulang · Juknis lomba: 8
  dibuat ulang · Piagam penghargaan: 19
  dibuat ulang · Poster lomba: 1
  dibuat ulang · Sertifikat: 60
  dibuat ulang · Surat perintah tugas guru: 4
  dibuat ulang · Surat tugas siswa: 4
  ukuran seluruh berkas: 2085 kB
Selesai dalam 33.7 detik.
```
No "dilewati" and no "catatan" line (nothing skipped, nothing built from a file name). Run again: `sudah terbaru (tidak
diubah): 97`, 4.9 s. It ran three times in all (twice while the templates still changed: 35.4 s, then 33.7 s). Each PDF carries the identifier of the document it was rendered from
(`/ID` in the trailer = hash of data + templates + drawing code), so an up-to-date file is neither rendered nor written;
editing a template makes the files out of date. Bytes are deterministic (fixed dates).
Checked before/after with read-only queries: row count and newest `updated_at` of 13 other tables and the 14 `files` rows
outside `demo/` identical; 97 files in `storage/app/private/demo` before and after (73,817 → 2,135,566 bytes = sum of
`files.size`). Read back seven files (KRENOVA, piagam O2SN, juknis FLS3N, surat WICE, FLS3N nasional, OSN SMP, AYRC): size
and SHA-256 equal the row, `%PDF-1.7`, 1 / 1 / 3 / 1 / 1 / 1 / 1 pages. All 97: `%PDF-`, 117 pages in all.

## Files (owned by me)
- `database/seeders/Support/Demo/` (new): `DemoDocumentKind`, `DemoDocument`, `DemoPdfRenderer`, `DemoArt`, `DemoPeople`,
  `DemoAward` + `DemoCertificate`, `DemoResearchReport`, `DemoEvent` + `DemoGuideline`, `DemoLetter`,
  `DemoDocumentResolver`, `DemoFileRefresher`, `DemoSamples` (one fixed sample per kind, no database)
- `database/seeders/demo/*.blade.php` (new, 8 templates) · `app/Console/Commands/RefreshDemoFiles.php` (new)
- `database/seeders/Support/DemoFiles.php` — `realistic()`, `forceRealistic()`, `researchReportName()`; `pdf()` unchanged
- `database/seeders/DemoSeeder.php` — `STAFF` / `STUDENTS` public; research report file on a report with a research
  title; after seeding, refresh when `DemoFiles::realistic()`
- `tests/Feature/Demo/DemoDocumentsTest.php`, `RefreshDemoFilesTest.php` (new). No existing test file was changed.

## Commands verified
- `docker compose run --rm --no-deps app php artisan test tests/Feature/Demo` → **20 passed, 1 skipped (779 assertions)**
- `docker compose run --rm --no-deps -e STH_DEMO_FULL=1 app php artisan test tests/Feature/Demo` → the skipped one too
  (full seed, 98 files rendered and scanned; 19 passed (1171) before the last two small tests were added)
- `… php artisan test tests/Feature/Demo tests/Feature/DataLayer/SeedersTest.php tests/Feature/Integration/RankConsistencyTest.php tests/Feature/Siswa/Peringkat/SiswaPeringkatDemoTest.php`
  → **29 passed, 1 skipped (857 assertions)** (= every test that mentions `DemoSeeder` / `DemoFiles`)
- `… ./vendor/bin/pint --test database/seeders app/Console/Commands/RefreshDemoFiles.php tests/Feature/Demo` → PASS (26 files)
- Full suite, `npm run build`, E2E: (not run)

## Not verified
- Nobody opened the PDFs in a browser or PDF viewer, and not in the M3-C preview popup. I rasterized them with pypdfium2
  (PDFium) on the host and looked at the pictures: every sample kind and look, both letters, and thumbnails of the first
  page of 48 of the 97 refreshed files. Not checked: printing, Firefox / Acrobat, pages after the first of the others.
- A real `migrate:fresh --seed` on MySQL (the seeder's refresh ran only in the test with `STH_DEMO_FULL=1`).
- The invented signer names were not searched for real namesakes.
- Tailwind may read `database/seeders/demo/*.blade.php` as a source; the class names there are Indonesian words (not checked).

## Assumptions / deviations (*sementara*)
- Demo letters = real template + CONTOH footer + sample signature (not byte-equal to what "Buat PDF" makes today).
- Certificates name the organizers of the demo data (Puspresnas, Universitas Diponegoro, …) as the screens do.
- Own renderer instead of `App\Domain\Letters\PdfRenderer` (that one is portrait and `resources/pdf` only); same lock-down.
- `STH_DEMO_FILES=placeholder` (process env) seeds local demo data without rendering.
- `letters.printed` stays null for demo letters; the preview rebuilds the same content the PDF was made from.

## Requests for other owners
- @lead / coordinator: the lead's database has **no research report file** (a fresh seed attaches one; the command may not
  add rows). `docker compose exec -T app php artisan sth:demo:refresh-files --lengkapi` adds exactly one `files` row and
  one `report_files` row (Keisha's LKTI report, then rendered). Not run by me: your decision.
- @I: `tests/E2E/support/setup-app.sh` seeds with `APP_ENV=local`, so the E2E seed now renders 98 PDFs (about 30 s on this
  laptop, less on Linux). Set `STH_DEMO_FILES=placeholder` on the E2E app service if that matters. DEPLOY: nothing ships.
- @S (letters): php-svg-lib selects Times-Roman for every SVG shape and only finds it when its metrics are in the font
  cache, so a dompdf PDF with an SVG (the letters' `kurung-kurawal.svg`) can differ by one unused font entry between an
  empty and a filled `storage/app/pdf-fonts`. Harmless; relevant only if letter hashes are ever compared.
- Docs (coordinator): CLAUDE.md §4 command table + §5 Seeders, `dokumentasi.md` (demo files are now real-looking).

## For the docs (plain)
File demo (sertifikat, piagam, laporan penelitian, juknis, brosur, poster, surat tugas) sekarang berisi dokumen contoh
yang tampak seperti aslinya, bukan lagi satu halaman kosong. Isinya mengikuti data demo: nama siswa, lomba, capaian,
tanggal. Semua nama fiktif, penanda tangan rekaan, dan tiap halaman bertulisan "CONTOH · data fiktif untuk uji SMAGA
Talent Hub · bukan dokumen resmi". Hanya dibuat di komputer pengembang (APP_ENV=local). Untuk database yang sudah ada:
`php artisan sth:demo:refresh-files` (aman diulang, hanya mengganti file di folder `demo/`).
