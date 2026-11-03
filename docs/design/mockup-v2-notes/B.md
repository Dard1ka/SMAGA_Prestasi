# Agent B notes: Akun row (login, activation, menu tours)

## Files (all in project/)
| File | Root size | Interactive |
|---|---|---|
| Masuk.dc.html | 1280×800 | yes: show/hide password toggle + links. Coordinator: please set `is_interactive: true` |
| Aktivasi.dc.html | 390×844 | yes: state `step` 1..4 |
| Tur-Menu-Mobile.dc.html | 390×844 | yes: state `step` 0..5 |
| Tur-Menu-Desktop.dc.html | 1440×1000 | yes: state `step` 0..6 |
No board height changes.

## Link targets used
- Masuk: "Masuk" → Beranda-Siswa.dc.html · "Lupa kata sandi?" → # · "Lihat prestasi sekolah" (top-right text link) → Main.dc.html
- Aktivasi: step 4 "Mulai" → Tur-Menu-Mobile.dc.html (steps 1–3 are in-board state)
- Tur-Menu-Mobile: "Lewati" (welcome + every tip) and "Mulai" (finish) → Beranda-Mobile.dc.html. Replica tab bar keeps D's hrefs (Beranda-Mobile / Katalog-Lomba / Lomba-Saya / Peringkat / Portofolio), all tabindex -1
- Tur-Menu-Desktop: "Lewati" (welcome + every tip) and "Mulai" (finish) → Beranda-Siswa.dc.html. Replica sidebar/main keep C's hrefs, all tabindex -1

## Mirroring
- Tur-Menu-Mobile background = D's Beranda-Mobile `<main>` copied verbatim (spliced 19:4x), plus tab bar. Made inert with `aria-hidden="true"` and `tabindex="-1"`. D's classes copied into my helmet: .tab:hover, .m-card, .m-link, .bn-link, .sg/.sg-done/.sg-cur/.sg-act.
- Tur-Menu-Desktop background = C's canonical siswa sidebar + C's Beranda-Siswa `<main>` copied verbatim, made inert the same way. C's classes copied: .btn-white, .lnk, .clamp2, .ttl-link. One tweak: `.nav-sec` items have inline `line-height:16px` and the brand row has `height:40px`, so the popover arrows line up with the nav items.
- If C or D change their Beranda screens after this, the tour backgrounds need the same splice again.

## Tour mechanics (for anyone reusing it)
- Scrim `.scrim` = absolute inset 0, z 10, rgba(21,26,51,0.55). The spotlighted element gets z 30, white background and a 3px #A9B6F5 ring (`.tab.spot`, `.nav.spot`; the blue "Cari lomba" button uses `.spot-btn` = 4px white halo + 3px ring). Popovers sit at z 40.
- Mobile: popover sits above the tab bar (bottom 96px). Arrow points down at the tab (`.arw-1..4`). Counter shows "N dari 4" with 4 dots. Steps: 0 welcome · 1 Lomba · 2 Lombaku · 3 Peringkat · 4 Profil · 5 finish ("Siap dipakai!").
- Desktop: popover sits right of the sidebar (left 282px) with a left-pointing arrow (`.pop-s1..6` set top). Counter shows "N dari 5". Steps: 0 welcome modal · 1 Cari lomba button · 2 Lomba saya · 3 Portofolio · 4 Peringkat · 5 Profil talenta · 6 finish. The finish is a popover anchored to the spotlighted "Bantuan" item, with Kembali + Mulai.

## Aktivasi behavior (booleans only, no values stored)
- Step 1: `len8`, `mix` and match (`sama`) are computed on input. "Beda dari kata sandi awal" = len8 && mix, because the prototype cannot know the initial password (the real server checks it). "Lanjut" only continues when all rules pass and both entries match; otherwise it shows an inline error.
- Step 2: "Kirim kode" needs a valid email format (`emailOk`).
- Step 3: six boxes. Typing auto-advances, Backspace goes back, and pasting a full code fills every box. "Verifikasi" needs all 6 filled (any digits). "Ganti email" returns to step 2.
- Back button on steps 2–3 resets that step's booleans.

## New CSS classes appended (screen-local)
Masuk: .float-card .ring .text-link · Aktivasi: .seg .seg-on .step-ic .m-input .eye-btn .rule-ic .rule-ic-on .otp .err .bottom-cta .link-btn · Tours: .scrim .tab.spot/.nav.spot .spot-btn .tour-card/.tour-modal .tour-pop .tour-arrow .arw-N/.pop-sN .tdot .tdot-on .skip .tile

## Icons beyond §6
lock (`rect 5,11,14,10 rx2` + `M8 11V7a4 4 0 0 1 8 0v4`) is the Aktivasi step 1 tile. eye-off (eye + `M4 4l16 16`) is used for the show/hide toggles.

## Copy/data I introduced (all fictional, consistent with spec)
- Masuk left panel: headline "Prestasimu, tercatat rapi." and line "Daftar lomba, pantau surat tugas, dan kumpulkan poin prestasi." Decorative cards: "LPB 2026 · Nasional / 17 Okt 2026 · Daring" (cover-riset), "+68 poin / OPSI 2026 · Juara II" (gold), "Surat tugas terbit / WICE 2026" (compact tracker).
- Aktivasi: account card "Nadia Putri Anindya · NIS 225226201 · XI-4". Resend timer is static "0:45". Masked email is always "n•••@gmail.com". Success card rows: "Kata sandi baru tersimpan" and "Email n•••@gmail.com terhubung".
- The initial-password pattern is never shown or hinted.
