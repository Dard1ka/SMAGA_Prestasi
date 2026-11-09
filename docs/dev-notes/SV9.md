# SV9: letters made consistent after the school's feedback of 2026-11-09 (D-200) · status: built 2026-11-09

The school's feedback came from photos of the production server running a version before SV8. SV8 had answered part of
it; SV9 makes the two letters consistent. **`smaga_server` only** (branch `smaga-server`). Decision: DECISIONS §D32,
D-200.

## What changed
- **Signature + stamp** (`resources/pdf/layout.blade.php`): one image size on both letters, 92 pt high (`.s-ttd .gambar
  img`, `.g-ttd .gambar img`), `left: -30pt`, pulled up by half of (92 − box): student `top: -15.525pt` (box 60.95 pt),
  guru `top: -23.665pt` (box 44.67 pt). The TU preview placeholder (`tata-usaha/partials/ttd.blade.php`) follows: 87 ×
  174 px, `-translate-x-7`, student `-top-[17.5px]` / `-bottom-[17.5px]`, guru `-top-[22.5px]` / `-bottom-[22.5px]`.
- **Guru kop rule** (`.kop-garis-g`): `margin-left: 0; width: 522.25pt` = from the left emblem's edge (page margin
  55.2 pt) to the right emblem's edge (577.45 pt).
- **Dasar editor** (TU › Template surat): `LetterTemplate::GURU_DASAR` (`guru_basis_1` … `_6`; 4–6 built-in empty),
  `DASAR` (`siswa` / `guru` → fields), `DASAR_MAX` (6; `SISWA_DASAR_MAX` stays as its alias), `dasarTexts($group,
  $values)`, `withDasar($group, $values, $texts)`; `validate()` closes the gaps of both lists; `guruBasisItems()` reads
  the six fields. `TataUsaha\TemplateSurat`: `#[Locked] $guruDasarRows`, `addGuruDasar()`, `removeGuruDasar(int)`,
  `moveGuruDasarUp(int)` next to the student ones, one private implementation per action; one view partial
  `livewire/tata-usaha/partials/dasar-list.blade.php` draws both lists (`group` = `siswa` | `guru`). The guru texts stay
  optional (a guru letter always ends its Dasar with the invitation); the last visible guru row cannot be removed ("Surat
  guru perlu minimal satu baris dasar. Kosongkan barisnya bila tidak perlu."). Every row that may be empty is labelled
  "(opsional)".
- **Checked, not changed**: guru pembimbing by Kesiswaan (D-198), teams up to 30 (D-196; a fictional 30-member student
  letter runs onto page 2 with the header row repeated), revisions (D-199): their SV8 tests pass on this tree.

## Tests
`tests/Feature/Letters/LetterLayoutTest.php` (signature size, place and preview box), `GuruLetterTest.php` (kop rule),
`tests/Feature/TataUsaha/Template/GuruDasarListTest.php` (new), `SiswaDasarListTest.php` (the student "Tambah dasar"
button checked by its `wire:click`, since the guru list has one too).

## Deploy on the school's server
No migration, no new package, no `.env` change. **Rebuild the frontend assets** (`public/build` is not in git; new
preview classes): `docker compose run --rm node sh -c "npm ci && npm run build"`, or take `public/build` from the server
package. Then `reload.bat` (`docker compose exec -T app sh docker/php/dev-cache.sh --force`) and
`docker compose restart queue scheduler`. Letters issued before keep what their PDF printed (D-108).
