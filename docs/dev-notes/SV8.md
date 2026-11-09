# SV8 — guru letter like the school's PDF, student Dasar list, teams above 3, Data guru, guru pembimbing by Kesiswaan, registration revision (2026-11-09)

`smaga_server` only (Smaga4 unchanged). Decisions D-194…D-199 (DECISIONS §D31). **Not committed** (base `7a2ae54` = SV7 on
`smaga-server`). Built by the coordinator and several agents in parallel, one package per decision, then this docs
package. The school's example PDF of the guru letter carries real names and NIP: it and every measurement made on it stay
in the coordinator's scratch folder outside the repo; tests, docs and comments use fictional people and numbers only
("Drs. Bambang Hartono, M.Pd.", made-up NIP and letter numbers).

## The lead's requests (9 Nov 2026) and the choices
- Guru letter: "samakan persis seperti template pdf nya harus identik 100%" (with the school's PDF) → D-194. Signature:
  "tanda tangan kebesaran, dikecilin sedikit. Ttd sama stempelnya juga digeser agak ke kiri" → D-194 (80 % of SV7,
  17 pt left; *sementara*, our choice of the amounts).
- Student letter: "Di tu, template surat siswa, dasarnya baru bisa 1. Dibuat bisa lebih dari 1 kayak surat guru" → D-195
  (at most 6: our choice).
- Teams: "Siswa tim bisa lebih dari 3 orang, jangan dibatasi 3" and "Cek juga tabel nama di suratnya tetap rapi kalau
  anggotanya banyak" → D-196 (the bound 30: *sementara*, our choice).
- Data guru (what the guru letter prints under "Kepada") → D-197.
- Item 4, guru pembimbing chosen by the student or set by Kesiswaan: the lead answered "Keduanya" → D-198.
- Item 6, registration revision (e.g. another team member): the lead answered "siswa boleh mengajukan revisi dalam kondisi
  apapun entah belum di acc tu atau kesiswaan maupun sudah di acc. Kalau sudah di acc siswa tetap bisa revisi namun perlu
  persetujuan TU atau kesiswaan dulu untuk acc revisi tersebut" → D-199 (no revision once the report was sent:
  *sementara*, our choice, because points are given per member).
- Item 7 (the guru letter template) waits for the school, which will also send a **second version** of the guru letter
  later (see "Open").

## What was built

### Guru letter like the school's PDF, signature (D-194)
- `resources/pdf/surat-perintah-tugas-guru.blade.php` picks the layout; new partials `resources/pdf/partials/kop-guru`,
  `guru-langsung` (the one-page letter), `guru-paragraf` (one justified paragraph, line by line); `partials/kop`,
  `partials/ttd`; `resources/pdf/layout.blade.php` (`.kop-g`, `.g-*`, `.verif-guru`, `.s-ttd` / `.g-ttd`); new emblem
  `resources/pdf/assets/logo-pemprov-guru.png` (the PDF's Pemprov emblem; an institution emblem, no personal data).
- `App\Domain\Letters\JustifiedLines` (new): breaks a justified paragraph the Word way (every line but the last ends at
  548.46 pt, one space before the 551.8 pt column edge; spaces may narrow to `MIN_SPACE` 0.8 so a word still fits; each
  space rounded down to `GRID` 0.05 pt) and prints each line with its own `word-spacing`.
- `LetterTemplate`: `GURU_LAYOUT_LANGSUNG` (new letters), `GURU_LAYOUT_LAMPIRAN` (letters printed before), `GURU_LAYOUTS`,
  `guru_layout` in `toArray()` / `fromArray()` (a stored template without it reads as `lampiran`, so the preview of an
  old letter keeps its Lampiran, D-108), `kopLineParts()` (the web host blue + underlined). `LetterContent`:
  `guruBasis(bool $twoDigitDay = true)`, `guruTaskParts()` (kegiatan bold = the lomba name as entered), `guruSignedOn()`
  ("04 November 2026"). `LetterPrintController::LOGOS` serves `pemprov-guru` to the TU preview.
- TU preview: `tata-usaha/partials/kop-guru`, `pratinjau-guru` (dispatcher), `pratinjau-guru-langsung`,
  `pratinjau-guru-lampiran`, `partials/ttd` (dashed box 92 × 183 px student / 87 × 174 px guru, 16 px left).
- Measured numbers matched (the school's PDF, pdfplumber, coordinator's `measure.py`): A4, page margin = the left
  emblem's corner 55.2 pt / 23.8 pt; Arial 12 pt (Helvetica in dompdf), line pitch 14.89 pt, "Kepada" rows 13.8 pt
  (dompdf line-height = pitch / 1.0175); "Dasar" / "Untuk" label x 90.42, colon 147.85, number 163.05, text 181.05 pt;
  "Kepada" label 85.03, colon 164.65, "1." 178.85, labels 192.85, colon 270.88, value 277.7 pt; signature x 333.3 pt;
  Pemprov emblem 81.7 × 85.05 pt, school emblem 63.85 × 85.05 pt at x 513.6 pt, 2.55 pt lower; kop lines centred on x
  322.53 pt, first line 30.72 pt; rule 2.7 pt + 0.9 pt gap + 0.9 pt from x 78.85 to 552.6 pt at 114.99 pt.
  Comparison of the rendered fictional letter with the PDF: 40 text lines, 39 within 0.38 pt (tops within 0.15 pt), the
  9 pt kop address line 0.89 pt off at its ends; both emblems at the same boxes. Not compared on paper.
- Signature + stamp: 80 % of SV7: student 121.9 → 97.52 pt (box 60.95 pt, `top` −18.285 pt, `left` −17 pt), guru
  116 → 92.8 pt in the 44.67 pt box of the school's three empty lines (`top` −24.065 pt, `left` −17 pt). No line or page
  moves. Letters issued before keep their PDF.
- Tests: `tests/Feature/Letters/GuruLetterTest.php` (new), `tests/Unit/Domain/Letters/JustifiedLinesTest.php` (new),
  `LetterLayoutTest`, `SuratTugasTest`, `TemplateSuratTest`, `RefreshDemoFilesTest`, `MailGreetingTest` (changed).

### Student Dasar list (D-195)
- `LetterTemplate`: `SISWA_DASAR` (`siswa_dasar`, `siswa_dasar_2` … `_6`), `SISWA_DASAR_MAX` = 6, `siswaDasarTexts()`,
  `withSiswaDasar()` (no gaps on save, number 1 required), `siswaDasarItems()` (replaces `siswaDasar()`).
  `LetterContent::siswaBasis()` = the template's texts, then the invitation as the last one (D-157); one text unnumbered.
- `TataUsaha\TemplateSurat` (`addSiswaDasar()`, `removeSiswaDasar()`, `moveSiswaDasarUp()`) + view ("Tambah dasar",
  "Naikkan", "Turunkan", "Hapus"); `tata-usaha/surat-tugas` (student tab "Dasar 1–N", "Dasar N+1 · Surat undangan
  lomba"), `partials/pratinjau-siswa`, `resources/pdf/surat-tugas-siswa.blade.php`, `LetterDraft` (comments).
- Tests: `tests/Feature/TataUsaha/Template/SiswaDasarListTest.php` (new), `TemplateSuratTest`.

### Teams above 3 (D-196)
- `Competition::MAX_TEAM_SIZE` = 30, `Competition::teamLimit()`; `CreateCompetition` ("Maks anggota" 2–30),
  `livewire/forms/competition-fields`; `CreateRegistration`, `InviteMembers`, `RevisionRules`, `Siswa\Pendaftaran\Form`
  use `teamLimit()`.
- Student letter member table (`.s-murid` in `layout.blade.php`): header row repeated on page 2, a row never splits, the
  header never alone at a page end, the last two rows with the signature. Rendered with 3, 10, 20 and 30 members, also
  with long names (scratch, fictional).
- Tests: `tests/Feature/Registrations/LargeTeamTest.php`, `tests/Feature/Letters/LetterTeamTableTest.php` (new).

### Data guru (D-197)
- `App\Livewire\Kesiswaan\DataGuru` + `resources/views/livewire/kesiswaan/data-guru.blade.php`, route
  `kesiswaan.guru.index` (`/kesiswaan/data-guru`, group `role:kesiswaan|admin`), menu items (Kesiswaan Data, Admin Data).
- `App\Actions\Users\UpdateGuruData` (new), `CreateStaffAccount::handleGuru()` / `guruErrors()` / `store()`,
  `UserPolicy::viewGuruData()`, `updateGuruData()`, `createGuru()`; audit `account.guru_data_updated` (field names only),
  label "Data guru diubah".
- Tests: `tests/Feature/Kesiswaan/DataGuruTest.php`, `DataGuruScreenTest.php` (new).

### Guru pembimbing by Kesiswaan (D-198)
- `App\Actions\Registrations\ChangeMentor` (new), `App\Notifications\Registrations\MentorAssigned` (new),
  `RegistrationPolicy::manageMentors()` / `assignMentor()`, `ApproveRegistration::mentorWasReplaced()` (latest change only,
  and only the approval's), `App\Livewire\Kesiswaan\Lomba\Pendaftar` + view (new), `Kesiswaan\Lomba\Index` + view
  ("N siswa" button), Log audit labels by `source`.
- Tests: `tests/Feature/Kesiswaan/GantiGuruPembimbingTest.php` (new).
- Fixed 2026-11-09: the first version refused once a letter that is not revoked existed (`MSG_LETTER`, "Tata Usaha perlu
  mencabut suratnya dulu"), but Tata Usaha has no separate "cabut" step (a Koreksi revokes and issues the next version at
  once from the registration's current data), so the guru could never be changed after the letters. Now `ChangeMentor`
  allows menunggu_lomba … menunggu_laporan (`RevisionRules::BEFORE_APPROVAL` + `AFTER_APPROVAL`), refuses
  `laporan_dikirim` with `MSG_REPORTED` ("Laporan hasil pendaftaran ini sudah dikirim, jadi guru pembimbing tidak bisa
  diganti lagi."), leaves the letters alone and, when a guru letter existed under the lock (`$locked->guruLetter()->exists()`:
  only the guru letter prints the guru pembimbing, the student letter does not), tells every Tata Usaha account that can
  log in (not the actor) once to make a Koreksi (`MentorAssigned` audience `tu`, like `ApproveRevision`; always the
  "diganti" wording, "Surat tugas guru yang sudah terbit masih memuat …": a guru letter needs a guru, so the first
  version's "Guru pembimbing diisi, surat perlu Koreksi" could never be reached and is gone); audit gets
  `guru_letter_issued` (was `letters_issued`, true for any letter). A student letter alone tells Tata Usaha nothing.
  Pendaftar: no "Surat tugas sudah dibuat." row text, the dialog line `ChangeMentor::TU_TOLD` only for a registration with
  a guru letter (`withExists` `guruLetter as has_guru_letter`), the toast reads the audit row
  (`ChangeMentor::guruLetterIssuedAtLastChange()`). Also: `Kepsek\Persetujuan` labels the guru "Guru pembimbing
  saat ini" when the latest change came from Kesiswaan or a revision (`ChangeMentor::latestSource()`), and the Log audit
  event filter says "Guru pembimbing diganti" (`AuditLog::MENTOR_APPROVAL_LABEL` keeps the approval's row label). Tests:
  `GantiGuruPembimbingTest` (changed), `MailGreetingTest` (the `tu` case).

### Registration changes and revisions (D-199)
- Migration `database/migrations/2026_11_09_100000_sv8_create_registration_revisions_table.php` (table
  `registration_revisions`), `App\Models\RegistrationRevision`, `App\Enums\RevisionStatus`, `Registration::revisions()` /
  `pendingRevision()`, `RegistrationStateMachine` (`menunggu_kepsek` → `menunggu_konfirmasi`).
- Actions (new): `RevisionRules`, `ChangeRegistration` (before the approval, at once), `RequestRevision`,
  `RespondToRevision`, `CancelRevision`, `ApproveRevision`, `RejectRevision`. Changed: `ApproveRegistration`
  (`MSG_BACK_TO_MEMBERS`), `Kepsek\Persetujuan` (that toast), `DeleteAllStudents`, `PurgeStudents` (revision rows).
- Policies: `RegistrationPolicy::change()` / `requestRevision()`, `RegistrationRevisionPolicy` (new).
- Screens: `Siswa\LombaSaya\UbahPendaftaran` + view (new), `Siswa\LombaSaya\Index` + view, `Siswa\UndanganRevisi` + view
  (new, route `siswa.undangan.revisi`), `Siswa\Beranda` (card), `Siswa\Pendaftaran\Form` (link), `Sekolah\RevisiPendaftaran`
  + view (new, routes `tu.revisi.index`, `kesiswaan.revisi.index`), `App\Support\RevisionQueue` (new), `Navigation`,
  `NavigationServiceProvider` (count `revisi.pendaftaran`), Log audit labels.
- Notifications (new): `RevisionInvitation`, `RevisionAnswered`, `RevisionAwaitingDecision`, `RevisionDecided`,
  `RevisionWithdrawn`, `RemovedFromTeam`.
- Tests: `tests/Feature/Registrations/RevisiPendaftaranTest.php`, `RevisiPendaftaranScreenTest.php` (new),
  `RegistrationStateMachineTest`, `NavigationBadgesTest` (changed).
- 2026-11-09: `ApproveRevision` refuses a revision that changes the guru when the registration's guru is no longer its
  `mentor_from` (Kesiswaan changed it since, D-198; it used to apply `mentorTo()` and overwrite that choice):
  `ApproveRevision::mentorChangedSince()`, key `revision`, `MSG_MENTOR_CHANGED` ("Guru pembimbing pendaftaran ini sudah
  diganti sejak revisi ini diajukan. Tolak revisi ini; ketua bisa mengajukan revisi baru."). `Sekolah\RevisiPendaftaran`
  shows it as the warning toast + refreshed list (`refused()`; `ask()` checks it too) and the row offers only "Tolak"
  (`MSG_MENTOR_STALE` as its `problem`). A revision that leaves the guru alone still passes. Tests: three new cases in
  `RevisiPendaftaranTest`.
- 2026-11-09: the guru letter is visible to the guru pembimbing only while it names that guru:
  `LetterPolicy::namesCurrentMentor(Letter)` (true for the student letter; for the guru letter: no `registration.mentor_changed`
  row of its registration at or after `generated_at`, else `created_at`, one query on the activity log's subject index; a
  change in the same second counts as later) is the mentor path of `LetterPolicy::view()` (so also `FilePolicy` /
  `files.show`, which answers 404), `Guru\BinaanDetail` (no row, no link; the line `GURU_LETTER_STALE`) and the student's
  "Surat tugas" card (`Siswa\LombaSaya\Index`: no "Untuk …" row for that letter). The Koreksi's new version (generated
  later, sent at once) is visible again; the guru before loses access too (no longer the mentor); the student letter and
  staff with `letter.view` are unchanged. Tests: `tests/Feature/Letters/GuruLetterAccessAfterMentorChangeTest.php` (new).

## Permissions (re-run `RoleSeeder`)
| permission | roles | decision |
|---|---|---|
| `registration.mentor` | Kesiswaan, Admin | D-198 |
| `registration.revise_decide` | Tata Usaha, Kesiswaan (**not** Admin: excluded in `RoleName` like `registration.approve`) | D-199 |
| `guru.update` | Kesiswaan, Admin | D-197 |
| `guru.create` | Kesiswaan, Admin | D-197 |

## Migrations
- `2026_11_09_100000_sv8_create_registration_revisions_table` (new table only, no row of another table changes;
  forward-only in production). Not run on the local MySQL by the agents (the lead runs it).

## Server steps (PANDUAN-DEPLOY-SERVER.md part G "Rilis SV8")
1. Laptop: full tests and Pint, then **build the assets** (`npm ci && npm run build`): new Blade views use Tailwind
   classes the old `public/build` does not have. Commit, package.
2. Server: E4 backup → E5 code → **E6a migrate** → **E6c `RoleSeeder` (required)** → E7 (`dev-cache.sh --force`, restart
   queue + scheduler). No container rebuild, no Composer change.
3. Browser checks per feature (listed in the guide).

## Open (for the lead / the school)
- The school's second version of the guru letter and item 7 (the guru letter template): a second layout is one more key
  in `LetterTemplate::GURU_LAYOUTS` with its PDF partial, its preview partial and its CSS; letters keep the layout they
  were printed with.
- *Sementara*, our choices: signature 80 % and 17 pt left (look at it on paper); at most 6 student Dasar texts; teams at
  most 30; no revision once the report was sent; one pending revision per registration; a revision never edits letters
  (Tata Usaha is told to make a Koreksi).
- "Tambah guru" on Data guru is part of D-197 as built (role guru only, Admins notified); say so if Kesiswaan should not
  create accounts.
- Not compared on paper: the guru letter (only line by line against the PDF), the two-page member table.
- Docs left as they were: the UI-MAP row "Data siswa" of Tata Usaha (gone since D-191, SV7) and the missing SV7 rows in
  UI-MAP / CLAUDE.md §8 (SV7 did not add them); CLAUDE.md §8 lists them as stale.

## TEST-RESULTS
- Package runs: in each package's own note to the coordinator.
- Full suite after all SV8 changes (incl. the 2026-11-09 follow-ups: guru change after the letters with the Koreksi
  notice for the guru letter only, the guru letter visible to a guru only while it names that guru, the stale revision
  refusal): `docker compose run --rm --no-deps app php artisan test --parallel --processes=12` → `Tests: 1 skipped,
  5194 passed (33527 assertions)`, Duration 1458.75s.
- `docker compose run --rm --no-deps app ./vendor/bin/pint --test` → `PASS … 1010 files`.
