# SMAGA Talent Hub — DESIGN SPEC v3 ("Tinta dan Sampul")

Decision: `docs/DECISIONS.md` D-117…D-119 (7 Oct 2026). This file **replaces the look** of
`DESIGN-SPEC-v2.md`: its §2 (visual direction), the values of §3 (helmet) and the appearance of the §4
components. Everything else in v2 stays valid: the product flow (§1), the component list and contracts
(§4, `docs/dev-notes/W1-D.md`), the fictional data set (§5) and the icons (§6). The v2 mockups
(`mockup-v2/*.dc.html`) are design history: they show the old look and are not redrawn.

Source of the change: the UI audit of 6 Oct 2026 (every screen of every role at 1440 px and 390 px) and the
lead's choices after it. The audit report with before/after pictures is outside the repo
(https://claude.ai/artifact/91hVTZ6Dn25ZgwoNYfdTDq).

## 1. Why

The v2 look ("Behance edu-soft") had every habit of a template dashboard at once: indigo with a lavender
wash, pastel gradient covers, 20 px cards with soft wide shadows, pills everywhere, glowing primary buttons,
tilted floating cards and two fashionable geometric typefaces. Nothing but the name said "SMA Negeri 3
Semarang", and for Kepala Sekolah, Tata Usaha and Admin the tone was too light. Measured on the old screens:
32 font sizes, 53% of all text below 14 px, input borders at 1.3:1.

The lead's correction to a first, plainer proposal matters as much: **do not strip the pictures**. A page of
white cards with text only is tiring to look at. So:

> A calm ink frame, and pictures where people browse. Every picture means something.

## 2. Rules

1. **Colour carries meaning only.** Status (green done, orange needs action, red rejected, blue in
   progress, gray waiting), gold for points and medals, the four bidang colours. The frame itself (menu,
   buttons, headings) is ink navy and neutral gray.
2. **One typeface, a fixed scale.** Public Sans 400/500/600/700. Sizes: 13 · 14 · 15 · 16 · 18 · 20 · 24 ·
   28 · 36 · 44 (`text-xs` … `text-5xl`, see §3). **Nothing on screen is smaller than 13 px.** No
   `text-[Npx]` in a view. The one exception is the scaled paper preview of a letter
   (`livewire/tata-usaha/partials/*`), which imitates an A4 page.
3. **Crisp shapes.** Radius 6 (controls), 8 (tiles), 10 (cards), 12 (dialogs), 4 (tags). Round only what is
   round by nature: avatars, count badges, tracker dots, radio dots.
4. **No decoration without information.** No gradients, no glow shadows, no blurred blobs, no tilted or
   floating cards, no page wash. One hairline shadow under cards; real depth only for things that float
   (menus, dialogs, toasts).
5. **Covers are always filled** (§5), and the banner motif is the tracker (§6).
6. **Staff screens stay calm.** Tables, forms and work queues carry no pictures. There the colour comes
   from status tags, the icon tile of a stat tile, and the bidang dot.
7. **One way to each place, one name per thing.** The student's "Cari lomba" is a menu item plus the one
   button on the home banner. The tab bar uses the menu's names ("Lomba saya", "Portofolio").
8. **Copy** (D-119): students are addressed as "kamu". Staff (guru, Kesiswaan, Kepala Sekolah, Tata Usaha,
   Humas, Admin) get sentences without a pronoun, "Anda" only where a sentence breaks without one. No
   internal codes such as "(D-08)" or "(U-04)" on a screen. The rest of CLAUDE.md §3.9 is unchanged.
9. Unchanged from v2: AA contrast, ≥ 44 px touch targets on touch devices, every input has a label, status
   tags always carry text, no emoji, no dark theme, no `style=""` and no inline JS (CSP).

## 3. Tokens (`resources/css/app.css`, Tailwind v4 `@theme`)

| group | tokens |
|---|---|
| surfaces | `page #F3F5F8` · `surface #FFFFFF` · `subtle #F6F7F9` · `hover #ECEFF3` |
| lines | `line #DDE2E9` (cards) · `line-soft #E8EBF0` (table rows) · `line-strong #C3C9D3` (quiet buttons, filters) · `line-input #7F8794` (inputs, 3.6:1) · `line-dashed #9AA2AF` |
| text | `ink #141A2B` · `ink-2 #3A4254` · `muted #566072` · `placeholder #687184` (4.9:1) |
| primary (ink navy) | `primary #1B2A4E` · `primary-hover #111C38` · `primary-tint #E9EDF4` · `primary-tint-hover #DCE2EC` · `primary-soft #8794B3` · `on-primary-soft #CDD4E6` |
| links, focus | `link #1C4A9C` · `link-hover #143670` · `focus #2F6FDE` (2 px ring, 4.7:1) |
| status | `green #1B6B45 / #E4F3EA` · `orange #9A3F00 / #FDEBD8` (`orange-line`, `orange-wash`) · `red #B42318 / #FCEAE7` (`red-line`) · `gray #3A4254 / #E8EBF0` · `blue #1C4A9C / #E8EEF9` |
| gold | `gold #6B4E00` on `gold-bg #FBF0CC` · `gold-line #EAD8A0` · `medal #E0A82A` |
| bidang | `sains #1D56AD`, `riset #5534B8`, `seni #A61F54`, `olahraga #0B6577`; each with `-bg` (tile), `-cover` (flat cover field) and `-chart` (dot, chart series) |

Type scale (rem; the page title is `text-2xl` on phones and `text-3xl` from lg):

| class | px | used for |
|---|---|---|
| `text-xs` | 13 | captions, tags, table heads, tab bar labels |
| `text-sm` | 14 | labels, hints, meta text, small buttons |
| `text-md` | 15 | controls (buttons, menu, tabs) and table cells |
| `text-base` | 16 | body text, inputs (all widths) |
| `text-lg` | 18 | card titles (`.title-card`) |
| `text-xl` | 20 | section titles (`.title-section`) |
| `text-2xl` / `text-3xl` | 24 / 28 | page title (`.title-page`), stat values |
| `text-4xl` / `text-5xl` | 36 / 44 | points, the hero headline |

Radii `rounded-tag 4` · `rounded-control 6` · `rounded-tile 8` · `rounded-card 10`. Shadows `shadow-card`
(hairline), `shadow-pop`, `shadow-drawer`, `shadow-float`; `shadow-primary` and `shadow-hero` are empty and
only kept so old class names do not break.

## 4. Components (class names and `<x-sth.*>` contracts unchanged unless noted)

- **Buttons** `.btn`: 44 px, radius 6, 15/600. Primary = ink navy, no glow. Quiet = white with a
  `line-strong` border. Soft = `primary-tint` with ink text. Danger unchanged. `.btn-white` on ink panels.
- **Tags** (class `.chip`, component `<x-sth.chip>`): 24 px high, radius 4, 13/600. Tinted = a status or
  gold. `tone="gray"` = white with a hairline (neutral facts: tingkat, peserta). `tone="glass"` = solid white
  for use on a cover. A **bidang tag** is a colour dot + the name, no fill. `.chip-wrap` lets a long tag take
  two lines in a narrow column.
- **Stat tile** `<x-sth.stat-tile>`: 40 px icon tile + label 14 / value 28 / note 13. On phones two per row
  (`grid-cols-2 … xl:grid-cols-4`) with the icon above the text. New prop `zero`: `quiet` (default: gray
  icon and muted number at 0), `hide` (a warning tile such as "Dibekukan" is not rendered at 0), `show`.
- **Sidebar**: current item = tinted row with bold ink text. Section labels in sentence case. No button
  above the menu. Count badges are ink navy.
- **Tables** `.tbl`: head 13/600, cells 15, row padding 12. A `fixed` table gets a floor
  (`table-class="min-w-[720px]"`), so it scrolls inside its card on phones instead of squeezing a column.
- **Forms**: inputs 46 px, radius 6, 16 px text at every width, border `line-input`. Pick tiles and segmented
  controls keep their behaviour; the selected state is ink, not indigo.
- **Tracker**: ink for done/current, orange only for "needs action".
- **Facts strip** `<x-sth.facts>`: icon next to the label, no tinted box.
- **Avatars**: smallest size 28 px so 13 px initials fit.
- **Public header**: solid white, no blur. **Dialogs**: radius 12.

## 5. Sampul (covers) — new component `<x-sth.sampul>`

Every lomba has a cover, in this order:

1. **Its own poster** when Kesiswaan uploaded one ("Sampul" in Kelola lomba, JPG/PNG/WEBP ≤ 3 MB;
   `competitions.cover_file_id`). Served by `files.cover` (D-118) as a small JPEG. A poster is usually
   portrait and the cover is wide: it is shown **whole in the middle over a blurred copy of itself** (two
   `<img>`, never an inline background; when the file cannot be loaded, `app.js` removes both and the
   template drawing shows).
2. Otherwise a **template drawing of its bidang**: 3 per bidang (Sains: kisi molekul, orbit, gelombang ·
   Riset: grafik, sebar data, jaringan · Seni Budaya: kawung, parang, gringsing · Olahraga: lapangan,
   lintasan, jaring). Plain lines and shapes, no gradient, our own SVG files in `resources/images/sampul`.
   The variant comes from the lomba id (`App\Support\Sampul::variant()`), so one lomba always shows the same
   drawing and the lomba of one bidang do not all look alike.

Two styles of each drawing: **terang** (light; cards, many on a page) and **pekat** (deep; the single big
cover of a detail page, `tone="pekat"`). Sizes: card 96 px (phones) / 124 px, detail 144 / 160 px, a band on
small cards 56–64 px. Where: lomba cards (Cari lomba, Beranda, Guru › Lomba), Detail lomba, Undangan tim,
Portofolio, the public "Prestasi terbaru" cards and home collage, the Humas queue cards. Public pages and
Humas show the template drawing only (the poster route needs a login, D-118).

Small bidang surfaces (icon tiles in lists, `.cover-<bidang>`) are one flat colour.

## 6. Spanduk (banner) — class `.spanduk`

The student home banner ("Halo, Nadia") and the brand panel of the login page are ink navy with one motif,
**lintasan**: arcs with six dots = the six steps of Jejak Prestasi (four done in gold, the current one
ringed, the next one open). On narrow banners the dots are left out so none sits under the text
(`motif/lintasan-polos.svg`). `.spanduk-tinggi` = the tall auth panel. The sample cards on the login panel
and on the public home are kept, straight and on one grid.

## 7. What must not come back

Gradient covers and the page wash, `shadow-primary` glows, tilted (`rotate-*`) or blurred decoration, pill
tags, a second typeface, `text-[Npx]` sizes and anything below 13 px, an "eyebrow pill" above a headline, the
"Cari lomba" button above the student menu, "Lombaku" / "Profil" as tab names, "kamu" on a staff screen.

## 8. Checking a screen

`/_dev/components` (local) shows every component, the 12 cover drawings and the type scale. A screen PR
still includes screenshots at 1440 and 390 px. Things worth measuring on a new screen: no sideways scroll at
390 px, no computed font size below 13 px, one font family, no `background-image: …gradient`.
