# SMAGA Talent Hub — DESIGN SPEC v3 ("Tinta dan Sampul")

Decision: `docs/DECISIONS.md` D-117…D-119 (7 Oct 2026); added on 10 Oct 2026 (EM2): the confirmation dialog pattern
(§4, D-128) and the mail template (§9, D-129, *sementara*); 12 Oct 2026 (FS1): count badges red (D-136). This file **replaces the look** of
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
   buttons, headings) is ink navy and neutral gray. *12 Oct 2026 (D-136):* count badges (sidebar, bell) are red:
   something waits to be read or checked. Tab and lane counts (`.cnt`) stay gray, the unread row marker stays navy.
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
7. **One way to each place, one name per thing.** A screen does not repeat a menu item as a button or link:
   the student's "Cari lomba" is the menu item (tab "Lomba" on phones) and the search box, nothing on the
   home banner; "Masuk" is in the public header only; a button that already sits at the top of a page is not
   repeated in its empty state. Still fine: a "see all" link next to a short list, a breadcrumb, the one next
   step of an empty list ("Cari lomba" when a student has no lomba yet), and the one button of the public
   hero ("Lihat prestasi"). The tab bar uses the menu's names ("Lomba saya", "Portofolio").
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
| gold | `gold #6B4E00` on `gold-bg #FBF0CC` · `gold-line #EAD8A0` · `medal #E0A82A` (rank 1) · `medal-2 #C2C9D4` (silver, rank 2) · `medal-3 #D39A6A` (bronze, rank 3) |
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
- **Brand mark** `<x-sth.brand>` (D-120): the crest of SMA Negeri 3 Semarang, 40 px high (36 px in the public
  header), with the wordmark beside it; on ink it stands on a white tile. It is the one colourful thing in the
  frame, which is why the frame is ink. The file is a small raster picture (class `.brand-mark`): not larger than
  about 56 px until the school gives a vector file. Never redraw or recolour it.
- **Sidebar**: current item = tinted row with bold ink text. Section labels in sentence case. No button
  above the menu. Count badges are ink navy. *12 Oct 2026 (D-136): red #B42318, white text.* A long item name wraps to
  two lines (FS1, D-135).
- **Tables** `.tbl`: head 13/600, cells 15, row padding 12. A `fixed` table gets a floor
  (`table-class="min-w-[720px]"`), so it scrolls inside its card on phones instead of squeezing a column.
- **Forms**: inputs 46 px, radius 6, 16 px text at every width, border `line-input`. Pick tiles and segmented
  controls keep their behaviour; the selected state is ink, not indigo.
- **Tracker**: ink for done/current, orange only for "needs action".
- **Facts strip** `<x-sth.facts>`: icon next to the label, no tinted box. Its columns follow its own width
  (container query): one row only when every cell gets about 138 px, else three or two per row. `plain` = no
  card of its own, for use inside `<x-sth.lomba-head>`.
- **Avatars**: smallest size 28 px so 13 px initials fit.
- **Boxes of one grid are the same height** (`auto-rows-fr` on the grid, `h-full` on a linked tile): stat tiles,
  bidang tiles, pick tiles (`<x-sth.radio-pick columns>`), also when one label takes two lines. Two boxes that
  open a page side by side share one grid row (Siswa › Beranda: banner and points card).
- **Pick tiles**: text centred, also on two lines. A grid of long labels shows two per row on phones
  (`phone-columns="2"`, e.g. the ten capaian). **Points formula** `<x-sth.rumus-poin>`: on a narrow phone
  "× Penyelenggara" moves to a second row; a label never leaves its tile.
- **Top three of a ranking**: one row with the medal (gold, silver, bronze: `bg-medal`, `bg-medal-2`,
  `bg-medal-3`) and the name, below a line the points on the left and the count on the right. No avatar there.
- **Public header**: solid white, no blur. **Dialogs**: radius 12.
- **Confirmation dialog** (D-128, EM2, 10 Oct 2026): a question before an action is always `<x-sth.modal size="sm">`,
  never the browser's own confirm box. The one-click and bulk actions swept in EM2 got one; D-128 lists the senders
  left without a question on purpose (among them Apresiasi "Masukkan" and "Simpan perubahan" on a published lomba,
  an open question for the lead). Title = the question ("Tolak laporan ini?", "Tutup lomba ini?"); the line under
  the title says who is told, and names only people who really get a notice (on a student screen it never states
  another student's account status: a neutral, always-true sentence stands there instead, never an empty line); body = where the action is about one item, a `bg-subtle` tile that names it (name in semibold, one
  muted 13 px line: a report, a lomba, a publication), then one sentence on what happens and, where true, that it
  cannot be undone (a bulk action states its counts instead); footer = a quiet "Batal" ("Kembali" when the action itself
  is called "Batalkan …") and the action button, which repeats the verb of the button that opened the dialog
  ("Tolak" → "Tolak laporan"; `danger` for a destructive action). Every dialog has its description on first render.

## 5. Sampul (covers) — new component `<x-sth.sampul>`

Every lomba has a cover, in this order:

1. **Its own poster** when Kesiswaan uploaded one ("Sampul" in Kelola lomba, JPG/PNG/WEBP ≤ 3 MB;
   `competitions.cover_file_id`). Served by `files.cover` (D-118) as a small JPEG, drawn with `<img>`, never
   an inline background; when the file cannot be loaded, `app.js` removes the image and the template
   drawing shows.
2. Otherwise a **template drawing of its bidang**: 3 per bidang (Sains: kisi molekul, orbit, gelombang ·
   Riset: grafik, sebar data, jaringan · Seni Budaya: kawung, parang, gringsing · Olahraga: lapangan,
   lintasan, jaring). Plain lines and shapes, no gradient, our own SVG files in `resources/images/sampul`.
   The variant comes from the lomba id (`App\Support\Sampul::variant()`), so one lomba always shows the same
   drawing and the lomba of one bidang do not all look alike.

Two styles of each drawing: **terang** (light; cards, many on a page) and **pekat** (deep; the drawing of a
detail page, `tone="pekat"`).

**The cover stands on the left, portrait, the text beside it** (the lead's choice after seeing the first build,
which showed a portrait poster small in the middle of a wide band). Three shapes, `bentuk` of `<x-sth.sampul>`:

| bentuk | where | what it does |
|---|---|---|
| `tegak` | the lomba card, a "capsule" (`<x-sth.lomba-card>`: Cari lomba, Beranda, Guru › Lomba) and the prestasi cards of Portofolio | one box, always 3:4, 104 px wide on a card under 440 px and 148 px from there; the poster fills it (a little of its sides or bottom may be cut) |
| `utuh` | the head of a lomba page (`<x-sth.lomba-head>`: Detail lomba, Undangan tim) | the poster whole, at its own proportions (104 / 168 / 216 px wide by the width of the card); "Perbesar" under it opens the file in the preview popup |
| `lebar` | a band on a small card: the public "Prestasi terbaru" cards, the Humas queue (template drawings only) | 56–64 px high; a poster would show whole in the middle over a blurred copy of itself |

A template drawing is 360 × 160 with its subject on the right; a portrait box shows a part about 120 wide, and
each drawing says which (`background-position` per class in `app.css`). Public pages and Humas show the template
drawing only (the poster route needs a login, D-118). The upload field in Kelola lomba says a portrait poster
(A4 or 4:5) fits best and shows the card's own box.

**Capsule**: poster left; right of it status, title, bidang / tingkat / peserta tags, penyelenggara, date and
place; under both a line with the registered students and "Lihat detail" (stretched over the card, so the whole
capsule opens the lomba). A grid of capsules takes its columns from its own width, so a card never gets much
narrower than a phone: `grid-cols-1 @min-[900px]:grid-cols-2 @min-[1360px]:grid-cols-3` inside an `@container`.
**Head of a lomba page**: poster left; beside it status, name, penyelenggara, who registered, then the key facts
(`<x-sth.facts plain>`); on a phone the facts run under the poster. Everything else of the page stays below.

Small bidang surfaces (icon tiles in lists, `.cover-<bidang>`) are one flat colour.

## 6. Spanduk (banner) — class `.spanduk`

The student home banner ("Halo, Nadia"), the hero of the public home and the brand panel of the login page are
ink navy with one motif, **lintasan**: arcs with six dots = the six steps of Jejak Prestasi (four done in gold,
the current one ringed, the next one open). On narrow banners the dots are left out so none sits under the text
(`motif/lintasan-polos.svg`). `.spanduk-tinggi` = the tall auth panel. **Nothing floats on or beside a banner**:
the sample cards of v2 (login panel, public home) are gone; a banner holds its text, at most one button, and the
motif.

## 7. What must not come back

Gradient covers and the page wash, `shadow-primary` glows, tilted (`rotate-*`) or blurred decoration, a card
floating beside a hero headline, pill tags, a second typeface, `text-[Npx]` sizes and anything below 13 px, an
"eyebrow pill" above a headline, the "Cari lomba" button above the student menu or on the home banner, a menu
item repeated as a button on the same screen, "Lombaku" / "Profil" as tab names, "kamu" on a staff screen, a
portrait poster shown small in the middle of a wide band on a lomba or prestasi card, the shield icon as the
brand mark. Since EM2 (10 Oct 2026): the browser's own confirm box (`wire:confirm`) for any action (§4); in a mail
(§9): the framework's default look, an image, a second link, a sign-off line, a greeting built from the first word of
a name ("Halo Drs.,") or "Halo!".

## 8. Checking a screen

`/_dev/components` (local) shows every component, the 12 cover drawings and the type scale. A screen PR
still includes screenshots at 1440 and 390 px. Things worth measuring on a new screen: no sideways scroll at
390 px, no computed font size below 13 px, one font family, no `background-image: …gradient`. Also look at it
at **1366 / 1280 px** (the school's laptops: a column next to a side panel gets narrow) and at **360 px**
(small Android phones): no text may touch or cover other text, be cut without "…", or leave its box. A box
whose width depends on a side panel takes its columns from a container query (`@container` +
`@min-[…]:grid-cols-n`), not from the window width.

## 9. Email (EM2, 10 Oct 2026; D-129, *sementara*)

Every mail STH sends looks like the app: the same ink and the same type scale, on one card. Files: the notification
view `resources/views/vendor/notifications/email.blade.php`, its components in `resources/views/vendor/mail/{html,text}`
and the theme `resources/views/vendor/mail/html/themes/sth.css` (`config/mail.php`: `markdown.theme` = `sth`). The
OTP mail (`resources/views/auth/mail/otp-code.blade.php`, plain Blade with inline styles) repeats the theme's values
by hand: change both together. D-129 is *sementara*: the lead has not seen a real mail yet (the coordinator looked at
two sample mails in Mailpit on 10 Oct 2026, the HTML card and the text part; a phone, Gmail in dark mode and Outlook
desktop were not tried), and the greeting rule, the text part, the single link and the footer
sentence are reviewed together with the look when the lead does.

**Tokens used by `sth.css`** (the values of §3, written as hex because a mail has no stylesheet of ours):

| token | value | where |
|---|---|---|
| `page` | `#F3F5F8` | the background around the card |
| `surface` | `#FFFFFF` | the card |
| `line` | `#DDE2E9` | the 1 px border of the card, the line under the header and above the fallback address |
| `ink` | `#141A2B` | greeting and body text, the address under the button, the OTP code |
| `ink-2` | `#3A4254` | the two small sentences under the code in the OTP mail |
| `muted` | `#566072` | the school line of the header, the fallback address sentence, the footer |
| `primary` | `#1B2A4E` | the product name in the header, the button |
| `link` | `#1C4A9C` | the colour of `a` (the theme sets it; a mail has no text link today) |
| `green` / `red` | `#1B6B45` / `#B42318` | the button of a mail whose level is `success` / `error` |

**Sizes** (px; nothing under 13): product name 16/700 · school line 13 · greeting (`h1`) 18/600, line height 1.4 ·
body text and lists 16, line height 1.5 · button 15/600 · fallback address sentence 14 · footer 13, centred · the
OTP code 32/700 with 8 px letter spacing. Font stack: `'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI',
Roboto, Helvetica, Arial, sans-serif`; no web font is loaded, so Public Sans shows only where it is installed.

**Layout**: page padding 24 px above, 12 px at the sides. One card, 570 px wide, radius 10, 1 px `line` border, no
shadow. Inside it the header (padding 20 / 32 / 16, a line below) and the content (padding 24 / 32). The footer
sentence stands under the card, not in it. Under 600 px the card and the footer take the full width with 20 px side
padding; under 500 px the button takes the full width. `color-scheme: light` (meta), as the app has no dark theme.

**Parts, top to bottom**
1. **Header**: two lines of text, "SMAGA Talent Hub" and "SMA Negeri 3 Semarang". No link, no image, no crest (the
   brand mark of D-120 is a raster file and a mail carries no image).
2. **Greeting**, one rule for every mail (`App\Notifications\Support\MailGreeting`): a student "Halo <nama depan>,"
   (the first word of the name that is not an abbreviation, `App\Support\FirstName`), staff and an account without a
   role "Yth. <nama lengkap>,", an unknown recipient "Halo,". Never "kamu" in a staff mail (D-119).
3. **Lines**: short sentences, one fact per line where a notice lists changes.
4. **Button**, at most one per mail and its only link: ink `#1B2A4E` with white text, radius 6, 15/600, 45 px high
   (21 px line + 12 px above and below; at least 44), 20 px at the sides, left-aligned; the label is a verb ("Lihat
   pendaftaran", "Buka publikasi"). A mail without an action has no link at all; the OTP mail never has one.
5. **Fallback address** under a line, only in the HTML part and only with a button: `Tombol "<label>" tidak bisa
   dibuka? Salin alamat ini ke browser:` and the address as text (it may break anywhere), never a second link.
6. **Footer**, one sentence for every mail (`PlainMailMessage::FOOTER`): "Email otomatis dari SMAGA Talent Hub, SMA
   Negeri 3 Semarang. Jangan balas email ini." No sign-off line, no copyright line.

A hidden **inbox preview line** carries the first sentence of the mail (in the OTP mail the intro sentence, never the
code). The **text part** is plain text: greeting, lines, `Label: address`, a line `--`, the footer sentence; no `#`,
no `[address](address)`, no header line.

**Not in a mail**: an image, a web font or any other remote file, a gradient, a shadow, a second
link, an emoji, text under 13 px. The theme also styles the Markdown components `panel` and `table`; no notification
uses them yet.

**Editing a mail view**: the notification view is read as Markdown, so keep every line flush left and leave no blank
line inside an HTML block. After a change run `reload.bat` and restart `queue`. `MailTemplateTest` checks the rules
above on the rendered mail (one `<a>`, no `<img>`, the colours, the smallest font size, the text part).
