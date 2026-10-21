# SMAGA Talent Hub — DESIGN SPEC v2 (redesign, Behance-inspired)

This REPLACES DESIGN-SPEC.md (v1). Every agent reads this whole file, then `artifact-type/reference/format.md` (path below), then writes ONLY its assigned files.

Paths
- Canvas folder: `C:\Users\darrr\AppData\Local\Temp\claude\D--real-project-SMAGA-Prestasi-SMAGA\6f3ee2bf-a318-4f79-b359-d74bdbf5b2b4\scratchpad\sth-canvas\project\`
- Format reference: `C:\Users\darrr\AppData\Local\Temp\claude\D--real-project-SMAGA-Prestasi-SMAGA\6f3ee2bf-a318-4f79-b359-d74bdbf5b2b4\scratchpad\artifact-files\36bc7eea-2a27-4c0d-b034-c6244e0ad6ce\artifact-type\reference\format.md`
- Shared notes folder (agent-to-agent coordination): `C:\Users\darrr\AppData\Local\Temp\claude\D--real-project-SMAGA-Prestasi-SMAGA\6f3ee2bf-a318-4f79-b359-d74bdbf5b2b4\scratchpad\sth-canvas\notes\`

Coordination protocol (agents are connected through this):
1. Before writing, `Glob` the notes folder and read any notes other agents already left.
2. When you finish, write `notes/<your-agent-letter>.md` (short): your files, their root sizes, interactive parts, every link target you used, any new CSS class you appended, any fictional data you invented (names, numbers, dates) so others can stay consistent.
3. Then read all other notes again; if another agent's note conflicts with your screen (a link target, a name, a number, a date), fix YOUR file to match the spec first, then the other note. Only if something is truly blocking, use SendMessage to "main".
4. Existing v1 files in `project/` are being replaced. Overwrite your assigned files completely (Write tool). Never edit files assigned to other agents, never touch `canvas.json`. Do not publish, render, screenshot, open a browser, or read your files back "to verify".
5. A hook ("Fact-Forcing Gate") may block a first Write/Edit asking for facts. Answer in your reply text: caller = `project/canvas.json` boards entry for that file; no other file serves the same purpose; static markup with synthetic data; user's instruction = "Rombak lah design web ini dengan menggunakan https://www.behance.net/ sebagai referensi design untuk desain web ini." Then retry the same Write.

## 1. What changed in v2 (read carefully — this is the user's corrected product flow)

1. **The SCHOOL inputs competitions first.** Kesiswaan (and Admin) create each lomba in a catalog: nama, bidang, penyelenggara, jenis penyelenggara, kategori bobot A–E, status ajang, tingkat (already verified by Kesiswaan), pelaksanaan, tanggal lomba, batas pendaftaran, tempat, individu/tim (+ max anggota), guru pembimbing default, juknis/brosur PDF, website resmi, poster/cover image (optional).
2. **Students browse the catalog** ("Cari lomba"), open a lomba detail, and **register** ("Daftar lomba"): individu or tim. For a team, the ketua picks members FROM EXISTING STUDENT ACCOUNTS (search by nama/NIS/NISN; since 2026-10-12, D-137: nama or NISN); names come from each member's own account; each member gets a notification and confirms ("Konfirmasi ikut"). Student chooses guru pembimbing (default from the lomba).
3. Students can **propose** a lomba that is not in the catalog ("Usulkan lomba": nama, penyelenggara, tautan/brosur). Kesiswaan reviews proposals and turns them into catalog entries.
4. **Kepala Sekolah approves** each registration (individu or whole team). Only after approval can TU make the surat tugas.
5. **TU surat tugas**: TU first ENTERS the letter numbers in a form — one number for the student letter (format from the school's Excel template: `400.3.8/<no>/MRD/<bulan romawi>/<tahun>`) and one for the guru pendamping letter (format from the Word template: `400.3.8.6/<no>/<tahun>`) — then clicks Generate. The signature+stamp is ONE image file (uploaded once by Admin in Pengaturan) pasted onto every letter. Output is **PDF** (printable). A team gets ONE shared student letter listing all members (same letter for every member); the pembimbing gets the Surat Perintah Tugas.
6. **Laporan hasil** deadline: **45 days after the final date** (*45 hari setelah tanggal final*, D-25; Admin can change it). Student reports result + uploads sertifikat/dokumentasi; Kesiswaan verifies → points.
7. **Points**: team members EACH get full points. Weights (Admin-editable):
   - Tingkat: Internasional 100 · Nasional 80 · Provinsi 60 · Kota/Kabupaten 40 · Sekolah 20
   - Capaian: Juara I / Medali emas 1,00 · Juara II / Medali perak 0,85 · Juara III / Medali perunggu 0,70 · Finalis 0,50 · Semifinalis 0,30 · Penghargaan khusus 0,30 · Peserta 0,10
   - Penyelenggara: A 1,00 · B 0,80 · C 0,60 · D 0,40 · E 0,20
   - Poin = Tingkat × Capaian × Penyelenggara
8. **Ranking is CUMULATIVE** (all years). Accounts are auto-archived **18 months (1,5 tahun) after the student starts kelas XII**; archived accounts are read-only and receive no new input, but their achievements stay in history/public pages.
9. **Student import (Admin)**: Excel columns: Nama lengkap, NIS, NISN, Tanggal lahir, Kelas saat pendaftaran, No HP (opsional). Admin picks the tahun ajaran of the data; system computes angkatan, tahun masuk kelas XII, and tanggal arsip otomatis (e.g. kelas X in 2026/2027 → kelas XII from Juli 2028 → arsip Januari 2030).
10. **Login**: NIS **or** NISN + password. Initial password = school's pattern (never shown in the UI). No activation code. First login: change password → connect email → 6-digit code (in this prototype any 6 digits are accepted; real email is configured later by the deploy team). If an account is hijacked, Admin resets it.
11. **Roles** (7 surfaces): Siswa, Guru Pembimbing, Kesiswaan, Kepala Sekolah, Tata Usaha, Humas, Admin. Kepala Sekolah and Admin are SEPARATE; Admin controls everything including replacing the Kepala Sekolah account.
12. **Public pages** (no login): prestasi overview, chart per bidang with drill-down (bidang → list of students + capaian → one student's achievements with lomba, peringkat, sertifikat), cumulative leaderboard. Show only nama + kelas + achievements. Never NIS/NISN/phone/birth date. Student photos only when a consent flag is on (show initials avatar otherwise).
13. Bidang stays 4: Sains · Riset · Seni Budaya · Olahraga.

## 2. Visual direction v2 — "Behance edu-soft"

References studied on Behance: CORSTA (education LMS dashboard), Scholarship & University Discovery Platform, CrioEd Education Admin Dashboard. What we take:
- **Visual catalog cards** like course cards: a colored COVER area (per bidang) with a big line icon, chips on the cover, then title / penyelenggara / meta / avatar stack of registered SMAGA students.
- **Filled pill active nav** (solid royal blue, white text), grouped sidebar sections with small uppercase labels, search in the top bar.
- **Hero/banner cards** with a warm greeting and one clear action (CORSTA "welcome back" + assistant banner).
- **Detail page like Scholarship Discovery**: wide banner, key-facts strip (Batas daftar · Tanggal lomba · Tingkat · Lokasi · Peserta), section tabs, sticky action panel on the right.
- **Admin screens like CrioEd**: stat tiles with a colored icon tile, clean tables with avatar + name, status chips, row action icon buttons, primary "Tambah" top-right.
- **Soft pastel atmosphere**: very light lavender/sky wash at the top of pages (class `.page`), white cards with gentle shadow, 20px radius.
No emoji. No dark theme. No heavy gradients on text areas (gradients only on cover placeholders and the subtle page wash). No real photos: covers and people are placeholders (bidang cover + initials avatars).

### Fonts
Urbanist (display: page titles, big numbers, card titles) + Plus Jakarta Sans (body, UI). Title 30–34px/800 Urbanist; section 20px/700 Urbanist; card title 17–18px/700 Urbanist; body 15px/500 (mobile 16px); small 13px; min 12px.

### Colors (all AA; one color = one meaning)
- Page `#F4F5FB` (+ `.page` wash), surface `#FFFFFF`, border `#E7E9F3`, input border `#DCDFEC`.
- Ink `#151A33`, secondary `#4A5068`, muted `#666C84`, placeholder `#8A90A6`.
- **Primary (actions, links, current)**: `#3148D6`, hover `#2638B0`, tint `#ECEFFF`, text-on-tint `#2A3CB8`, focus ring `#A9B6F5`.
- **Bidang identity** (chips `.b-*`, covers `.cover-*`): Sains sky `#1D56AD` on `#E6F0FF`; Riset violet `#5534B8` on `#F0EBFF`; Seni Budaya pink `#A61F54` on `#FDE8F0`; Olahraga teal `#0B6577` on `#E0F4F8`. Chart series per bidang use `#3D7BE0` (Sains), `#7B5CE0` (Riset), `#D6457E` (Seni Budaya), `#1690A6` (Olahraga).
- **Achievement gold** (points, medals only): `#FFF3D1` / `#6B4E00`, decorative medal `#F2B63A`.
- **Status**: done/approved green `#E4F5EA`/`#1B6B45`; needs action/deadline orange `#FFEEDD`/`#9A3F00`; rejected/error red `#FDECEA`/`#B42318`; waiting/neutral gray `#EEF0F6`/`#4A5068`; in progress/info blue `#ECEFFF`/`#2A3CB8`. Every status chip has a text label (and icon where space allows).

## 3. THE HELMET (copy verbatim into every artboard; you may append screen-specific rules at the END of the <style> only)

```html
<helmet>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Urbanist:wght@600;700;800&display=swap" rel="stylesheet">
<style>
body{margin:0;background:#F4F5FB;font-family:"Plus Jakarta Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#151A33;-webkit-font-smoothing:antialiased}
*{box-sizing:border-box}
a{color:#3148D6}a:hover{color:#2638B0}
h1,h2,h3,.display{font-family:"Urbanist","Plus Jakarta Sans",system-ui,sans-serif;letter-spacing:-0.01em}
.page{background:radial-gradient(900px 320px at 88% -8%,#ECE8FF 0%,rgba(236,232,255,0) 70%),radial-gradient(760px 300px at 28% -14%,#E2F0FF 0%,rgba(226,240,255,0) 70%),#F4F5FB}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;padding:0 18px;border-radius:12px;font:600 15px/1 "Plus Jakarta Sans",system-ui,sans-serif;border:1px solid transparent;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn-primary{background:#3148D6;color:#FFFFFF;box-shadow:0 8px 18px -10px rgba(49,72,214,.65)}
.btn-primary:hover{background:#2638B0;color:#FFFFFF}
.btn-quiet{background:#FFFFFF;color:#151A33;border-color:#DCDFEC}
.btn-quiet:hover{background:#F4F5FB;color:#151A33}
.btn-soft{background:#ECEFFF;color:#2A3CB8}
.btn-soft:hover{background:#DFE4FF;color:#2A3CB8}
.btn-danger{background:#FFFFFF;color:#B42318;border-color:#F3C4BE}
.btn-danger:hover{background:#FDECEA;color:#B42318}
.btn-sm{height:36px;padding:0 14px;font-size:14px;border-radius:10px}
.btn:focus-visible,.nav:focus-visible,.input:focus-visible,.icon-btn:focus-visible,.tab:focus-visible,.pick:focus-visible{outline:3px solid #A9B6F5;outline-offset:2px}
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:12px;border:1px solid #E7E9F3;background:#FFFFFF;color:#4A5068;cursor:pointer;position:relative;text-decoration:none}
.icon-btn:hover{background:#F4F5FB;color:#151A33}
.dot{position:absolute;top:8px;right:9px;width:8px;height:8px;border-radius:999px;background:#3148D6;border:2px solid #FFFFFF}
.card{background:#FFFFFF;border:1px solid #E7E9F3;border-radius:20px;box-shadow:0 1px 2px rgba(20,24,60,.04),0 10px 28px -18px rgba(20,24,60,.18)}
.chip{display:inline-flex;align-items:center;gap:6px;height:26px;padding:0 10px;border-radius:999px;font-size:12.5px;font-weight:600;white-space:nowrap}
.c-blue{background:#ECEFFF;color:#2A3CB8}
.c-green{background:#E4F5EA;color:#1B6B45}
.c-orange{background:#FFEEDD;color:#9A3F00}
.c-red{background:#FDECEA;color:#B42318}
.c-gray{background:#EEF0F6;color:#4A5068}
.c-gold{background:#FFF3D1;color:#6B4E00}
.c-glass{background:rgba(255,255,255,.82);color:#151A33}
.b-sains{background:#E6F0FF;color:#1D56AD}
.b-riset{background:#F0EBFF;color:#5534B8}
.b-seni{background:#FDE8F0;color:#A61F54}
.b-olahraga{background:#E0F4F8;color:#0B6577}
.cover-sains{background:linear-gradient(135deg,#DCE9FF,#C3D8FF);color:#1D56AD}
.cover-riset{background:linear-gradient(135deg,#EEE7FF,#D9CCFF);color:#5534B8}
.cover-seni{background:linear-gradient(135deg,#FFE6EF,#FFCFE0);color:#A61F54}
.cover-olahraga{background:linear-gradient(135deg,#DDF4F8,#BFE8F0);color:#0B6577}
.label{font-size:13px;font-weight:600;color:#4A5068}
.input{height:46px;width:100%;border:1px solid #DCDFEC;border-radius:12px;padding:0 14px;font:500 15px "Plus Jakarta Sans",system-ui,sans-serif;color:#151A33;background:#FFFFFF}
.input::placeholder{color:#8A90A6}
textarea.input{height:auto;padding:12px 14px;line-height:1.5;resize:vertical}
.nav{display:flex;align-items:center;gap:12px;height:44px;padding:0 14px;border-radius:12px;color:#4A5068;text-decoration:none;font-size:14.5px;font-weight:600}
.nav:hover{background:#F1F2FA;color:#151A33}
.nav-on{background:#3148D6;color:#FFFFFF;box-shadow:0 8px 18px -10px rgba(49,72,214,.7)}
.nav-on:hover{background:#2638B0;color:#FFFFFF}
.nav-sec{font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8A90A6;padding:8px 14px 2px}
.badge{margin-left:auto;min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:#ECEFFF;color:#2A3CB8;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}
.nav-on .badge{background:#FFFFFF;color:#2A3CB8}
.avatar{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#ECEFFF;color:#2A3CB8;font-weight:700;flex-shrink:0}
.stack{display:flex}.stack .avatar{border:2px solid #FFFFFF;margin-left:-8px}.stack .avatar:first-child{margin-left:0}
.pick{cursor:pointer;border:1px solid #DCDFEC;background:#FFFFFF;border-radius:14px;font:600 14.5px "Plus Jakarta Sans",system-ui,sans-serif;color:#151A33}
.pick-on{border:2px solid #3148D6;background:#ECEFFF;color:#2A3CB8}
.tabbar{position:absolute;left:0;right:0;bottom:0;height:80px;background:#FFFFFF;border-top:1px solid #E7E9F3;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));padding:8px 6px 16px}
.tab{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:52px;border-radius:14px;color:#666C84;text-decoration:none;font-size:11.5px;font-weight:600}
.tab-ic{width:48px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center}
.tab-on{color:#3148D6}.tab-on .tab-ic{background:#ECEFFF}
.muted{color:#666C84}
.num{font-variant-numeric:tabular-nums}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>
</helmet>
```

## 4. Shared components (build them exactly like this everywhere)

**Brand mark** (logo placeholder, never the real crest): 40×40 (sidebar) / 36×36 (public header) rounded 12px square `#3148D6` with a white shield-check line icon, `role="img" aria-label="Logo SMAGA Talent Hub"`; wordmark "SMAGA Talent Hub" Urbanist 17px/800 + "SMA Negeri 3 Semarang" 12px muted.

**App shell (desktop 1440 wide)**: root `<div class="page" style="width:1440px;height:<H>px;display:flex;overflow:hidden">`.
- Sidebar `<aside>` 264px, bg `#FFFFFF`, border-right `1px solid #E7E9F3`, padding 22px 16px, flex column gap 18px: brand row → (Siswa only) primary button "Cari lomba" with search icon linking `Katalog-Lomba.dc.html` → `<nav aria-label="Menu utama">` with `.nav-sec` group labels and `.nav` links (20px icon + label, current = `nav nav-on` + `aria-current="page"`, counts via `.badge`) → bottom (margin-top:auto) user card: 12px padding, radius 16, bg `#F4F5FB`, 38px `.avatar` initials + name 14px/700 + role 12.5px muted.
- Main column: flex 1, min-width 0, padding 26px 36px, flex column gap 22px. Top bar: left = title block (h1 Urbanist 30px/800 + one-line muted subtitle); right = search field 300px (`.input` with search icon, `<label class="sr-only">`), `.icon-btn` bell with `.dot` (aria-label "Notifikasi, 2 baru"), 40px `.avatar`.

**Sidebar menus per role** (icons from §6):
- Siswa: MENU → Beranda (home) · Cari lomba (search) · Lomba saya (clipboard) [badge 2] · Portofolio (award) · Peringkat (bars); AKUN → Profil talenta (user) · Bantuan (help). User: "Nadia Putri Anindya" · "Siswa · XI-D".
- Kesiswaan: MENU → Beranda (home) · Kelola lomba (trophy) · Usulan siswa (inbox) [badge 3] · Verifikasi hasil (shield) [badge 4]; DATA → Database prestasi (database) · Talenta siswa (users) · Apresiasi (award). User: "Ibu Dwi Rahayu" · "Kesiswaan".
- Kepala Sekolah: MENU → Dashboard (bars) · Persetujuan (check-circle) [badge 5] · Prestasi per bidang (chart-pie); LAPORAN → Laporan (file). User: "Drs. Bambang Hartono, M.Pd." · "Kepala Sekolah" (fictional).
- Tata Usaha: MENU → Antrean surat (mail) [badge 3] · Arsip surat (folder) · Template surat (file). User: "Ibu Sri Handayani" · "Tata Usaha".
- Humas: MENU → Antrean publikasi (megaphone) [badge 3] · Jadwal (calendar) · Apresiasi (award) · Arsip (folder). User: "Mas Galih Prasetyo" · "Humas".
- Admin: MENU → Dashboard (bars) · Kelola lomba (trophy); DATA → Import siswa (upload) · Pengguna & peran (users) · Arsip otomatis (archive); SISTEM → Pengaturan (sliders). User: "Operator STH" · "Admin".
- Guru Pembimbing (only if you need it): Siswa binaan (users) · Lomba (trophy).

**Public header** (public pages): height 72px, bg `rgba(255,255,255,.9)`, border-bottom, padding 0 56px; brand left; center nav links (Beranda · Prestasi · Peringkat) as plain `<a>` 15px/600 `#4A5068`, current `#151A33` with 2px `#3148D6` underline; right `<a class="btn btn-primary btn-sm" href="Masuk.dc.html">Masuk</a>`.

**Lomba card (catalog)**: `.card` overflow hidden, width fills grid cell. Cover 128px `.cover-<bidang>` position relative, padding 14px: top-left `.chip c-glass` tingkat; top-right status pendaftaran chip (`c-green` "Dibuka · 8 hari lagi", `c-orange` "Ditutup 2 hari lagi", `c-gray` "Ditutup"); bottom-right big bidang line icon 56px at 70% opacity. Body padding 16px 18px, gap 10px: bidang chip `.b-<bidang>` + individu/tim chip `c-gray`; title Urbanist 17px/700 (2 lines max); penyelenggara 13.5px muted; meta row with calendar + pin icons (13px); footer row: `.stack` of 3 small 26px avatars + "3 siswa SMAGA mendaftar" 12.5px muted, right link "Lihat detail" → `Detail-Lomba.dc.html`.

**Jejak Prestasi tracker v2** — 6 phases: 1 `Daftar` · 2 `Persetujuan` · 3 `Surat tugas` · 4 `Lomba` · 5 `Laporan hasil` · 6 `Poin & apresiasi`. Sub-statuses: Daftar (Menunggu konfirmasi anggota, Terdaftar) · Persetujuan (Menunggu Kepala Sekolah, Disetujui, Ditolak) · Surat tugas (Nomor surat diproses, Surat terbit) · Lomba (Berlangsung, Selesai) · Laporan hasil (Menunggu laporan · batas <date>, Laporan dikirim, Diverifikasi) · Poin & apresiasi (Poin diberikan, Dipublikasikan, Diapresiasi). Full size: 30px circles joined by 3px rounded lines; done = `#3148D6` fill + white check; current = white fill + 3px `#3148D6` ring + number, label 700; needs-action current = ring/number `#9A3F00` + `#FFEEDD` fill; upcoming = `#EEF0F6` fill, `#666C84` number, line `#DCDFEC`. Labels 13px under circles, optional date 12px muted. Compact: 6 segments (6px tall, gap 4px, radius 999; done `#3148D6`, current `#9A3F00` if needs action else `#8FA0EE`, upcoming `#E3E6F2`) + "Tahap N dari 6 · <phase>".

**Rumus poin tiles**: three tiles (bg `#F4F5FB`, radius 14, padding 10px 14px; 12px muted label on top; value Urbanist 20px/800 tabular) joined by "×", then "=" and a gold result tile (`#FFF3D1`/`#6B4E00`, value + "poin"). Decimal comma: `1,00`, `0,85`.

**Stat tile** (CrioEd style): `.card` padding 18px, row: 44px icon tile (radius 14, tinted bg matching meaning, colored line icon) + text (13px muted label, Urbanist 28px/800 value, 12.5px muted note).

**Mobile (390 wide)**: root `class="page"` `width:390px;height:<H>px;position:relative;overflow:hidden`. No fake status bar/keyboard. Side padding 20px, top 22px. Tab bar (student): `.tabbar` with 5 `<a class="tab">` each with `<span class="tab-ic">` icon 22px + label: Beranda (home) → `Beranda-Mobile.dc.html` · Lomba (search) → `Katalog-Lomba.dc.html` · Lombaku (clipboard) → `Lomba-Saya.dc.html` · Peringkat (bars) → `Peringkat.dc.html` · Profil (user) → `Portofolio.dc.html`; active = `tab tab-on` + `aria-current="page"`.

## 5. Shared fictional data (NEVER real student/teacher names or real NIS/NISN)
Today: Rabu, 30 September 2026. Tahun ajaran 2026/2027.
- Logged-in student **Nadia Putri Anindya** · NIS 225226201 · NISN 0098765432 · XI-D · angkatan 2025 (kelas X 2025/2026) · kelas XII mulai Juli 2027 · arsip otomatis Januari 2029 · avatar "NA" · email n•••@gmail.com.
- Nadia's points (cumulative): **104 poin**, **#14** dari siswa aktif. Verified achievements: OPSI 2026 · Nasional · Juara II · Puspresnas (A) → 80 × 0,85 × 1,00 = 68 · KRENOVA Kota Semarang 2026 · Kota/Kab · Juara I · Pemda (B) → 40 × 1,00 × 0,80 = 32 · O2SN Kota Semarang 2026 · Kota/Kab · Peserta · (A) → 40 × 0,10 × 1,00 = 4.
- Nadia's registrations:
  a. **WICE 2026** (World Invention Competition and Exhibition) · Internasional · Riset · Tim 3 (Nadia ketua, Dimas Arya Nugroho XI-D, Keisha Larasati XI-F) · IYSA, kategori D · SEGi University, Kuala Lumpur · lomba 21–24 Sep 2026 · approved by Kepala Sekolah 5 Sep · surat tugas 400.3.8/312/MRD/IX/2026 terbit 8 Sep · now **Laporan hasil: menunggu laporan, batas 24 Okt 2026** (needs action). Point preview if Medali emas: 100 × 1,00 × 0,40 = 40.
  b. **Lomba Peneliti Belia (LPB) 2026** · Nasional · Riset · Tim 2 (Nadia ketua, Dimas Arya Nugroho) · Center for Young Scientists, kategori C · Daring · lomba 17 Okt 2026 · registered 29 Sep · now **Persetujuan: menunggu Kepala Sekolah**. Pembimbing Bu Retno Wulandari, S.Pd.
- Catalog (created by Kesiswaan, all fictional-safe real event names):
  1. OSN Tingkat Kota Semarang 2026 · Sains · Kota/Kab · Puspresnas (A) · Individu · Luring, Kota Semarang · lomba 14 Okt 2026 · daftar s.d. 8 Okt · 12 siswa mendaftar
  2. Lomba Peneliti Belia (LPB) 2026 · Riset · Nasional · CYS (C) · Tim maks 3 · Daring · lomba 17 Okt 2026 · daftar s.d. 10 Okt · 5 siswa
  3. FLS3N Kota Semarang 2026 · Seni Budaya · Kota/Kab · Puspresnas (A) · Individu · lomba 27 Okt · daftar s.d. 2 Okt (Ditutup 2 hari lagi) · 7 siswa
  4. O2SN Kota Semarang 2026 · Bulu tangkis · Olahraga · Kota/Kab · (A) · Individu · lomba 20 Okt · daftar s.d. 9 Okt · 4 siswa
  5. National Science and Invention Fair (NSIF) 2026 · Riset · Nasional · swasta (D) · Tim maks 3 · Daring · lomba 7 Nov · daftar s.d. 20 Okt · 3 siswa
  6. Lomba Debat Bahasa Indonesia (LDBI) Kota 2026 · Seni Budaya · Kota/Kab · (A) · Tim 3 · lomba 24 Okt · daftar s.d. 12 Okt · 6 siswa
  7. KRENOVA Kota Semarang 2027 · Riset · Kota/Kab · Pemda (B) · Individu/Tim · lomba Feb 2027 · daftar dibuka 1 Des 2026 (chip gray "Segera dibuka")
  8. Kejuaraan Pencak Silat Pelajar Jateng 2026 · Olahraga · Provinsi · federasi (C) · Individu · lomba 1 Nov · daftar s.d. 15 Okt · 2 siswa
- Usulan siswa (for Kesiswaan): "International Science Project Olympiad (ISPO) 2027" by Raka Pradipta XII-B; "Lomba Film Pendek Pelajar Semarang" by Salsabila Kirana XI-E; "Kompetisi Robotik Pelajar Jateng 2026" by Bima Satria Wicaksono XII-G.
- Registrations waiting for Kepala Sekolah (5): LPB 2026 (Nadia + Dimas, Riset, Nasional); OSN Kota 2026 (Hana Safira X-H, Sains); O2SN Kota 2026 · Bulu tangkis (Fajar Ramadhan XII-A); LDBI Kota 2026 (Salsabila Kirana XI-E, Citra Ayu Lestari XI-B, Aurelia Maharani X-C); Pencak Silat Pelajar Jateng 2026 (Yoga Pratama XII-E).
- TU queue (approved, waiting for surat) (3): FLS3N Kota 2026 · Baca puisi (Citra Ayu Lestari XI-B; pembimbing Bu Maya Anggraini, S.Sn.) · NSIF 2026 (Raka Pradipta XII-B, Bima Satria Wicaksono XII-G, Yoga Pratama XII-E; pembimbing Pak Hendra Kusuma, S.Pd., M.Si.) — SELECTED in TU mockup, team of 3 → one shared student letter + one guru letter · OSN Kota 2026 (Aurelia Maharani X-C; Pak Hendra Kusuma).
- Laporan waiting for Kesiswaan verification (4): FIKSI 2026 (Raka Pradipta XII-B, Juara I Nasional, A) · O2SN Provinsi Jateng 2026 · Pencak silat (Fajar Ramadhan XII-A, Juara I Provinsi, A) · OSEBI 2026 (Aurelia Maharani X-C, Finalis Nasional, Eduversal, D) · FLS3N Provinsi 2026 · Baca puisi (Salsabila Kirana XI-E, Juara III Provinsi, A).
- Leaderboard (cumulative; on the public page archived students show chip "Alumni"): 1 Raka Pradipta XII-B 312 · 2 Salsabila Kirana XI-E 268 · 3 Bima Satria Wicaksono XII-G 240 · 4 Aurelia Maharani X-C 196 · 5 Fajar Ramadhan XII-A 180 · 6 Citra Ayu Lestari XI-B 164 · 7 Yoga Pratama XII-E 150 · 8 Hana Safira X-H 132 · 9 Rizky Maulana XI-A 121 · … 14 Nadia Putri Anindya XI-D 104.
- Staff: Kesiswaan Ibu Dwi Rahayu · Kepala Sekolah Drs. Bambang Hartono, M.Pd. (fictional) · TU Ibu Sri Handayani · Humas Mas Galih Prasetyo · Admin "Operator STH". Pembimbing: Bu Retno Wulandari, S.Pd. (Riset) · Pak Hendra Kusuma, S.Pd., M.Si. (Sains) · Bu Maya Anggraini, S.Sn. (Seni Budaya) · Pak Agus Setiawan, S.Or. (Olahraga).
- School stats (public + dashboard, SAMPLE data, always show chip "Data contoh" on dashboard; public page may omit the chip but numbers must match): prestasi terverifikasi per tahun ajaran — 2024/2025: 31 · 2025/2026: 44 · 2026/2027 (berjalan): 18. Per bidang (kumulatif 93): Sains 29 · Riset 24 · Seni Budaya 21 · Olahraga 19. Per tingkat (kumulatif): Kota/Kab 38 · Provinsi 25 · Nasional 21 · Internasional 9.
- Surat numbers: student letter pattern `400.3.8/___/MRD/X/2026`; guru letter pattern `400.3.8.6/____/2026`; last used numbers shown as hints: siswa 312, guru 1680.
- Signature+stamp file (Admin › Pengaturan): `ttd-stempel-kepsek.png` (placeholder image box labelled "Tanda tangan + stempel" with a dashed border).

## 6. Icons (inline SVG, `viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"`, 20px desktop / 22px mobile; always close tags `<path ...></path>`)
home `<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>`
search `<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>`
clipboard `<rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"></path>`
award `<circle cx="12" cy="9" r="6"></circle><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7"></path>`
bars `<path d="M6 20V11M12 20V5M18 20v-6M3 20h18"></path>`
user `<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>`
users `<circle cx="9" cy="8" r="3.5"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"></path>`
help `<circle cx="12" cy="12" r="9"></circle><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01"></path>`
trophy `<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"></path><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"></path>`
inbox `<path d="M3 13h5l1.5 3h5L16 13h5"></path><path d="M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z"></path>`
shield `<path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path>`
check-circle `<circle cx="12" cy="12" r="9"></circle><path d="m8 12 3 3 5-6"></path>`
chart-pie `<path d="M12 3v9h9"></path><path d="M20.5 15A9 9 0 1 1 9 3.5"></path>`
database `<ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"></path>`
mail `<rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path>`
folder `<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>`
file `<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path>`
megaphone `<path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1z"></path><path d="M17 8a5 5 0 0 1 0 8"></path>`
calendar `<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path>`
pin `<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"></path><circle cx="12" cy="10" r="2.5"></circle>`
upload `<path d="M12 16V4M7 9l5-5 5 5M4 20h16"></path>`
download `<path d="M12 4v12M7 11l5 5 5-5M4 20h16"></path>`
archive `<rect x="3" y="4" width="18" height="5" rx="1"></rect><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"></path>`
sliders `<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"></path><circle cx="16" cy="6" r="2"></circle><circle cx="10" cy="12" r="2"></circle><circle cx="18" cy="18" r="2"></circle>`
bell `<path d="M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z"></path><path d="M10 20a2 2 0 0 0 4 0"></path>`
plus `<path d="M12 5v14M5 12h14"></path>` · check `<path d="m5 12 5 5 9-10"></path>` · x `<path d="M6 6l12 12M18 6 6 18"></path>`
chevron-right `<path d="m9 6 6 6-6 6"></path>` · chevron-left `<path d="m15 6-6 6 6 6"></path>` · chevron-down `<path d="m6 9 6 6 6-6"></path>`
clock `<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>` · info `<circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path>`
link `<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"></path><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path>`
image `<rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="m21 17-5-5-9 8"></path>`
eye `<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle>`
edit `<path d="M4 20h4L19 9l-4-4L4 16z"></path><path d="m13.5 6.5 4 4"></path>`
trash `<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"></path>`
Bidang icons (cover + chips): Sains flask `<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"></path><path d="M7.5 15h9"></path>` · Riset bulb `<path d="M9 18h6M10 21h4"></path><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"></path>` · Seni Budaya palette `<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.5-.8 1.5-1.6 0-1.3-1.2-1.6-1.2-2.9 0-.9.7-1.5 1.7-1.5H17a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"></path><circle cx="7.5" cy="11" r="1"></circle><circle cx="10" cy="7" r="1"></circle><circle cx="15" cy="7.5" r="1"></circle>` · Olahraga ball `<circle cx="12" cy="12" r="9"></circle><path d="M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9M12 3C9.5 5.5 8.5 8.5 8.5 12s1 6.5 3.5 9M3.5 9h17M3.5 15h17"></path>`

## 7. .dc.html format rules (break one → it fails silently)
- Skeleton: `<!doctype html>`, `<html lang="id">`, head with `<meta charset="utf-8">`, a per-screen `<title>`, and EXACTLY `<script src="./support.js"></script>`; body = `<x-dc>` (helmet + markup) then `<script type="text/x-dc" data-dc-script data-props='{"$preview":{"width":W,"height":H}}'>class Component extends DCLogic { ... renderVals() { return {...}; } }</script>`.
- Root element has FIXED width/height = board size = `$preview`.
- Close every non-void element; quote every attribute. Layout with flex/grid + gap; grids `repeat(N, minmax(0, 1fr))`.
- `{{hole}}` is a dotted lookup into renderVals() only (never an expression). Events `onClick="{{ fn }}"` / `onChange="{{ fn }}"`; loops `<sc-for list="{{ items }}" as="item" hint-placeholder-count="3">`; branches `<sc-if value="{{ flag }}" hint-placeholder-val="{{ true }}">`. Per-item handlers and class strings are computed in renderVals. State via `constructor(props){ super(props); this.state = {...}; }` + `this.setState`.
- Links between artboards: `<a href="X.dc.html">` styled as the button itself (never a <button> inside <a>).
- Accessible as drawn: real `<button type="button">`, `<a href>`, inputs with `<label for>`, `aria-label` on icon-only buttons, `aria-pressed`/`aria-current` for selected states, tables with `<th scope="col">`. Hit targets ≥44px mobile, ≥36px desktop (prefer 40–44).
- No emoji, no lorem ipsum, no network except the helmet font link, no iframes, no innerHTML.
- Copy: Bahasa Indonesia, sentence case, SHORT (the user dislikes wordy text and bullet-point intros). Buttons are verbs. Students addressed as "kamu".
- Everything must fit inside the board; root `overflow:hidden`; tighten spacing rather than clip.

## 8. Link map (use exact filenames)
- Public: `Main.dc.html` (public home) · `Prestasi-Bidang.dc.html` (drill-down) · `Peringkat.dc.html` (public cumulative leaderboard). Public header links: Beranda → Main.dc.html, Prestasi → Prestasi-Bidang.dc.html, Peringkat → Peringkat.dc.html, Masuk → Masuk.dc.html.
- `Masuk.dc.html` (login) → "Masuk" → `Beranda-Siswa.dc.html`; "Lupa kata sandi?" → `#`.
- `Aktivasi.dc.html` (mobile first login) → "Mulai" → `Tur-Menu-Mobile.dc.html` → finish/skip → `Beranda-Mobile.dc.html`. `Tur-Menu-Desktop.dc.html` → finish/skip → `Beranda-Siswa.dc.html`.
- Siswa sidebar: Beranda → `Beranda-Siswa.dc.html` · Cari lomba → `Katalog-Lomba.dc.html` · Lomba saya → `Lomba-Saya.dc.html` · Portofolio → `Portofolio.dc.html` · Peringkat → `Peringkat.dc.html` · Profil talenta/Bantuan → `#`.
- Catalog card / "Lihat detail" → `Detail-Lomba.dc.html`. Detail's registration success → link "Lihat di Lomba saya" → `Lomba-Saya.dc.html`. "Usulkan lomba" opens an in-artboard panel (no separate board).
- Desktop "Laporkan hasil" → `Lomba-Saya.dc.html`; mobile "Laporkan hasil" → `Lapor-Hasil-Mobile.dc.html`; Lapor back → `Beranda-Mobile.dc.html`.
- Kesiswaan: Kelola lomba & Usulan siswa → `Kesiswaan-Kelola-Lomba.dc.html` · Verifikasi hasil → `Kesiswaan-Verifikasi-Hasil.dc.html`.
- Kepala Sekolah: Dashboard → `Dashboard-Sekolah.dc.html` · Persetujuan → `Kepsek-Persetujuan.dc.html` · Prestasi per bidang → `Prestasi-Bidang.dc.html`.
- TU: Antrean surat → `TU-Surat-Tugas.dc.html`. Humas: Antrean publikasi → `Humas-Publikasi.dc.html`.
- Admin: Dashboard → `Dashboard-Sekolah.dc.html` · Kelola lomba → `Kesiswaan-Kelola-Lomba.dc.html` · Import siswa → `Admin-Import-Siswa.dc.html` · Pengaturan → `Admin-Pengaturan.dc.html`.
- Dashboard bidang chart rows → `Prestasi-Bidang.dc.html`.
- Anything else → `#`.

## 9. Artboards (file · size · owner agent)
Row 1 Publik: `Main.dc.html` 1440×1100 (A) · `Prestasi-Bidang.dc.html` 1440×1000 (A, interactive) · `Peringkat.dc.html` 1440×1000 (A, interactive)
Row 2 Akun: `Masuk.dc.html` 1280×800 (B) · `Aktivasi.dc.html` 390×844 (B, interactive) · `Tur-Menu-Mobile.dc.html` 390×844 (B, interactive) · `Tur-Menu-Desktop.dc.html` 1440×1000 (B, interactive)
Row 3 Siswa: `Beranda-Siswa.dc.html` 1440×1000 (C) · `Katalog-Lomba.dc.html` 1440×1000 (C, interactive) · `Detail-Lomba.dc.html` 1440×1100 (C, interactive) · `Lomba-Saya.dc.html` 1440×1000 (D, interactive) · `Portofolio.dc.html` 1440×1000 (D) · `Beranda-Mobile.dc.html` 390×844 (D) · `Lapor-Hasil-Mobile.dc.html` 390×1980 (D, interactive)
Row 4 Staf: `Kesiswaan-Kelola-Lomba.dc.html` 1440×1000 (E, interactive) · `Kesiswaan-Verifikasi-Hasil.dc.html` 1440×1000 (E, interactive) · `Kepsek-Persetujuan.dc.html` 1440×1000 (E, interactive) · `TU-Surat-Tugas.dc.html` 1440×1100 (F, interactive) · `Humas-Publikasi.dc.html` 1440×1000 (F)
Row 5 Admin: `Admin-Import-Siswa.dc.html` 1440×1000 (G, interactive) · `Admin-Pengaturan.dc.html` 1440×1000 (G, interactive) · `Dashboard-Sekolah.dc.html` 1440×1080 (G)
If content truly cannot fit, you may make a board taller: say so in your notes file with the exact new height (the coordinator updates canvas.json).
