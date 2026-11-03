# UI3-B — copy pass: staff are not addressed as "kamu", one name per thing  ·  status: done

Part of UI3 (`UI3.md`, D-119). Wording only, plus one prop for the guru's upload sheet.

## Rule
Students stay "kamu". Staff screens, notifications and mails: a sentence without a pronoun, "Anda" only where it
breaks without one (none was needed). Screens shared by students and staff: a neutral sentence where it reads well.

## Changed strings (old → new)
**Guru** — `Guru\Binaan`: "Siswa yang kamu bimbing di lomba…" → "Siswa bimbingan di lomba…" · `Guru\BinaanDetail`:
"…siswa binaanmu." → "…siswa binaan." · `Guru\Lomba` + `guru/lomba`: "info lomba yang kamu upload" → "info lomba
yang di-upload" (subtitle, empty state, table caption), "Siswa binaanmu di lomba ini" → "Siswa binaan di lomba ini"
· `guru/binaan`: "siswa yang kamu bina" → "siswa yang dibina", "Siswa memilihmu sebagai guru pembimbing…" → "Siswa
memilih guru pembimbing…" · `BinaanReportDecided`: "Siswa binaanmu" → "Siswa binaan".

**Kepala Sekolah** — `Kepsek\Laporan` + view: "diberi tanda nama kamu" → "diberi tanda nama akun ini" (the route
is `kepala_sekolah|admin` and the mark is the downloader's name) · `Kepsek\SuratSaya`: "…tanda tangan Anda." →
"…tanda tangan Kepala Sekolah." · `kepsek/surat-saya`: "Surat atas nama Anda" → "Surat atas nama saya" (the menu
name; tile and caption), "Belum ada surat atas nama Anda" → "Belum ada surat" · `SignatureReplaced`: "Jika bukan
atas persetujuan Anda…" → "Jika tanpa persetujuan Kepala Sekolah…".

**Kesiswaan** — lomba form: "…termasuk yang kamu buat." → "…termasuk buatan sendiri." · `Lomba\Index`: "Upload
kamu" → "Upload sendiri" · Talenta (view + class): "Terkirim saat kamu menekan Lolos atau Tidak lolos." →
"Terkirim saat Lolos atau Tidak lolos ditekan.", "…ke tahap yang kamu pilih." → "…ke tahap yang dipilih." ·
Verifikasi hasil: "Kamu pembimbing lomba ini. Laporan binaanmu diverifikasi…" → "Laporan binaan sendiri
diverifikasi petugas Kesiswaan lain.", "Siswa menerima catatan kamu…" → "Siswa menerima catatan revisi…".

**Admin** — Pengguna: "Akun kamu" → "Akun sendiri" · `StaffAccountChanged`: "Jika bukan atas permintaan Anda…" →
"Jika reset ini tidak diminta…" · Pengaturan › Halaman publik: "(U-04)" removed.

**Shared screens** — login: "Sudah aktivasi? Kamu juga bisa masuk pakai email." → "…Bisa juga masuk pakai email.",
"Kamu akan diminta menghubungkan email dulu. Setelah itu kamu boleh ganti kata sandi." → "Nanti diminta
menghubungkan email dulu. Setelah itu boleh ganti kata sandi." · confirm-password: "kata sandi kamu" → "kata sandi"
· 2FA: "di HP kamu" → "di HP" · aktivasi: "Akun kamu sudah aktif" → "Akun sudah aktif", "Bukan akun kamu?" →
"Salah akun?" · lupa kata sandi: "akunmu" → "akun" · Keamanan akun: "Untuk memastikan ini kamu." → "…ini pemilik
akun.", "perangkat yang tidak kamu kenal" → "…tidak dikenal" · Bukan saya: "Bukan kamu yang mengubah email?" →
"Tidak merasa mengubah email?" · Notifikasi: "Kabar terbaru untukmu." → "Kabar terbaru untuk akun ini." · 403:
"Kamu tidak punya akses ke halaman ini." → "Tidak ada akses ke halaman ini." · usulan form: "Kamu akan dapat
notifikasi…" → "Notifikasi dikirim…" · `SubmitCompetition`: "Kamu sudah mengirim 5 usulan hari ini." → "Sudah 5
kali mengirim hari ini." · `EnsureAccountActive`: "Sesi kamu sudah berakhir." → "Sesi sudah berakhir." ·
`ChangeEmail`: "Ini sudah email akunmu." → "Email ini sudah dipakai akun ini." · `lang/id.json` (8 lines) and
`lang/id/passwords.php` (2 lines): "kamu" dropped · QR page: "surat yang kamu pegang" → "surat yang dipegang".

**Shared mails** — `CompetitionPublished`: "lomba yang kamu kirim" → "lomba yang dikirim" · `EmailBindingNotice`,
`SecurityNotice`, `PasswordWasReset`, `OtpCodeMail` + both OTP templates: "Jika bukan kamu…" → "Jika tidak merasa
…", and three subjects: "Email akun SMAGA Talent Hub kamu diganti" → "…Talent Hub diganti", "Email kamu terhubung
ke SMAGA Talent Hub" → "Email ini terhubung…", "Kata sandi kamu sudah diganti" → "Kata sandi sudah diganti".

**One name per thing** — `Navigation::tabbar()`: "Lombaku" → "Lomba saya", "Profil" → "Portofolio" (keys
unchanged; icon `award`) · `Siswa\TurMenu::mobileTips()` follows · Guru › Lomba: the sheet opened by "Upload info
lomba" is titled "Upload info lomba" ("Info lomba terkirim", "Upload lagi", "Kirim info lomba"); the student's
sheet keeps "Usulkan lomba".

## Contracts other areas can use
- `App\Livewire\Lomba\UsulanForm`: `#[Locked] public bool $upload = false` (guru wording);
  `<livewire:lomba.usulan-form :upload="true" />`. `ulang()` keeps it (`resetExcept('upload')`).

## Commands verified
- Pint on the 38 changed PHP files → pass. Affected test folders (Guru, Kesiswaan, Auth, Akun, Errors, Kepsek,
  Notifikasi, Support, Admin, `LangIdTest`, `CspSafeTest`, `MailInjectionTest`) → all passed. New tests: the guru
  sheet is named like its button; the tab bar and the mobile tour replicas carry the menu names; the student's
  sheet keeps "Usulkan lomba".
- `tests/E2E/support/email-scenario.php`: the three subjects replaced by hand, the scenario itself not run.

## Left on purpose
- Student-only branches of `auth/activation` and every student notification / Action keep "kamu".
- "Prestasimu, tercatat rapi." on the login panel (a decorative, `aria-hidden` panel, students are the audience).
- `App\Domain\Files\UploadLimits` "berkasmu" (the per-student quota).
- Comment examples in component docblocks and `resources/views/dev/**`.

## Follow-ups (not done)
- Menu name vs page title still differ in places: Kepsek "Laporan" / "Laporan prestasi", "Persetujuan" /
  "Persetujuan lomba"; TU "Antrean surat" / "Surat tugas"; Humas "Antrean publikasi" / "Publikasi prestasi";
  tab "Lomba" vs menu "Cari lomba".
- `CompetitionSubmitted` tells verifiers "Usulan lomba baru … mengusulkan" for a guru's upload too.
- Formal words left in staff mails: "Mohon tinjau…", "Mohon diverifikasi.", "Silakan masuk lagi."
- `docs/PANDUAN-PENGGUNA.md` was only corrected where it quoted a changed sentence.
