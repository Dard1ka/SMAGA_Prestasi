# SV3: STH hero, import template ready to fill, Status aktivasi Excel per tingkat (D-174) · status: built 2026-11-03

The lead's requests of 2026-11-03 for the school's server, **`smaga_server` only** (branch `smaga-server`; Smaga4 is
untouched). Decision: DECISIONS §D26, D-174.

## What changed
- **Public home hero** (`resources/views/livewire/publik/beranda.blade.php`, the same copy in
  `resources/views/dev/public.blade.php`): title "SMAGA TALENT HUB (STH)", line "Talent Scouting, Competition & Achievement
  Management System"; the eyebrow, the old title and the old sub line are gone; "Lihat prestasi" stays.
- **Import template** (`App\Livewire\Akun\ImportSiswa::downloadTemplate()`, constants in `App\Actions\Accounts\ImportStudents`):
  - `TEMPLATE_HEADER` = the six names with "(wajib diisi)" / "(opsional)"; `normalizeHeader()` drops that part, so
    files of the older template (plain names) and CSVs with either header still read.
  - `TEMPLATE_WIDTHS` = column widths A–F in Excel characters (each ≥ header and example + 2), set on the sheet before
    the first row (OpenSpout `Sheet::setColumnWidth()`).
  - Row 2 = `TEMPLATE_EXAMPLE`, grey (`808080`) italic, fictional (DESIGN-SPEC-v2 §5); its text columns keep the Text
    format. `isTemplateExample()` skips a row that still equals it (digits compared as digits), like a blank row: it is
    neither a student nor an error. The empty Text rows below it keep the total at `MAX_ROWS`.
  - One sentence under the column chips: "Kolom selain No HP wajib diisi. Baris contoh di bawah judul boleh ditimpa atau
    dibiarkan."
- **Yearly re-import**: unchanged (D-81, NIS **and** NISN); the existing test "moves a registered student to the class in
  the file and leaves the account alone" pins it.
- **Status aktivasi download** (`App\Livewire\Akun\StatusAktivasi::export()`, `App\Actions\Accounts\ActivationReport::writeXlsx()`):
  - `$unduhTingkat` (not in the address) with `DOWNLOAD_LEVELS` ('' Semua kelas, '10', '11', '12'); an unknown value
    answers 404 before the password check, with no file and no audit row.
  - `writeXlsx(Collection $rows, string $path, ?int $level)`: null = sheets `X`, `XI`, `XII` always (header only when
    empty) + `NO_LEVEL_SHEET` "Tanpa kelas" only when a row's class level is outside `LEVELS` (only broken data: the
    domain refuses other levels); an int = that one sheet. Rows keep the order of `rows()`; every cell is a formula-safe
    Text cell (`cell()`), header bold, widths set. `writeCsv()` is gone.
  - The file follows the status tab and the search; the class filter of the list (`daftarKelas`) is not used for it.
    Audit `account.activation_report_exported` gains `tingkat` (`semua` | `X` | `XI` | `XII`); `filters` no longer
    carries `kelas`. Filename `status-aktivasi-<semua|x|xi|xii>-Ymd-His.xlsx`. Password confirmation and
    `LargeExportNotice` unchanged.
  - The CSV hint line ("Buka di Excel lewat Data › Dari Teks/CSV …") is gone; the button reads "Download Excel".

## Tests
`tests/Feature/Publik/PublikPagesTest.php`, `tests/Feature/DesignSystem/ComponentsTest.php` (hero);
`tests/Feature/Accounts/ImportStudentsTest.php` (new header everywhere, `W1C_OLD_IMPORT_HEADER`, widths, example row
style, example row skipped, old header + CSV accepted); `tests/Feature/Aktivasi/StatusAktivasiTest.php` (sheets per
tingkat, one tingkat, empty sheet, "Tanpa kelas", status tab instead of the class filter, unknown tingkat 404,
formula-safe + NISN as text + masked email, audit `tingkat`).

## Deploy on the school's server
No migration, no new package, no `.env` change. Replace the code, then `reload.bat` (or
`docker compose exec -T app sh docker/php/dev-cache.sh --force`) and `docker compose restart queue scheduler`.

Not code, done on the server by whoever deploys (values travel only in the private server package, never in this repo):
the temporary password of the six staff accounts, and the letter signer (name, pangkat, NIP, signature + stamp image) in
Admin › Pengaturan › Surat & tanda tangan. Letters issued before keep what their PDF printed (D-108).
