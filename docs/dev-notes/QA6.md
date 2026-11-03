# QA6 — explore round after PR 14 (2026-10-07)  ·  status: built, not committed

Third exploration after PR 14 was merged (code of `e48de10`, merged into `main` as `d7b9d97`) in the app's built-in browser: end-to-end flows across roles
(registration → Kepala Sekolah → TU letters → QR check → Arsip) and edge inputs (long unbroken words, HTML in names,
past dates, unanswered team invitations). Where the client document "SMAGA TALENT HUB (STH)" says nothing, the lead
left the UX to us ("tentukan sendiri uxnya yg enak"): new decisions **D-113…D-116** (DECISIONS §D10; D-113 is
*sementara*). Every fix test-first (red seen, then green). Fictional data only; no migration.

## Files
- `app/Livewire/Kepsek/Persetujuan.php`, `resources/views/livewire/kepsek/persetujuan.blade.php` — confirm dialog
  for "Setujui terpilih" (`BULK_DIALOG`), checkboxes named after the student
- `app/Livewire/TataUsaha/SuratTugas.php`, `resources/views/livewire/tata-usaha/surat-tugas.blade.php`,
  `resources/views/livewire/tata-usaha/arsip-surat.blade.php` — shared invitation fields carried into the other letter
  (D-116), `?koreksi=` from Arsip surat, confirm dialog for "Kirim" (`SEND_DIALOG`)
- `app/Http/Controllers/LetterVerificationController.php` — issued letters verify before "Kirim" (D-113)
- `app/Actions/Talent/{UpdateTalentAssessment,MoveTalentStage,RejectTalentApplication}.php`,
  `app/Livewire/Kesiswaan/Talenta.php`, `resources/views/livewire/kesiswaan/talenta.blade.php` — assessment date not
  before today (D-114), long words in the detail panel
- `app/Actions/Registrations/WithdrawInvitation.php` (new), `app/Notifications/Registrations/InvitationWithdrawn.php`
  (new), `app/Livewire/Siswa/Pendaftaran/Form.php`, `resources/views/livewire/siswa/pendaftaran/form.blade.php`,
  `app/Livewire/Siswa/Lomba/Detail.php`, `app/Livewire/Admin/AuditLog.php` — withdraw an unanswered invitation (D-115),
  "belum aktivasi akun" in the member search, registrant count refreshed
- `docs/DECISIONS.md` (§D10), `docs/ARCHITECTURE.md` (§8b row, §8g), `CLAUDE.md` (§2, §5, §8, §9, §10)

## What changed (finding → root cause → fix)
1. **Kepala Sekolah › Persetujuan, "Setujui terpilih" approved at once** (Q1-10). One click approved every ticked
   row, with no list of what was ticked. The button now opens a dialog "Setujui N pendaftaran?" listing lomba and
   students; "Setujui N pendaftaran" in the dialog runs `approveSelected()`, which closes it.
2. **Persetujuan checkboxes all said "Pilih <lomba>"**. Two registrations of one lomba had the same label for a
   screen reader. Label: "Pilih <first member>[ dkk.], <lomba>".
3. **TU "Kirim" sent at once** (Q1-10). Sending notifies every member and the guru pembimbing and cannot be taken back
   (only Koreksi = revoke + new version). Once every letter exists the button opens "Kirim surat sekarang?" (both
   letter numbers, who is told, what a later change costs); while a letter is missing the button still names it at
   once.
4. **TU, the guru letter did not take the invitation typed for the student letter** (Q1-15, #61). Each form started
   from the lomba only. `SuratTugas::carrySharedFields()` copies "Surat dari", the invitation number and date, hari,
   tanggal, pukul and tempat into a letter that is not issued yet when TU typed them in the other form or the other
   letter is issued (on mount, on a tab change, after "Buat PDF"); never into a field TU typed for that letter
   (`$typed`), never while correcting (D-116).
5. **Arsip surat › "Koreksi" opened the student tab, not the chosen letter** (Q1-13, #61). The link only named the
   registration. It now carries `?koreksi=<letter id>`; `mount()` opens that current letter's correction form
   (`startRevision()`, which authorizes `revoke`); an unknown or revoked id just opens the page. The parameter is
   emptied when the correction is cancelled or saved.
6. **QR check said "Tidak ditemukan" for a letter TU had already printed** (Q1-23, #61). Only sent letters were
   looked up, but "Cetak" exists from "Buat PDF" on. Every issued letter is now known: "Sah", or "Dicabut" with the
   replacement number (D-113, *sementara*: the lead may prefer "no Cetak before Kirim").
7. **Kesiswaan › Talenta, a 2020 assessment date was accepted and sent to the student** (Q2-13, #62). A new or changed
   date before today is refused ("Jadwal asesmen tidak boleh sebelum hari ini."); the saved date stays valid so the
   panel can be saved after the assessment (D-114).
8. **Kesiswaan › Talenta, one long word of the student widened the page by 385 px** (Q2-29, #65). The panel's `dl`
   used `grid-cols-[110px_1fr]` (a `1fr` column grows to its longest word). Now `minmax(0,1fr)` and
   `[overflow-wrap:anywhere]` on the student's texts (Minat, Organisasi, Prestasi SMP). Checked at 1366 and 390 px.
9. **Team invitations could not be withdrawn, and nothing said a classmate had not activated** (Q1-22, #66). An
   unanswered invitation held the place until the registration deadline. New `WithdrawInvitation` (ketua only, while
   the team waits and registration is open, only an unanswered anggota row; the row is deleted so the student may be
   invited again; D-36 rule afterwards; audit `registration.invitation_withdrawn`; `InvitationWithdrawn` to the
   student). The panel shows an X "Batalkan undangan <name>" next to each "Menunggu" member. Member search marks
   "belum aktivasi akun" (D-115).
10. **Detail lomba, "N siswa SMAGA sudah mendaftar" stayed old after registering** (minor). The "Daftar lomba" panel is
    a child component, the count belongs to the page. The panel dispatches `Form::CHANGED` after send, cancel, invite
    and withdraw; `Siswa\Lomba\Detail` listens and renders again.

Checked and not a bug: HTML and `<script>` in lomba names / organizers are escaped everywhere (no `{!!` on user
text); Kelola lomba keeps a long unbroken lomba name inside its card at 1366 px; "Pilih semua" in Persetujuan follows
the selection; the report deadline on the student screens is `final_on` + 45 days.

## Review of the change set (code reviewer agent, then fixed test-first)
- The full suite caught one test of the member search that lists the result keys exactly (`SiswaScreensTest`): the
  new `pending` flag is deliberate (a boolean like `busy` / `declined`; still no NIS, NISN or contact data), the
  expected list now includes it.
- The first carry rule ("empty or still the lomba prefill") could not tell a prefill from a value TU typed that happens
  to be equal, so a guru field set back to the lomba value or emptied on purpose was filled again on the next tab
  change. Now `$typed` remembers the keys TU typed per letter; red test first.
- Coverage the reviewer asked for: a saved past assessment date through `MoveTalentStage` and
  `RejectTalentApplication`; `?koreksi=` with a letter of another registration and a revoked one, kept while the
  correction is open and dropped after `revise()`; `WithdrawInvitation` refused after the deadline and in
  `menunggu_kepsek`; `InvitationWithdrawn` rendered (array, mail) after a queue round trip.
- Not changed: `w2cDraft()` in `SuratTugasTest` prints the fixed letter date 2026-10-01, and `IssueLetters` refuses a
  letter date older than one year, so those tests (old and new) start failing on 2027-10-02; existing tests assert
  that month and year, so the helper is a separate change (request below).

## Commands verified
- RED: the 15 new or changed tests failed for the missing behaviour (no dialog, 404 for an issued letter, no prefill,
  no `koreksi`, no `WithdrawInvitation` class, no `pendaftaran-berubah` event, past date accepted, no wrapping class).
- GREEN: `php artisan test` on the seven touched files → 164 passed; `pint --dirty` clean; `npm run build` OK (the
  two new Tailwind classes are in the CSS). After the review: the new carry test red (93 others passed), then `SuratTugasTest` + `RevokeLetterTest` → 50 passed.
- Browser (built-in Chromium, demo Kesiswaan account): Talenta panel with a long word at 1366 px (sideways overflow
  385 px → none) and 390 px; "Jadwal asesmen" 15 Jan 2020 + "Simpan" → the new message, nothing saved.
- Not clicked in the browser: the Kepala Sekolah, TU and student screens (a demo login from this session was not
  possible); they are covered by the Livewire tests above, which render the real views.

## TEST-RESULTS
- First full run `php artisan test --parallel --processes=4`: 2242 passed, 1 skipped, **1 failed**
  (`Siswa/Lomba/SiswaScreensTest`, the key list of the member search; see the review section), 1079 s.
- After the review fixes: `php artisan test --parallel --processes=4` → **2248 passed, 1 skipped (12968 assertions)**,
  exit 0, 1066 s. `pint --test` → PASS (658 files). `npm run build` OK.
- Not run: the email scenario and the Playwright smoke (backlog #46); Firefox / Safari / a real phone.

## Requests for the lead
- D-113 is *sementara*: issued letters verify before "Kirim". The other choice from the QA list was "no Cetak or
  Download before Kirim".
- `tests/Feature/TataUsaha/SuratTugasTest.php` `w2cDraft()` uses the fixed date 2026-10-01: move it to a date relative to
  today before 2027-10-02 (and the assertions on month / year with it).
- D-115 adds a mail ("Undangan tim dibatalkan"): one line in PANDUAN-PENGGUNA (Siswa) and a step in the email
  scenario (backlog #46).
- Please click through the Kepala Sekolah "Setujui terpilih" dialog, TU "Kirim" dialog, Arsip surat › Koreksi and the
  student "Batalkan undangan" once in a browser.
