# M3-C — file preview popup ("Buka" shows the file) + copy hook  ·  status: done

Lead: "ketika klik buka sertifikat tidak perlu langsung unduh, jdi bisa langsung muncul view popout nya tanpa harus
didownload terlebih dahulu tpi tetap menyertakan tombol download". Branch `feat/ui-uji-manual-m3`, nothing committed.

## What changed
- "Buka" on an evidence file opens one dialog (`#sth-preview`) with the file in it: PDF in the browser's own viewer,
  image as a picture. Footer: "Tutup" and "Download". Nothing is downloaded until "Download" is pressed.
- One dialog per page, in `layouts::app` and `layouts::mobile` (`<x-sth.preview />`). Not in `layouts::public` / `auth`.
- Applied to: Kesiswaan › Verifikasi hasil (every evidence file), Kesiswaan › Database prestasi (certificate in the
  drawer, popup opens on top of it), Guru › Siswa binaan detail (evidence; the letter rows are untouched),
  Siswa › Portofolio (own certificate; text now "Terverifikasi · buka").

## Contracts other areas can use
**Preview hook** (copy-paste; `$file` is an `App\Models\File`):
```php
'url' => route('files.show', $file),                    // download, stays the href
'preview' => FileController::previewUrl($file),         // files.show + ?tampil=1, null = no preview
'previewType' => FileController::previewType($file),    // 'pdf' | 'image' | null (from the stored MIME)
'name' => $file->original_name,
```
```blade
<x-sth.button variant="quiet" size="sm" icon="eye" :href="$f['url']" aria-haspopup="dialog"
    :data-sth-preview="$f['preview']" :data-sth-preview-type="$f['previewType']"
    :data-sth-preview-name="$f['name']">Buka</x-sth.button>
```
- `href` = download (no JavaScript, Ctrl/Shift/middle click, and the dialog's "Download"). On a `<button>`: `data-sth-preview-download`.
- `data-sth-preview` null → Blade drops the attribute and the link is a plain download (label it "Download").
- `data-sth-preview-type`: `pdf` | `image`; anything else → one sentence + "Download". `data-sth-preview-name` = dialog title.
- A page without the dialog (public, login) just downloads. Links added by a Livewire update work (delegated click).
- Only `files.show` sends a PDF inline. `files.public` never does (stays a download; images inline as before).

**Copy hook** (for M3-E, as specified): `<button type="button" data-sth-copy="#target-id">Salin data</button>` copies the
`innerText` of `#target-id`, toast "Data disalin." (success). No Clipboard API or it refuses → the text is selected, toast
"Tekan Ctrl+C untuk menyalin." (info). Unknown target or invalid selector → nothing happens.

## Security reasoning
What each answer carries (asserted in `FilePreviewTest`):

| answer | disposition | X-Frame-Options | CSP |
|---|---|---|---|
| any page | – | `DENY` | page CSP, now with explicit `frame-src 'self'`; `frame-ancestors 'none'`, `object-src 'none'` unchanged |
| download (default) | attachment | `DENY` | `default-src 'none'; sandbox` (unchanged) |
| image `?tampil=1` | inline | `DENY` | `default-src 'none'; sandbox` (unchanged; shown with `<img>`, never framed) |
| **PDF `?tampil=1` on `files.show`** | inline | `SAMEORIGIN` | `default-src 'none'; frame-ancestors 'self'` |
| other type `?tampil=1`, PDF on `files.public`, 404 | attachment / – | `DENY` | as download / page |

- The only relaxation is on the inline PDF answer: this origin may frame it, nobody else. No global change weakens a page:
  `frame-src 'self'` is what `default-src 'self'` already meant for frames; no `blob:`, `data:` or other origin.
- No `sandbox` there because Chromium does not start its PDF viewer in a sandboxed document. Checked in the browser:
  with `sandbox` in the answer's CSP, and with a `sandbox` attribute on the frame (even `allow-same-origin allow-scripts`),
  the frame stays empty. With `default-src 'none'; frame-ancestors 'self'` and the page's `object-src 'none'` it renders.
- Why a file still cannot run anything in the app's origin: the stored MIME is the server's own sniffing at upload, the
  answer says `application/pdf` + `nosniff` (never HTML), `default-src 'none'` stays, uploads with scripts/actions were
  refused by `PdfScanner` (D-70), and no pdf.js runs on our origin (the browser's viewer does the work). Inline types are
  an allowlist: `application/pdf`, `image/jpeg|png|webp` (was: any `image/*`; SVG, GIF, HTML, xlsx stay attachments).
- Policy, 404-for-not-yours, `auth`/`activated`/`staff.2fa` and `throttle:60,1` are the same route and the same code path.
- `Cache-Control: private, no-store` on every file answer, inline included.
- HEAD: the popup asks with HEAD first and only frames `200` + `application/pdf` + `inline`; so it never starts a download,
  and an expired session / 404 / 429 becomes one sentence. HEAD runs the same policy check, reads no bytes, is **not
  audited**. A preview is audited as `file.downloaded` with `inline: true`; "Download" adds a normal row.
- The script only frames an address of the same origin. Production: Apache uses `Header setifempty X-Frame-Options`,
  so `SAMEORIGIN` survives (read in `docker/apache/*.conf`, not run).

## Files (owned by me)
- `app/Http/Controllers/FileController.php` — inline PDF, HEAD, `INLINE_PARAMETER`, `previewType()`, `previewUrl()`
- `app/Http/Middleware/SecurityHeaders.php` — explicit `frame-src 'self'`
- `resources/views/components/sth/preview.blade.php` (new) — the dialog + its texts; `layouts/app.blade.php`, `layouts/mobile.blade.php`
- `resources/js/app.js` — hooks `data-sth-preview`, `data-sth-copy`; `resources/css/app.css` — `.dlg-preview`, `.preview-*`
- `app/Livewire/{Kesiswaan/VerifikasiHasil,Kesiswaan/DatabasePrestasi,Guru/BinaanDetail,Siswa/Portofolio}.php` + their views
- Tests: `tests/Feature/Files/FilePreviewTest.php`, `FilePreviewScreensTest.php` (new); shared file changed:
  `tests/Feature/Auth/SecurityHeadersTest.php` (+1 test, +1 assertion). No other shared test needed a change.

## Commands verified
- `docker compose run --rm --no-deps app php artisan test tests/Feature/Auth/SecurityHeadersTest.php tests/Feature/Security/CspSafeTest.php tests/Feature/Siswa/Lomba/FileDownloadTest.php tests/Feature/Integration/NginxHeadersTest.php tests/Feature/Kesiswaan/KesiswaanScreensTest.php tests/Feature/Kesiswaan/Prestasi/DatabasePrestasiTest.php tests/Feature/Guru/GuruAreaTest.php tests/Feature/Siswa/Hasil/PortofolioTest.php tests/Feature/DesignSystem tests/Feature/Auth/CredentialPageCachingTest.php tests/Feature/Files`
  → **233 passed (1387 assertions)**, exit 0. `tests/Feature/Files` alone: 33 tests.
- After the last JS/CSS edit: `… tests/Feature/Files Auth/SecurityHeadersTest Security/CspSafeTest Siswa/Lomba/FileDownloadTest Integration/NginxHeadersTest Auth/CredentialPageCachingTest` → 95 passed (699);
  `… tests/Feature/Siswa/Hasil tests/Feature/Kesiswaan/Prestasi tests/Feature/Guru` → 88 passed (503).
- `pint --test` on the 9 touched PHP files → PASS. `npm run build` → OK. Full suite: (not run, coordinator).

## Browser results (built-in browser of the desktop app: Chromium 152, `kesiswaan` and `retno.w`)
- Verifikasi hasil › "Buka": PDF visible with the viewer's toolbar, title = file name, focus on "Tutup" (X). Requests:
  HEAD 200, then GET 200 of `?tampil=1`; page address unchanged. No CSP violation (0 `securitypolicyviolation` events).
- Esc, X, "Tutup", click outside: dialog closes, 0 `<iframe>` left in the page, focus back on "Buka".
- Image (the PNG brochure `gambar-bernama.pdf`, opened through the same link): shown as a picture at its own size.
- Database prestasi: popup opens above the drawer; closing it returns to the drawer. Guru › binaan detail: evidence opens
  in the popup, the two letter links carry no hook.
- Narrow viewport (375×812): dialog fills the screen, no sideways scroll, buttons 44 px. Simulated "no PDF viewer":
  no request at all, sentence "Browser ini tidak bisa menampilkan PDF, download untuk membukanya.".
- Unknown type, missing file, missing image, an address that answers `attachment`, a foreign address: one sentence each, no frame.
- Not yours, in the address bar: as `kesiswaan` the Humas flyer → page "Tidak ditemukan" (404), with and without
  `?tampil=1`, GET and HEAD; as guru `retno.w` another guru's evidence → 404 in all four cases.
- Copy hook: three paths give the right toast and selection (clipboard stubbed, see below).
- One 504 in the console came from a hover prefetch of `/kesiswaan/talenta` during a php-fpm reload (not this change).

## Not verified
- Firefox, Safari, Edge, a real phone, production behind Apache. Unknown there: whether the viewer accepts
  `default-src 'none'` on the PDF answer (if a frame stays blank, drop that directive from the inline PDF only), and how
  iOS Safari shows a PDF in a frame. Android Chrome has no PDF viewer → it gets the sentence + "Download" (by design).
- "Download" was not clicked (it would save a file on the lead's disk); checked instead that its href answers
  `attachment; filename=…`. The real clipboard was not written (stubbed), so the lead's clipboard was left alone.
- A student in the browser (Portofolio, "not yours" as a student): demo students log in with the school password, which
  I do not type. Covered by tests (`FilePreviewTest` 404 for another student, GET and HEAD; `FilePreviewScreensTest`).

## Assumptions / deviations (sementara, for the lead)
- Esc does not close while keyboard focus is **inside** the PDF viewer (after a click in the PDF): the key goes to the
  browser's viewer. Esc works right after opening; X, "Tutup" and a click outside always work.
- HEAD on a file is not audited; a preview is a `file.downloaded` row with `inline: true`.
- A PDF preview costs 2 requests of the 60 per minute (HEAD + GET), "Download" 1 more: about 20 files a minute.
- Inline images narrowed from `image/*` to jpeg/png/webp. Public pages keep the plain download.
- The unload on close watches the dialog's `open` attribute, not the `close` event (it arrives late in a tab that is not painting).

## Requests for other owners
- @coordinator: same three attributes for `Kesiswaan/Talenta.php` (evidence, ~l.962), `Kesiswaan/Lomba/Index.php` +
  `Form.php` (juknis), `Humas/Arsip.php`, `Siswa/ProfilTalenta.php`, `Siswa/LombaSaya/Index.php`, `Guru/Lomba.php`,
  `livewire/siswa/lomba/detail.blade.php`. Letters (`FilePurpose::Letter`): the route would send them inline too; whether
  letters get the popup is the lead's call (task said: leave the letter link alone).
- @lead (docs): new DECISIONS entry "preview popup: PDF inline, same-origin frame, no sandbox"; SECURITY §3d ("preview
  inline hanya untuk gambar" → also PDF via `files.show`, with the table above) and §3f (`frame-src 'self'`); UI-MAP.
- @lead (optional): move `previewType()` to `App\Models\File`; show "preview" for `inline: true` in Admin › Log audit.
- @M3 seeder owner: demo data has no image evidence; one JPG evidence would let the image path be hand-tested.
- @M3-E: `data-sth-copy` is in the build.

## For the user guide (PANDUAN-PENGGUNA)
Klik **Buka** pada bukti atau sertifikat: file langsung tampil di jendela kecil di halaman yang sama, tanpa di-download.
PDF bisa digulir dan diperbesar, foto tampil utuh. Tombol **Download** di bawah jendela menyimpan file ke perangkat,
**Tutup** (atau tombol X, klik di luar jendela, tombol Esc) menutupnya. Di HP yang browsernya tidak bisa menampilkan
PDF, jendela berisi satu kalimat penjelasan dan tombol Download. Yang boleh membuka file tetap sama seperti sebelumnya.
