# EM2 — fixes after the lead's mail and notification test (2026-10-09)  ·  status: built and reviewed; branch `fix/em2-uji-email` (pushed, no PR yet)

On the lead's local `main` (`a2d21e1` = `origin/main` with EM1, QA9 and QA10). On 2026-10-09 the lead ran every mail and
bell notice of every role through Brevo with a test guide: 67 steps in 11 stories, all done, **4 marked "bermasalah",
19 with a note** (report `hasil-uji-email-sth-2026-10-09.html`, outside the repo). The tested code was EM1 (`4766b56`),
so QA9 and QA10 were not in it yet. Every note was first diagnosed in today's code (eight read-only diagnoses plus one
research on the mail provider), then seven implementer agents fixed in parallel, test-first, each on its own files.
Decisions **D-122…D-129** (DECISIONS §D13): what the lead asked for is final, what the coordinator chose is
*sementara* until the lead has looked. Fictional data only. Three migrations. After the build an independent review
confirmed 13 small findings; 12 were fixed in the code and 1 in the docs (section "Review"). Browser tools were off
for the implementers, the reviewers and the fixers; the coordinator looked at part of it in the app's browser on
2026-10-10: see "Checked in a browser" and "Not checked".

## What the report said → what was done
| step | the lead's note (short) | result |
|---|---|---|
| 1-3 **bermasalah** | the bell shows "1" only after a click on it, and stays after "Tandai dibaca" until another page | D-127, track 1 |
| 1-6 | after the fifth failed login the lock times looked odd (45, then 40) | no code: fixed by QA10 (`LoginLockout::secondsUntilAllowed()`, one clock); the test ran on the code before it |
| 2-1 **bermasalah**, 2-3 | `https://contoh.test/lomba-uji` refused for the student, `https://contoh.test/lomba-guru` accepted for the guru | track 2: not the role, not `.test` (one component, one rule; the address is accepted on all four link fields). Replaying the logged response sizes showed that the field did not hold a valid https address on the four refused tries (which character was wrong cannot be recovered), and the app answered every reason with one sentence. Now links are cleaned first and each refusal names its reason. No decision number |
| 2-2 | "Lainnya" for the organizer needs a field to type the kind | D-125, track 2 |
| 3-6 | "should be Arsip surat › siswa › Koreksi" | no code: the test guide named another path than the app has |
| 5-4 | the third team member (Keisha, demo data) got no mail | no code: that demo account is not activated and has no address yet, so there is nothing to mail |
| 5-6 **bermasalah** | the schedule is a reminder for Humas; the student's "Prestasimu sudah dipublikasikan" must wait for "Tandai dipublikasikan" | D-122, track 4 |
| 5-7, 10-6 | ask "are you sure" before a report is rejected | D-128, track 6 |
| 6-1 **bermasalah** | an event needs a time, not a date only; a search box for "Tambah prestasi" | D-123, D-124, track 5 (also: the guru got "Binaan masuk daftar apresiasi" twice for one team) |
| 10-7 | can a lomba update be told, e.g. to all students? | D-126, track 3: to the people attached to that lomba, never to all students |
| 11-2, 11-3, 11-4 | the raw mail, the tracked button address, the fallback address | D-129, track 7 (what the provider adds stays: tracking pixel, rewritten links) |
| 11-5, 11-13 | how to get rid of Gmail's "Berhenti berlangganan"; dangerous when pressed by accident | no code: not possible on Brevo below the Enterprise plan (research: addendum to D-121 in DECISIONS §D13) |
| 11-9 | stored mail content: keep for 1 month | no code: a setting in the Brevo account (1 month is the shortest log retention; previews can be "never stored") |
| 11-11 | 105 mails used that day | no code (the free plan allows 300 a day) |

## The seven tracks
1. **Bell and sidebar counts (D-127).** Akun › Notifikasi sends `sth-badges` with the key `Navigation::BELL`
   (`akun.notifikasi`) on every render; "Tandai semua dibaca" no longer reloads. The bell bubble is always in the DOM
   (hidden at 0, "99+" above 99). New `GET /notifikasi/jumlah` (`notifikasi.jumlah`,
   `NotificationCountController`): JSON numbers only (`NavigationServiceProvider::liveCounts()` = bell + the sidebar
   counts of the user's own menus), middleware of the Notifikasi page + `throttle:30,1,lonceng`, `no-store`,
   `reflash()`, not audited, no JSON headers → redirect to `notifikasi.index`. `app.js` ("live counts") asks it 2.5 s and
   8 s after a Livewire action, about every 60 s ± 10 s while the tab is visible and was used in the last 5 minutes, and
   0.3 s after Back / Forward; never next to a Livewire request; stops after two refused answers (401 / 403) until the
   next page; holds at least a minute on a 429, a 5xx (as long as `Retry-After` asks), no network or no answer in 15 s,
   and then asks again by itself. A higher bell number is announced ("Ada notifikasi baru."), no toast; the
   Notifikasi list refreshes itself (`data-sth-refresh-on`). Switch `STH_COUNTS_POLL_SECONDS` (`config/sth.php`: 60;
   1–29 read as 30; above 3600 read as 3600, `MAX_SECONDS`; 0 = off, the only way).
2. **Lomba forms: links and "Lainnya" (D-125).** `ExternalUrl::normalize()` (BOM, zero-width characters, soft
   hyphens, direction marks anywhere; whitespace of any kind at both ends; never adds a scheme) and
   `ExternalUrl::problem()` (one sentence per reason: `NO_HTTPS`, `HTTP`, `SPACE`, `HOST`, `IP`, `PORT`,
   `CREDENTIALS`, `CHARACTERS`, `TOO_LONG`, else `MESSAGE`) on all four link fields; the accepted set is unchanged
   (a test compares it with a copy of the old rule). The usulan sheet trims its text fields, checks the name with
   `NoWebAddress`, and errors raised by the Action (5 a day, a refused brochure) also dispatch `sth-form-invalid`.
   `competitions.organizer_type_other` (string 60): the field "Jenis penyelenggara lainnya" shows only while the
   select is "Lainnya", may be empty in the queue, is required to publish (`VerifyCompetition::missingFields()`), is
   dropped for any other type (`CreateCompetition::normalize()`), and prints as "Lainnya (Sanggar seni)"
   (`Competition::organizerTypeLabel()`: Guru › Lomba, Database prestasi, its Excel column).
3. **Lomba change notice (D-126, *sementara*).** `UpdateCompetition` on a published lomba compares
   `CompetitionChange::snapshot()` before and after. Changed facts (`FACTS`: deadline, dates, mode, location,
   participants, registration_url, website_url, juknis) go to the members nobody declined of the registrations that
   rely on them (deadline, participants: `menunggu_konfirmasi`, students only; the rest: up to `berlangsung`, plus
   `menunggu_laporan` for a new final date) and to their guru pembimbing (one notice per guru). Notifications
   `CompetitionChanged` (`competition.changed`) and `BinaanCompetitionChanged` (`competition.binaan_changed`); audit
   `competition.facts_changed` {changed, students, mentors, mail}, counts only; above `MAIL_LIMIT` = 50 recipients
   bell only. No sentence prints a web address: a link is only "diganti" / "dihapus", and a place with a web address in
   it (`NoWebAddress::found()`) is only "Tempat diganti." (the field has no address rule; its save is never refused).
   The drawer shows a hint when students are registered ("N siswa sedang terdaftar di lomba ini. Perubahan tanggal,
   tempat, pelaksanaan, link, atau juknis diberitahukan ke siswa itu dan guru pembimbingnya. Perubahan batas daftar
   atau peserta hanya diberitahukan ke tim yang masih menunggu konfirmasi anggota."; N = `attachedStudents()` = the
   members nobody declined of registrations up to `berlangsung`, not "the students a change is told to": that is
   `statusesFor()`) and a toast that names who was told. A "Periksa lomba" drawer whose lomba a colleague published
   meanwhile needs a second "Simpan": the first one only warns and turns the drawer into "Ubah lomba" with the hint
   (`Form::changedElsewhere($competition, bool $expectsQueued)`).
4. **Humas publication (D-122).** `RemindDuePublication` + job `RemindDuePublications` (every 5 minutes) replace
   `AutoPublishPublication(s)` (deleted, with `AutoPublishTest`): one `PublicationDue` to every active Humas account
   per schedule, `publications.reminded_at` stamped, audit `publication.reminded`; status stays `terjadwal`, nothing
   goes to the student. `Publication::isOverdue()` / `scopeOverdue()`, `HumasQueries::overduePublications()`, sidebar
   count `humas.jadwal`, chip and alert "Lewat jadwal". "Tandai dipublikasikan" = `askPublish()` → dialog
   `tandai-terbit` → `publish()`. Demo seed: the two past demo schedules count as reminded. The reminder (mail and
   bell), the alert "Jadwal sudah lewat" and the hint under "Tanggal publikasi" promise a student notice only when
   `PublishPublication::notifiesStudent()` is true, like the dialog and the toast. For an alumnus (archived account
   with publish consent, still in the queue) they read "… supaya statusnya menjadi Dipublikasikan.", "Sudah di-upload
   ke kanal? Tekan Tandai dipublikasikan. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang." and "Status baru
   menjadi Dipublikasikan setelah tombol Tandai dipublikasikan ditekan." (`PublicationDue` has a seventh constructor
   argument `bool $notifiesStudent = true`, set by `for()` and read with `?? true`, because a payload queued before the
   property existed is unserialized without it; `Publikasi::panel()` has the key `notifies`.)
5. **Humas appreciation (D-123, D-124).** `appreciation_events.held_time`; `EventWhen` (`long`, `sentence`, `short`,
   `parseTime`) is the only place that words "when"; `CreateAppreciationEvent::handle(User, string $name,
   DateTimeInterface|string $heldOn, string $heldTime)`; all four appreciation notifications and all five
   appreciation audit events carry the time. Picker on Apresiasi (search, ticks, steps of 10 up to 100, one
   "Masukkan", at most 30): `RecordAppreciations::handle(User, AppreciationEvent, iterable)` is all or nothing and
   sends one `BinaanAppreciationNotice::scheduledMany()` per guru; `RecordAppreciation` delegates to it. The box "Cari
   prestasi" keeps the text exactly as typed: `Apresiasi::updatedSearch()` must not write a trimmed value back into
   `$search`, because Livewire puts a value the server changed into the focused input ("nadia putri" became
   "nadiaputri", and letters typed during the round trip were lost). Only `picker()` / `searchAchievements()` trim the
   term; the hook only cuts a text above `SEARCH_MAX` = 100, which a real browser cannot send (`maxlength`). This
   replaces the line of the diagnosis that prescribed the trimmed write-back.
6. **Confirmations (D-128).** Ask step + confirm step (silent no-op without its ask, ids `#[Locked]`):
   Verifikasi hasil "Tolak" (`askReject()`, `tolak-laporan`), Daftar lomba "Batalkan undangan"
   (`askBatalkanUndangan()`, `batal-undangan`), Talenta bulk "Pindahkan ke …" (`askBulkAdvance()`,
   `pindahkan-talenta`), Kelola lomba "Tutup" (`askClose()`, `tutup-lomba`), Import siswa (`askImport()`,
   `impor-siswa`). `RejectReport::reason()`, `RequestReportRevision::audience()` (told / of / mentor): the banners of
   "Tolak" and "Minta revisi" name only who was told, and the revision banner adds "Ketua / Siswa bisa mengirim ulang
   laporan." only when the ketua's account can log in (`RequestReportRevision::leaderCanResend()`; only the ketua may
   send a report again). "Batalkan undangan?": the line under the title is "<Nama> langsung diberi tahu." when the
   invitee is told, else the neutral "Undangan ini belum dijawab." (a student screen never states another student's
   account status, SECURITY §3m-3, as on Undangan tim since QA10; the staff wording "Akun … tidak aktif, jadi tidak ada
   notifikasi." stays staff-only). A refused withdrawal, at the ask or the confirm step, is also a warning toast
   (`Form::refuseWithdrawal()`: "Undangan tidak bisa dibatalkan lagi: tim sudah lengkap dan diteruskan ke Kepala
   Sekolah." once the team went on, else "Undangan tidak bisa dibatalkan lagi untuk pendaftaran ini."), because the
   block that prints `members` errors is drawn only while the ketua may still invite. The toast after a withdrawal is
   read from the status `WithdrawInvitation` wrote under its lock and adds "Pendaftaran diteruskan ke Kepala Sekolah."
   when the withdrawal completed the team (another invitee may confirm while the dialog is open). Guru › detail
   binaan: "Tidak menjawab" for an expired invitation. `tests/Feature/Support/NoNativeConfirmTest.php`: no view uses
   `wire:confirm`. The dialogs are a pattern for the swept actions, not a rule for every sender: Apresiasi "Masukkan"
   and "Simpan perubahan" on a published lomba notify people without a question (D-128 lists both; a question for
   the lead below).
7. **Mail templates (D-129, look *sementara*).** `resources/views/vendor/notifications/email.blade.php`, components
   in `resources/views/vendor/mail/{html,text}`, theme `html/themes/sth.css`, `config/mail.php` (`markdown`).
   `MailGreeting` (`for` / `student` / `staff`, `NEUTRAL`) and `App\Support\FirstName` in all 29 `toMail()`
   implementations; every `->salutation()` removed; `PlainMailMessage::FOOTER`; `plain()` is public and the template
   prints greeting and lines through it. The OTP mail and `EmailBindingNotice` follow (new optional `greeting`
   property with a default, so a job queued before the change still unserializes). `sth:mail-test` ends with the
   same footer. Four framework mail lines left `lang/id.json`.

## Migrations (forward-only, no backfill, no index; `grants.sh` unchanged: grants are per table)
- `2026_10_10_100000_em2_add_organizer_type_other_to_competitions` — `competitions.organizer_type_other` string(60) null.
- `2026_10_10_100100_em2_add_reminded_at_to_publications` — `publications.reminded_at` timestamp null.
- `2026_10_10_100200_em2_add_held_time_to_appreciation_events` — `appreciation_events.held_time` time null.
All three ran on the lead's local MySQL on 2026-10-10 (coordinator); the assets were rebuilt, php-fpm was reloaded
and `queue` + `scheduler` were restarted the same day. On any other checkout: until they run, saving a lomba and the
publication reminder fail there.

## Files (new ones; changed files are in each track's diff)
- 1: `app/Http/Controllers/NotificationCountController.php`, `config/sth.php`,
  `tests/Feature/Notifikasi/LiveCountsTest.php`. Changed: `routes/web.php`, `layouts/app`, `<x-sth.topbar>`,
  `Akun\Notifikasi` (+ view), `resources/js/app.js`, `Navigation` (`BELL`), `NavigationServiceProvider`
  (`liveCounts()`), `.env.example`, `docker/env.production.example`.
- 2: migration, `tests/Unit/Rules/ExternalUrlTest.php`, `tests/Feature/Lomba/LinkFieldsTest.php`,
  `tests/Feature/Kesiswaan/LombaJenisLainnyaTest.php`. Changed: `ExternalUrl`, `NoWebAddress` (message),
  `Lomba\UsulanForm`, `SubmitCompetition`, `CreateCompetition`, `VerifyCompetition`, `Competition`,
  `Kesiswaan\Lomba\Form`, `Guru\Lomba`, `Kesiswaan\DatabasePrestasi`, `ExportAchievements`, factory, `DemoSeeder`.
- 3: `app/Actions/Competitions/CompetitionChange.php`, `app/Notifications/Competitions/{CompetitionChanged,
  BinaanCompetitionChanged}.php`, `tests/Feature/Kesiswaan/LombaChange{Fixtures,NoticeTest,NoticeScreenTest}.php`.
  Changed: `UpdateCompetition`, `Kesiswaan\Lomba\Form` (+ view), `Admin\AuditLog` (label "Info lomba diubah").
- 4: migration, `app/Actions/Publications/RemindDuePublication.php`, `app/Jobs/RemindDuePublications.php`,
  `tests/Feature/Humas/PublicationReminderTest.php`. Deleted: `AutoPublishPublication`, `AutoPublishPublications`,
  `AutoPublishTest`. Changed: `Publication`, `SchedulePublication`, `PublicationDue`, `routes/console.php`,
  `Humas\{HumasQueries,Publikasi,Jadwal}` (+ views), `Navigation`, `NavigationServiceProvider`, `Admin\AuditLog`
  (label "Pengingat publikasi dikirim ke Humas (otomatis)"), `DemoSeeder`.
- 5: migration, `app/Actions/Publications/{EventWhen,RecordAppreciations}.php`,
  `tests/Feature/Humas/{AppreciationTimeTest,RecordAppreciationsTest,ApresiasiPickerTest}.php`. Changed:
  `AppreciationEvent`, the five appreciation Actions, the four notifications, `Humas\Apresiasi` (+ views),
  `HumasQueries` (`searchAchievements()`), `Humas\{Jadwal,Publikasi}` (event order and labels), factory, `DemoSeeder`.
- 6: `tests/Feature/Kesiswaan/{VerifikasiHasilTolakTest,TalentaPindahkanDialogTest,LombaTutupDialogTest}.php`,
  `tests/Feature/Registrations/WithdrawInvitationDialogTest.php`, `tests/Feature/Accounts/ImportSiswaDialogTest.php`,
  `tests/Feature/Support/NoNativeConfirmTest.php`. Changed: `RejectReport`, `RequestReportRevision`,
  `Kesiswaan\{VerifikasiHasil,Talenta,Lomba\Index}`, `Siswa\Pendaftaran\Form`, `Akun\ImportSiswa` (+ views),
  `Guru\BinaanDetail`.
- 7: the eleven files under `resources/views/vendor`, `app/Support/FirstName.php`,
  `app/Notifications/Support/MailGreeting.php`, `tests/Feature/Notifications/{MailTemplateTest,MailGreetingTest}.php`,
  `tests/Unit/Support/FirstNameTest.php`. Changed: `config/mail.php`, `PlainMailMessage`, every notification with a
  `toMail()`, `OtpCodeMail` (+ both views), `IssueOtpCode`, `SendEmailBindingNotice`, `MailTest`, `Siswa\{Beranda,
  TurMenu}`, `lang/id.json`.
- Shared by several tracks: `Navigation`, `NavigationServiceProvider`, `Admin\AuditLog`, `DemoSeeder`, the Humas
  components, `tests/E2E/support/email-scenario.php` (steps 8b, 8c, new 8d, 10j, 11a, and by the review the expected
  guru rows of 7b, 7d, 7f and 8b; **not run**),
  `tests/Feature/DesignSystem/LongWordsTest.php` (the hand-built Humas card got `'overdue' => false`, coordinator).
- Areas (CLAUDE.md §11): the round changed the lead's backend files and S's screens by the lead's request, and two
  files of I (`docker/env.production.example`, the email scenario).

## Deviations worth knowing
- Bell: Livewire 4.4 never reports the end of a request it cancelled, so the script keeps "request → time sent" and
  forgets an entry after 30 s. A commit counts as an action only when it calls a real method (not `wire:poll`,
  `$refresh`, `$set`). A check times out after 15 s. The 429 hold survives a page change. `pollSeconds()` clamps
  1–29 to 30 on the server as well, and since the review anything above 3600 to 3600 (the script clamps again:
  `COUNTS_MIN_EVERY` / `COUNTS_MAX_EVERY`). Trade-off of the review fix: after a failed check (5xx, network error,
  timeout) the 60 s hold also skips the 2.5 s / 8 s checks after an action that falls inside that minute, so a
  notification caused in that minute shows at the next tick. The `.env.example` line is `# STH_COUNTS_POLL_SECONDS=60`
  with one space on purpose (`EnvExampleMailQa9Test` counts the lines with three).
- Links: `problem()` keeps the old gates in the old order and uses the new checks only to choose the sentence, so the
  accepted set is identical by construction. A non-text link is still refused by the Kesiswaan drawer (`string`
  rule) and still becomes null in `SubmitCompetition`. Every role holds `competition.create` (D-27), so the
  "forbidden" tests of the usulan sheet cover an account without a role, a disabled and an archived student.
- "Lainnya": the typed kind is also dropped when no type is chosen. The drawer view gets a flag (`otherType`) from the
  component instead of comparing with the enum in Blade.
- Change notice: `audience()` returns finished sentences per registration (the sentence for dates depends on the
  registration's status); `mail` is false when nobody is told; "peserta" ignores `max_team_size` while the lomba is
  individu; the toast counts come from a second read right after the save and can differ by one from the audit row.
  A false match of the address rule on "Tempat" only shortens the sentence to "Tempat diganti."; it never blocks a
  save. The hint wording was corrected in the review to the rule as built (not a new rule: the copy of the diagnosis
  promised notices for batas daftar / peserta to all registered students and the guru, which the rule never sent).
  Two "Ubah lomba" drawers on the same published lomba are still last-write-wins, and the notice then carries the
  value of the later save (predates EM2).
- Publication: `publish()` without `askPublish()` does nothing (no 404). The dialog also shows a box with the lomba,
  "Nama · Kelas · Capaian" and the channels. A `terjadwal` row without a time shows as overdue but is never reminded.
  The reminder is stamped even when no Humas account can log in. The reminder text is fixed when the reminder is
  sent: when the student's account is archived or reactivated between the reminder and the button press, the stored
  bell row keeps the earlier sentence, while the panel and the dialog are computed live.
- Appreciation: ticks are **not** pruned on render (a stale or forged tick stays until "Masukkan", which then writes
  nothing and says so); a single stale tick gets the general sentence, the Publikasi panel still names the event.
  `parseTime()` is strict ("7:00" and "24:00" are refused). `RecordAppreciations` checks verified + consent on the
  given models before the transaction, as the single Action did. The demo event keeps its name "Upacara Senin,
  5 Oktober 2026".
- Confirmations: the Talenta question stores which rows move and from which stage (QA9's "never move a row twice"
  holds). `askImport()` runs the import's own checks first, so the password page comes before the dialog;
  `confirmText()` is now a statement. `Form::askBatalkanUndangan()` repeats three refusal sentences of
  `WithdrawInvitation` and the "team is ready" rule of `ConfirmMembership`. `Talenta::reject()` and
  `Lomba\Index::publishPast()` still answer 404 without their ask (pinned by older tests).
- Mails: `MailGreeting::for()` decides "student" with `LoginIdentifier::isStudentAccount()` (role or student record;
  one extra query per staff mail). `FirstName` also skips a single letter without a full stop ("M Rizky"). The text
  part ends with "--" (no trailing space). The OTP mail got a hidden inbox preview line (the intro sentence, never
  the code). Facts found on the way: Laravel builds the text part from the same Markdown view with `strip_tags` only;
  a compiled view is shared by both parts, so `view:cache` decides its echo format and the Markdown "secured encoding"
  does nothing there (without `plain()` in the view a stock `MailMessage` produced a live link in that order).

## The lead's local database
- The mail test had changed three settings (steps 8-4, 8-5, 8-6). The coordinator put them back with a one-off
  script, notifications faked (none sent), everything audited: the weights of the version before the test were
  published as a **new** version (`PublishWeightVersion`, reason "Kembali ke bobot sebelum uji email 9 Okt 2026"); the
  one changed line of the letter template got its earlier text back (`UpdateLetterTemplate`, a new template version);
  `signature_file_id` points at the earlier image again (set directly, with an audit row `signature.replaced` that
  carries `restored: true`).
- Rows from before the round stay as they are: publications the old job already published, the demo appreciation
  event (no start time), a closed demo lomba with a bare "Lainnya", old bell rows with their old sentences.

## Checked in a browser (coordinator, 2026-10-10, the app's browser pane, demo Kesiswaan account)
- **Bell:** "Tandai dibaca" lowers the number at once; two `GET /notifikasi/jumlah` follow the action; the answer is
  JSON `{"badges":{…}}`; no console error.
- **Verifikasi hasil, "Tolak":** an empty note gives the field error and no dialog; with a note the dialog "Tolak
  laporan ini?" opens; it was closed without rejecting.
- **Kelola lomba:** the dialog of "Tutup"; the field "Jenis penyelenggara lainnya"; the info alert at the top of "Ubah
  lomba".
- **Apresiasi:** all three validation messages of "Buat acara" at once; the event card reads "Senin, 12 Oktober 2026,
  07.30 WIB"; the picker shows 10 of 71; the search "osn" answers "7 prestasi cocok." with a tick kept. The test event
  was deleted again; nobody was notified.
- **Mails:** two sample mails in Mailpit: the HTML card and a clean text part.

For the lead's own retest: the test guide (outside git) got a new "Cerita 12" with nine retest steps for this round.

## Not checked (the agents had no browser, no real mail and ran no scenario; what the coordinator saw is above)
- In a browser, still unseen: an overdue card on Humas › Publikasi and the count on Jadwal (the dialog of "Tandai dipublikasikan" was opened and closed by the coordinator as the demo Humas account on 2026-10-10: lomba, student, channels, notice line), the Talenta
  bulk dialog, the Import dialog, the student's "Batalkan undangan" dialog, every phone width, the bell's background
  tick and Back / Forward, a real mail in Gmail on a phone.
- Live counts in a browser, with the queue worker running (of these steps only the first half of (2) and the two
  checks after an action were seen): (1) change the password on Akun › Keamanan: two
  `GET /notifikasi/jumlah` about 2.5 s and 8 s after the toast (`Accept: application/json`, `X-Requested-With`), the
  bell shows 1 without a click; (2) "Tandai dibaca" lowers it at once, "Tandai semua dibaca" without a reload;
  (3) Notifikasi left open: a notice caused from a second profile appears within about a minute; (4) another role's
  sidebar count rises the same way; (5) no request from a hidden tab, one on return, none after six idle minutes;
  (6) Back → one request, the address typed by hand lands on `/notifikasi`; (7) Keluar: no request on `/login`, no CSP
  error; (8) `STH_COUNTS_POLL_SECONDS=0`: no address in the page, "Tandai dibaca" still works; (9) since the review:
  block `/notifikasi/jumlah` in DevTools › Network request blocking for two checks, then unblock: the request comes
  back within about two ticks without a page load, while two 401 answers (the session ended in another tab) must
  still stop it. The rules of the script (never next to a Livewire request, the cancels, the two refusals, the holds,
  the 3600 s cap) were exercised only in a scratch simulation of `app.js` (fake timers, fetch and Livewire hooks;
  the review's copy `bell-harness-fix.mjs` is the current one, neither is in the repo), not in a browser.
- Screens: the link hints; the toast of "Ubah lomba", its alert at 1366 / 390 px, and the two-click path of a stale
  "Periksa lomba" drawer (render-tested through Livewire only); the overdue Humas panel, the dialog `tandai-terbit` at
  360 / 390 px, the count on Jadwal; Apresiasi at 360 / 1280 / 1366 px (the time input, 31 ticks, a team with one
  click → one guru mail, and typing "nadia ", a pause of more than half a second, then "putri" into "Cari prestasi":
  the box must read "nadia putri" and the list must find the student; the fix was verified by Pest on the server side
  only); focus order and look of the confirm dialogs that were not opened above.
- Mails: each kind (with a button, without one, the OTP mail) in Mailpit's HTML and Text tab (two samples were seen,
  not every kind); one through the allowlist in Gmail on a phone (dark mode); Outlook. Rendered samples of five mails
  were written to the scratchpad by the mail track.
- The Playwright smoke. (The email scenario was run by the coordinator, see TEST-RESULTS.)

## Answered by the lead on 2026-10-10 (chat)
- D-126: "Oke jangan ke semua siswa". The notice goes only to the people attached to the lomba (final). The fact list, the
  status scope and the limit of 50 stay *sementara*.
- D-129: the new look and greeting, also "Yth. <nama lengkap>," for staff in the OTP mail: "Boleh kalo mau tambahi
  gitu". Accepted from the description; the lead has not seen a real mail yet.
- D-128: the five dialogs beside "Tolak" (Batalkan undangan, Pindahkan talenta, Tutup lomba, Impor siswa, Tandai
  dipublikasikan): "Boleh".
- D-125: "Jenis penyelenggara lainnya" required before publishing: "Iya".

## Requests for the lead
- **Bell (D-127):** the first check (2.5 s) often comes before the queue wrote the row, so the bell then changes at
  8 s: write the bell row at once, or check at about 3.5 s? A read-only session for the counts route (a late answer
  could write an older session back)? A message in a tab whose session ended? An action that ends in a full page
  redirect loses its two checks. On Import siswa the 2 s poll cancels checks while a job runs. The sidebar key
  `siswa.lomba-saya` is printed and never counted.
- **Lomba form (D-125, links):** 60 characters and "Lainnya (…)" also in the Excel column (a pivot splits "Lainnya")?
  Show the typed kind on the queue card and the catalog card? No blocklist of endings for production (`.test`
  accepted, `http://` refused)? One word for "Link resmi" (usulan) and "Website resmi" (drawer, catalog)? A name
  field for the capaian "Penghargaan khusus" (same kind of gap, not built).
- **Lomba change (D-126):** confirm the fact list, the status scope and the limit of 50. "ditambahkan" for a first
  link or juknis (today "diganti")? "Kesiswaan mengubah …" also when an Admin did? The hint counts accounts that
  cannot log in and leaves out `menunggu_laporan` (hint 4, toast 3 is possible). No merge window: three saves are
  three notices, and a one-letter fix of "Tempat" notifies. Should the hint also show, or count, when only
  `menunggu_laporan` registrations exist (a moved final date is told to them; left as built by the review)? An input
  rule against a web address in "Tempat" (today only the notice leaves the value out)? Not built, wanted?: a notice to TU once letters exist, a
  notice when a lomba is closed, a question before saving a past deadline, refusing "tim" while individu
  registrations run, "Baru" / "Diperbarui" tags in the catalog (the lead's "everyone" without a mail or a bell row).
- **Humas publication (D-122):** a daily nudge while a row stays overdue? Stamping the reminder when no Humas can
  log in? A consent that comes back after the time passed gets its late reminder: fine? "Jadwalkan" → "Jadwalkan
  pengingat"? The dialog line for an alumnus ("Akun siswa tidak aktif …" vs "Siswa ini sudah alumni …").
- **Appreciation (D-123, D-124):** "Ubah acara" (a wrong time or date needs delete + create)? One guru notice also
  for members added in separate clicks? Merge a student's own notices in one click? The caps 30 / 100? Should a single
  stale tick still name the event, and should the picker close when the event was confirmed meanwhile? A time for
  the event already in the local database, and the demo event renamed to "Upacara Senin"?
- **Confirm dialogs (D-128):** which of the swept dialogs stay? Silent no-op also for `Talenta::reject()` and
  `Lomba\Index::publishPast()`? "Siswa bisa …" (individu) vs "Ketua bisa mengirim ulang laporan." (tim)? A sentence
  for Kesiswaan when the ketua cannot log in (today the resend sentence is only left out)? The extra line of the
  Talenta dialog for rows a colleague moved first? "Impor berjalan di latar belakang" in the Import dialog above 50
  rows? (The warning toast for the ketua when the invitee answered first was built in the review.)
  **Two senders have no question at all: should Apresiasi "Masukkan" ask above a few ticks, and should "Simpan
  perubahan" ask on a published lomba with registered students?** Today neither asks. One "Masukkan" can queue up to
  30 student mails plus the guru mails, and each "Lepas dari acara" afterwards sends one more; one "Simpan perubahan"
  can send up to 50 mails (`CompetitionChange::MAIL_LIMIT`). The drawer's alert says beforehand who is told; the
  Apresiasi picker does not, only the toast afterwards does. If a question is wanted, the cheapest place is
  `Apresiasi::addAchievements()` with this round's pattern (an ask method stores the ticked ids in a `#[Locked]`
  property, the confirm method is a silent no-op without it).
- **Mails (D-129):** the look. Staff are greeted "Yth. <nama lengkap>," in the OTP mail and the binding notice too,
  which can reach a mistyped address (SECURITY §3b said "nama depan saja"): keep, or "Halo," for staff in those two?
  The hidden preview line in the OTP mail? `for()` everywhere instead of `student()`? Remove the unused "Whoops!"
  line? More specific button labels, an Auto-Submitted header.
- **Provider (D-121):** the production provider (candidates in the §D13 addendum). On Brevo meanwhile: retention
  (logs 1 month, "Never store previews"), a second sender for OTP and account mails (Brevo blocks an unsubscriber per
  sender; to test with a throwaway address), and one look at "Show original" of an OTP mail to see whether it carries
  the unsubscribe header too (expected, not seen).

## Requests for other owners
- @I: the email scenario was run by the coordinator on 2026-10-10 (TEST-RESULTS; steps 8b, 8c, 8d, 10j, 11a changed, its
  template check and leak scan passed on the new template). Its result depends on the developer's `.env`: with real OTP
  codes (`STH_OTP_TEST_MODE=false`) the two work mails of the unverified second Humas account are reported missing. Pin
  `STH_OTP_TEST_MODE=true` (and the mail settings, see EM1) on the `app` service of `tests/E2E/compose.e2e.yaml`. The review added the four guru pembimbing rows the expected lists had been
  missing since D-110, all to the demo guru of that registration: 7b "Laporan binaan perlu revisi: …", 7d "Laporan
  binaan diverifikasi: …", 7f "Laporan binaan ditolak: …", 8b "Binaan masuk daftar apresiasi". They were written by
  reading the notification classes and confirmed by that run. Still to add: a step that edits a published lomba with a running registration (D-126). Not written
  blind in this round; when it is: place it after 4c and before 6a, change the place only (a new batas daftar is
  told only to `menunggu_konfirmasi`), expect one "Info lomba berubah: <lomba>" per member of every registration on
  that lomba (two registrations use it in the scenario) and one "Info lomba binaan berubah: <lomba>" to their guru,
  and confirm the recipients with a real run. The mails of `ReleaseAppreciation` / `DeleteAppreciationEvent` still
  have no step.
  Playwright: a bell test must mock `**/notifikasi/jumlah` (no queue worker in the E2E stack); a step that waits for
  network idle after a Livewire action sees two more requests. `docs/DEPLOY.md`: §7 env list
  (`STH_COUNTS_POLL_SECONDS`, each check is one php-fpm request; above 3600 is read as 3600; to switch the checks off
  set 0, never a very large number) and one sentence for the deploy procedure (open pages keep their bell and sidebar
  counts through `artisan down`: they wait for the 503's `Retry-After` and ask again, no reload needed), §9
  (`sth:mail-test` now ends with the common footer; no Brevo path, provider open), §18 (a release that changes a mail
  view or `config/mail.php` needs the queue worker restarted). Review the block the bell track added to
  `docker/env.production.example` (its comment now also says that values above 3600 are read as 3600 and that 0,
  never a very large number, switches the checks off).
- Backend owner: one source for the refusal sentences and the "team is ready" rule that `Siswa\Pendaftaran\Form` now
  repeats (e.g. a static helper on `WithdrawInvitation` / `ConfirmMembership`); the audience rules of
  `CompetitionChange` (D-126); `RecordAppreciations` (locks, checks before the transaction); the counts route and the
  session (D-127); `app/Actions/Reports` was touched by the confirmations track (`reason()`, `audience()`, and in
  the review `leaderCanResend()`); `Form::undang()` still puts its refusal ("Anggota tidak bisa diundang lagi untuk
  pendaftaran ini.") on `members`, which the screen no longer draws once the team went to the Kepala Sekolah (same
  root cause as the withdrawal refusal the review fixed; it needs its own sentence).
- @S: the Humas, Kesiswaan, Siswa and Akun screens changed by tracks 2–6 and the mail views (D-129) need the rest of
  the browser pass above; `resources/views/dev/components.blade.php` could show `humas.jadwal` in the Humas preview
  badges. Backlog (predates this round, not changed in it): `Humas\Arsip::updatedQ`, `TataUsaha\ArsipSurat::updatedQ`
  and `Kesiswaan\Lomba\Index::updatedQ` write a trimmed value back into a `wire:model.live` search box, the habit that
  ate the space in "Cari prestasi". Same fix there: trim where the query is built, not in the `updated…` hook. A rule
  worth one line in CLAUDE.md §6 if the lead wants it: such a hook never rewrites its property with a normalised
  value.
- Coordinator, on the dev stack: done on 2026-10-10 (`migrate`, `npm run build`, `reload.bat`, restart of `queue` +
  `scheduler`).

## Review (after the build, before the final run)
One independent reviewer per track and one for the seams between the tracks read the code and the tests (nothing was
run in that stage). Every finding was then challenged by a skeptic who tried to refute it in the code; only a
confirmed finding, with the skeptic's fix, went to a fixer, who wrote the failing test first. Fixers could not touch
the docs, which were brought in line afterwards. No blocker, no major: **13 confirmed, all minor** (12 fixed in the
code, 1 in the docs), **1 rejected**, and nits that were left.

Confirmed and settled:
- **bell-1**: two passing failures (a 503 during `artisan down`, a network drop, a timeout) switched the live counts
  off for the rest of the page's life. Now only two refused answers (401 / 403) end them; a 429, a 5xx
  (`Retry-After` honoured), a network error or the 15 s timeout hold for at least 60 s and the chain goes on.
- **bell-2**: `STH_COUNTS_POLL_SECONDS` had no upper bound, and a value above about 2,147,483 s overflowed the browser
  timer delay and could make every tab ask every 2 s. Capped at 3600 on the server (`MAX_SECONDS`) and in the script.
- **lomba-change-notice-1**: "Tempat" was printed as typed, so a web address in it went into the mail and the bell.
  `CompetitionChange::place()` now says only "Tempat diganti." then; input validation is untouched.
- **lomba-change-notice-2**: the drawer hint promised notices for batas daftar / peserta to every registered student
  and the guru, which the rule never sent. The copy now has two sentences that match `statusesFor()`. Left as built:
  no hint when only `menunggu_laporan` registrations exist (no false sentence is shown; a question for the lead).
- **publication-1**: the reminder, the overdue alert and the hint under the date promised a student notice an alumnus
  never gets. All three now depend on `PublishPublication::notifiesStudent()`.
- **appreciation-1**: "Cari prestasi" could lose a typed space or letters, because the hook wrote the trimmed value
  back into the box. `updatedSearch()` now leaves the text as typed.
- **confirmations-1**: the student's dialog "Batalkan undangan?" said that a classmate's account was not active. It
  now says the neutral "Undangan ini belum dijawab."
- **confirmations-2**: a withdrawal refused because the team had gone to the Kepala Sekolah showed no message. Both
  steps now also raise a warning toast (`Form::refuseWithdrawal()`).
- **confirmations-3**: the dialog's "Kamu bisa mengundangnya lagi" was not checked again at the confirm step. The
  result toast is now read from the status the Action wrote and adds "Pendaftaran diteruskan ke Kepala Sekolah."
- **confirmations-4**: the revision banner said "Ketua bisa mengirim ulang laporan." when the ketua could not log in.
  `RequestReportRevision::leaderCanResend()` decides it now; the sentence is left out then.
- **seam-1**: the email scenario expected no guru pembimbing mail in steps 7b, 7d, 7f and 8b, so its next run would
  have ended "FAILED". The four rows were added by reading the code and confirmed by the coordinator's run of 2026-10-10 (TEST-RESULTS); no
  D-126 step was written blind.
- **seam-2** (docs only, nothing built): the general sentence of D-128, "every action that cannot be undone or that
  notifies people asks in the app's own dialog", was not true for two senders built in this round. It now reads: a
  question before an action is always the app's own dialog, never a native confirm; the swept actions got one; the
  senders left without a question are listed in D-128, among them Apresiasi "Masukkan" and "Simpan perubahan" on a
  published lomba (a question for the lead). Reworded in DECISIONS, CLAUDE.md, ARCHITECTURE, UI-MAP, SECURITY,
  DESIGN-SPEC-v3 and PANDUAN-PENGGUNA.
- **seam-3**: a "Periksa lomba" drawer left open on a lomba a colleague published could notify students without the
  hint. The first "Simpan" now only warns and turns the drawer into "Ubah lomba"; the second click saves and
  notifies (three tests in `LombaQa9Test`, names ending in "(EM2)").

Rejected:
- **mail-templates-1** (the OTP mail and the binding notice print a staff account's full name at an address that is
  not proven yet): true as described, but a recorded, deliberate choice that is already a question for the lead
  (SECURITY §3b, D-129). The binding notice goes out only after a verified code, so only the OTP mail can reach a
  mistyped address.

Nits, left as they are unless noted (CLAUDE.md backlog #81):
- **bell-3**: a check can still run beside the next page's request after a Livewire full-page redirect.
- **bell-4**: the ±10 s jitter also applies at the 30 s floor, so a setting of 30 asks every 20 to 40 s.
- **lomba-links-organizer-1**: the typed "Lainnya" kind is dropped only when the input carries `organizer_type` (only
  a hand-made request of Kesiswaan or Admin can keep it on another type).
- **lomba-links-organizer-2**: `ExternalUrl::normalize()` also removes ZWNJ / ZWJ inside an IDN host, and `TOO_LONG`
  counts bytes while its sentence says characters.
- **lomba-links-organizer-3**: the `LinkFieldsTest` case named for both link fields passes with the hint on one.
- **lomba-links-organizer-4**: D-76 quoted the old `NoWebAddress` message. Closed in the docs pass: a dated pointer
  under D-76.
- **lomba-change-notice-3**: a first link or a first juknis is announced as "diganti".
- **publication-2**: `publishingId` survives "Batal", X, Esc and `schedule()`, so a hand-made `publish` call could
  still confirm a dismissed question.
- **publication-3**: `publication.reminded` is audited also when no Humas account was told.
- **publication-4**: PANDUAN-PENGGUNA §7 still described auto-publish when the reviewer read it. Closed by the docs
  pass ("Jadwal hanya pengingat").
- **appreciation-2**: a stale tick the screen no longer shows can stay in `$picked` and cause a second, confusing
  error.
- **appreciation-3**: `AppreciationEvent::startsAt()` and `BinaanAppreciationNotice::scheduled()` have only tests as
  callers.
- **confirmations-5**: `Talenta::reject()` and `Lomba\Index::publishPast()` still answer 404 without their ask.
- **mail-templates-2**: `MailGreetingTest` expects "Halo Bambang," for the Kepala Sekolah on student-audience
  notifications (latent: no sender reaches the wrong audience today).
- **mail-templates-3**: "Whoops!" in `lang/id.json` is a dead line.
- **seam-4**: the live Jadwal count can run ahead of the open Jadwal or Publikasi page.
- **seam-5**: `RecordAppreciation::blockedMessage()` prints the event date without its time.

## Commands verified (by the implementers, per track; runs overlapped while other tracks were editing)
- Every track saw its new tests red first (missing class, method or column), then green. Final runs per track:
  1 `tests/Feature/Notifikasi` + `NavigationBadgesTest` 66 passed; 2 `ExternalUrlTest` 97 passed, link and usulan
  tests 205 passed, Kesiswaan / Guru / Exports 231 passed; 3 the two change-notice files 82 passed, all of
  `tests/Feature/Kesiswaan` 502 passed; 4 `tests/Feature/Humas` and neighbours 432 passed; 5 `tests/Feature/Humas` +
  `SearchBoxTest` + `CspSafeTest` 417 passed; 6 the six new files and the adapted ones green by folder (Kesiswaan 420,
  Registrations 48, Accounts 69, Security 193, Siswa 239, Admin 226); 7 mail / notification filter 479 passed.
- `pint --test` clean on every track's files; `npm run build` OK after the last `app.js` edit (bell track);
  `schedule:list` shows `RemindDuePublications` every 5 minutes.
- Full runs during the round were not conclusive: paratest printed no summary when a failure's stack pointed into a
  compiled view of a worker that had already removed its folder (names can be read with `--tmp-dir` and the
  per-worker JUnit files). The one real failure they all showed, `LongWordsTest` (the Humas card without `overdue`),
  has its one-line fix in the test.
- Review fixes (by the fixers, each new test seen red first, then green; narrow filters only, no full run): bell
  `LiveCountsTest` + `NotifikasiTest` + `NavigationBadgesTest` 71 passed (66 before, plus 5 new cases), and the
  scratch simulation of `app.js` green five runs out of five; lomba change notice, the two files 84 passed (734
  assertions; 82 before); publication `PublicationReminderTest` + `PublikasiScreenTest` + `HumasQa9Test` +
  `HumasQa10Test` + `MailGreetingTest` 114 passed (896 assertions); appreciation `ApresiasiPickerTest` 28 passed, all
  Apresiasi tests + `HumasQa9Test` 61 passed; confirmations `WithdrawInvitationDialog` + `InviteMembers` +
  `VerifikasiHasilTolak` + `ReportVerification` 73 passed (477 assertions); seams `LombaQa9Test` +
  `LombaChangeNoticeScreenTest` + `LombaFormQa10Test` 54 passed (374 assertions), and `php -l` on the email scenario.
  `pint` clean on every touched file; `npm run build` OK after the `app.js` fix.

## TEST-RESULTS
First full run after the implementation and before the review fixes: 3359 passed, 1 skipped (20800 assertions), exit 0, 771 s; pint --test PASS (750 files).
Final run after the review fixes (2026-10-10): `php artisan test --parallel --processes=4` → **3378 passed, 1 skipped (20966 assertions)**, exit 0, 801 s; `pint --test` → PASS (750 files); `npm run build` OK.
Email scenario (`tests/E2E/support/email-scenario.php`, isolated project `sth-e2e`, `STH_DEMO_FILES=placeholder`): with `STH_OTP_TEST_MODE=true` passed to the two commands → **123 mails, 0 missing, 0 unexpected, 0 leaks, 0 failed jobs, RESULT: PASSED** (the leak scan found its control phrase in 123 of 123 mails, so the new template is what was scanned). A first run with the lead's own `.env` (real OTP codes, `STH_OTP_TEST_MODE=false`) gave 121 mails and 2 missing, both for the scenario's unverified second Humas account (steps 7d and 8c): the scenario expects the local test mode, in which an unverified address counts as verified (`User::routeNotificationForMail()`, D-68); it is the production rule that step 11a shows, not a fault of this round. The throwaway stack was removed afterwards (`down -v`).
Before the commit (same day, on the tree that was committed): the full suite again → 3378 passed, 1 skipped (20966 assertions), exit 0, 798 s; `pint --test` PASS (750 files); `composer validate --strict` and `composer audit --locked` clean; `npm run build` OK, `npm audit --omit=dev` 0 vulnerabilities; CI's boot-guard step repeated with `.env.example` as the env file (boots with the OTP test mode off, refuses with it on).
Not run: the Playwright smoke (its image is not on the lead's laptop; CI runs it on the PR).
