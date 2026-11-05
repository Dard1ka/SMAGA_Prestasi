# SV7 — bigger signature, complete student Excel, Kelas for TU and Kesiswaan, Hapus permanen, remembered password (2026-11-05)

`smaga_server` only. Decisions D-189…D-193 (DECISIONS §D30). Built on the lead's SV5/SV6 (commit 6b64e7c): the
coordinator (signature), one agent (Excel + Kelas for TU/Kesiswaan + Sampah), one agent (remembered confirmation).

## The lead's six requests and the choices
1. Signature + stamp in the letter PDF bigger, may cover words; "verif dulu baru bilang done" → D-189 (2x the box).
2. Student Excel complete; from Kelas everything but the activation status, from Status aktivasi everything incl. status; one
   class chooser everywhere, for every role → D-190. Choice: birth date and phone included.
3. The second password prompt remembered in a browser cookie → D-193. Choice: 30 days.
4. Sampah: "Hapus permanen" now instead of waiting 7 days → D-192 (button for D-186).
5./6. Tata Usaha's "Data siswa" replaced by the Admin Kelas page; Kesiswaan gets it too → D-191. Choice: same as Admin, the
   Sampah says who deleted.

## Checks
- Signature: `LetterLayoutTest` updated (2x, may run past its block by ≤ 20 pt, never off the page); letter tests 99 passed.
  Rendered the TU sample with the school's real signature image (page 1 student letter, guru letter pages 1–2) to PNG in a
  throwaway poppler container and looked at them: bigger, over the lines, name readable, page counts unchanged.
- Agents: Kelas/Excel/Sampah — full suite 4952 passed, 1 skipped (with the other workers' changes); remembered confirmation —
  17 new tests, 625 related passed.
- Laptop (2026-11-05): `RoleSeeder` re-run, `dev-cache.sh --force`; three fictional students imported, both Excel kinds
  written and read back (Data siswa: sheets X, XI, columns No…Email; Status aktivasi: + status columns, Ringkasan last; NISN
  zeros kept, DD-MM-YYYY). Browser as `tu1`: menu "Kelas" (no Data siswa), `/tu/data-siswa` → `/tu/kelas`, Lihat siswa with
  Ubah data / Ubah status / Hapus / ticks / download chooser (Semua, X, XI, XII + classes); Hapus → no second password (the
  login counted), Sampah row "Dihapus 5 Nov 2026, 21.32 oleh Tata Usaha 1 (Tata Usaha)"; Hapus permanen → toast, student and
  account gone from the database, one `trash.purged_by_admin` row. As `kesiswaan`: menu "Kelas", same page. No console error.
  Test students removed again (laptop: 0 students, 36 classes).
- Full suite after all changes: TEST-RESULTS below.

## Deploy
Migrations of SV5/SV6 (2), `RoleSeeder` (new `student.status`), then the usual reload. Steps: PANDUAN-DEPLOY-SERVER.md G.

## Open (for the lead)
- Added by the worker: a "Bukan saya?" freeze stays Admin-only; Kesiswaan also gets Ubah status on Status aktivasi; the
  chooser lists only classes with students; texts still saying "Admin" for a freeze made by TU/Kesiswaan.
- D-193 trade-off on shared PCs (see DECISIONS).

## TEST-RESULTS
- Full suite (`--parallel --processes=6`, all SV7 changes): see the lines below.
  Tests:    1 skipped, 4952 passed (30573 assertions)
  Duration: 1365.71s
