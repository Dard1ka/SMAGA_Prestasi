# Agent C notes (FINAL)

Status: all 3 screens written. Sidebar + top bar markup below is FINAL (copy verbatim).

## Canonical SISWA sidebar (copy verbatim)
Only change per screen: move `nav nav-on` + `aria-current="page"` to the current item (all other items plain `class="nav"`, no aria-current).
Below it is shown with Beranda current (Beranda-Siswa.dc.html). Katalog/Detail use Cari lomba current. Lomba saya → Lomba saya current, Portofolio → Portofolio current.
Root shell: `<div class="page" style="width:1440px;height:<H>px;display:flex;overflow:hidden">` then this aside, then `<main ...>`.

```html
  <aside style="width:264px;flex-shrink:0;background:#FFFFFF;border-right:1px solid #E7E9F3;padding:22px 16px;display:flex;flex-direction:column;gap:18px">
    <div style="display:flex;align-items:center;gap:12px;padding:0 4px">
      <span role="img" aria-label="Logo SMAGA Talent Hub" style="width:40px;height:40px;border-radius:12px;background:#3148D6;color:#FFFFFF;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path></svg></span>
      <div style="display:flex;flex-direction:column;gap:2px;min-width:0">
        <span class="display" style="font-size:17px;font-weight:800;line-height:1.15">SMAGA Talent Hub</span>
        <span class="muted" style="font-size:12px;line-height:1.3">SMA Negeri 3 Semarang</span>
      </div>
    </div>
    <a class="btn btn-primary" href="Katalog-Lomba.dc.html" style="width:100%"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>Cari lomba</a>
    <nav aria-label="Menu utama" style="display:flex;flex-direction:column;gap:4px">
      <div class="nav-sec">Menu</div>
      <a class="nav nav-on" href="Beranda-Siswa.dc.html" aria-current="page"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path></svg>Beranda</a>
      <a class="nav" href="Katalog-Lomba.dc.html"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>Cari lomba</a>
      <a class="nav" href="Lomba-Saya.dc.html"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"></path></svg>Lomba saya<span class="badge num">2</span></a>
      <a class="nav" href="Portofolio.dc.html"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="9" r="6"></circle><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7"></path></svg>Portofolio</a>
      <a class="nav" href="Peringkat.dc.html"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 20V11M12 20V5M18 20v-6M3 20h18"></path></svg>Peringkat</a>
      <div class="nav-sec" style="margin-top:10px">Akun</div>
      <a class="nav" href="#"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>Profil talenta</a>
      <a class="nav" href="#"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01"></path></svg>Bantuan</a>
    </nav>
    <div style="margin-top:auto;padding:12px;border-radius:16px;background:#F4F5FB;display:flex;align-items:center;gap:10px">
      <span class="avatar" style="width:38px;height:38px;font-size:14px" aria-hidden="true">NA</span>
      <div style="display:flex;flex-direction:column;gap:2px;min-width:0">
        <span style="font-size:14px;font-weight:700;line-height:1.3">Nadia Putri Anindya</span>
        <span class="muted" style="font-size:12.5px;line-height:1.3">Siswa · XI-4</span>
      </div>
    </div>
  </aside>
```

## Canonical siswa main column + top bar (copy; change only h1 text, subtitle text, and the input id if needed)

```html
  <main style="flex:1;min-width:0;padding:26px 36px;display:flex;flex-direction:column;gap:22px">
    <header style="display:flex;align-items:center;justify-content:space-between;gap:24px">
      <div style="display:flex;flex-direction:column;gap:4px;min-width:0">
        <h1 style="margin:0;font-size:30px;font-weight:800;line-height:1.15">Beranda</h1>
        <p class="muted" style="margin:0;font-size:15px">Rabu, 30 September 2026</p>
      </div>
      <div style="display:flex;align-items:center;gap:12px;flex-shrink:0">
        <div style="position:relative;width:300px">
          <label class="sr-only" for="cari-atas">Cari lomba</label>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="position:absolute;left:14px;top:13px;color:#8A90A6"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
          <input class="input" id="cari-atas" type="search" placeholder="Cari lomba" style="padding-left:44px">
        </div>
        <button class="icon-btn" type="button" aria-label="Notifikasi, 2 baru"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z"></path><path d="M10 20a2 2 0 0 0 4 0"></path></svg><span class="dot"></span></button>
        <span class="avatar" role="img" aria-label="Nadia Putri Anindya" style="width:40px;height:40px;font-size:14px">NA</span>
      </div>
    </header>
    ... screen content ...
  </main>
```

## My files (project/)
| File | Root size | Interactive |
|---|---|---|
| Beranda-Siswa.dc.html | 1440x1000 | no (static links only) |
| Katalog-Lomba.dc.html | 1440x1000 | yes: state `bidang` (chips, aria-pressed), `tingkat` + `status` selects, `q` search, `usul` sheet (+ `usulSent`, `usulNama`, `usulErr`) |
| Detail-Lomba.dc.html | 1440x1100 | yes: state `tab` (tentang/persyaratan/jadwal/dokumen), `daftar` (form/sent), `anggota` list (add/remove, max 3 incl. ketua), `q` member search, `guru` select, `setuju` checkbox |
No board height changes. Coordinator: all three already `is_interactive` except Beranda (static; set true only if you want Play for its links).

## Link targets used
- Sidebar: Beranda -> Beranda-Siswa · Cari lomba (button + nav) -> Katalog-Lomba · Lomba saya -> Lomba-Saya · Portofolio -> Portofolio · Peringkat -> Peringkat · Profil talenta/Bantuan -> #
- Beranda: hero "Cari lomba" -> Katalog-Lomba · "Laporkan hasil" -> Lomba-Saya · Lomba saya "Lihat semua" + card titles WICE/LPB -> Lomba-Saya · gold card "Peringkat" -> Peringkat · Batas daftar "Katalog" -> Katalog-Lomba · Rekomendasi "Lihat semua lomba" -> Katalog-Lomba · card "Lihat detail" -> Detail-Lomba
- Katalog: every card "Lihat detail" -> Detail-Lomba · "Usulkan lomba" opens in-board sheet (no link)
- Detail: breadcrumb "Cari lomba" -> Katalog-Lomba · Dokumen "Unduh"/"Buka" -> # · sent state "Lihat di Lomba saya" -> Lomba-Saya

## CSS appended (end of helmet style, screen-local)
- All 3: `.lnk` (inline text link, min-height 36px) · Beranda also `.btn-white`, `.clamp2`, `.ttl-link`
- Katalog: `.clamp2`, `.fchip`/`.fchip-on` (bidang filter pills), `.sel` (pill select), `.drop` (dashed upload box)
- Detail: `.stab`/`.stab-on` (underline section tabs), `.seg`/`.seg-btn`/`.seg-on`/`.seg-off` (Individu|Tim), `.btn-primary:disabled`, `.cek` (checkbox), `.rt` (rumus tile)

## Data I used or invented (keep consistent)
- Initials rule = first + last word: Nadia NA, Dimas Arya Nugroho DN, Keisha Larasati KL, Raka Pradipta RP, Bima Satria Wicaksono BW, Yoga Pratama YP, Hana Safira HS, Aurelia Maharani AM, Rizky Maulana RM, Salsabila Kirana SK, Citra Ayu Lestari CL, Fajar Ramadhan FR.
- Catalog card lines (penyelenggara · kategori / date / place / status chip / peserta):
  OSN Tingkat Kota Semarang 2026: Puspresnas · Kategori A / 14 Okt 2026 / Kota Semarang / "Dibuka · 8 hari lagi" / Individu / 12 siswa
  LPB 2026: Center for Young Scientists · Kategori C / 17 Okt 2026 / Daring / "Dibuka · 10 hari lagi" / Tim maks 3 / 5 siswa
  FLS3N Kota Semarang 2026: Puspresnas · Kategori A / 27 Okt 2026 / Kota Semarang / c-orange "Ditutup 2 hari lagi" / Individu / 7 siswa
  O2SN Kota Semarang 2026 · Bulu tangkis: Puspresnas · Kategori A / 20 Okt 2026 / Kota Semarang / "Dibuka · 9 hari lagi" / Individu / 4 siswa
  NSIF 2026: "Penyelenggara swasta · Kategori D" (no org name invented) / 7 Nov 2026 / Daring / "Dibuka · 20 hari lagi" / Tim maks 3 / 3 siswa (RP, BW, YP = the TU-queue NSIF team)
  LDBI Kota 2026: Puspresnas · Kategori A / 24 Okt 2026 / Kota Semarang / "Dibuka · 12 hari lagi" / Tim 3 / 6 siswa
  KRENOVA Kota Semarang 2027: Pemerintah Kota Semarang · Kategori B / Feb 2027 / Kota Semarang / c-gray "Segera dibuka" / Individu/Tim / "Daftar dibuka 1 Des 2026"
  Pencak Silat Pelajar Jateng 2026: IPSI Jawa Tengah · Kategori C / 1 Nov 2026 / Jawa Tengah / "Dibuka · 15 hari lagi" / Individu / 2 siswa
- Katalog "Semua" shows the first 6 in spec order (OSN, LPB, FLS3N, O2SN, NSIF, LDBI) + "8 lomba · Menampilkan 6 dari 8". Status filter "Ditutup" -> empty state ("Hapus filter" / "Usulkan lomba").
- Beranda: hero "Halo, Nadia" + "Ada 8 lomba yang bisa kamu ikuti bulan ini." + mini card "Poin kamu 104 #14"; Perlu tindakan "WICE 2026 · laporan hasil / Batas 24 Okt 2026 · 24 hari lagi"; compact cards WICE (tahap 5, chip c-orange "Menunggu laporan · batas 24 Okt") and LPB (tahap 2, chip c-gray "Menunggu Kepala Sekolah"); Rekomendasi = NSIF, OSN Kota, KRENOVA 2027 ("Sesuai bidang minatmu: Riset & Sains").
- Detail NSIF: Jadwal 15 Sep 2026 Pendaftaran dibuka (Selesai) · 20 Okt 2026 Batas pendaftaran (20 hari lagi) · 27 Okt 2026 Unggah abstrak dan video presentasi · 7 Nov 2026 Presentasi daring dan pengumuman juara. Dokumen juknis-nsif-2026.pdf (PDF · 1,2 MB). Rumus preview Juara I 80 × 1,00 × 0,40 = 32 poin. Registration default: ketua Nadia + Keisha Larasati XI-6 "Menunggu konfirmasi"; suggestions Dimas Arya Nugroho XI-4, Rizky Maulana XI-1; pembimbing default Pak Hendra Kusuma, S.Pd., M.Si. (options also Bu Retno, Bu Maya, Pak Agus). Kirim needs checkbox + at least 1 anggota.
