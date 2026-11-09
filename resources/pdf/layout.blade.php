{{--
    Base of both surat tugas PDFs (rendered by App\Domain\Letters\PdfRenderer with dompdf: no remote, no JS,
    no PHP, file:// inside resources/pdf + the render sandbox only). Data is printed with {{ }} only.

    M1-A (lead, 2026-10-04): both letters follow the school's own files measure by measure.
    - guru  = since SV8, D-194 (lead, 2026-11-09: "samakan persis seperti template pdf nya harus identik 100%") the
              school's PDF letter of that day, measured line by line (pdfplumber): A4, one page, no Lampiran, Arial 12 pt
              (the kop 12 / 14 bold / 9), line pitch 14.89 pt (the "Kepada" rows 13.8 pt). The page margin is the left
              emblem's corner (55.2 pt from the left, 23.8 pt from the top); every block has its own offset from it,
              taken from the PDF (.kop-g, .g-*). The M1-A Word file "ST PENGUKUHAN KEG OPSI" (with Lampiran) is only
              left in the TU preview of the letters printed with it (LetterTemplate::GURU_LAYOUT_LAMPIRAN);
    - siswa = Excel sheet "ST_LOMBA … TIM": A4, top/bottom 0.79 in, Arial 11 pt (title 12 pt), rows 15.95 pt, the
              sheet's column widths. The sheet itself stands at the left margin (0.55 in) and leaves a wider right
              margin; since RV1 (lead, 2026-10-11) the letter is centred on the page instead: every block is as wide
              as the kop picture (473 pt) and the left and right margins are equal, (595.28 - 473) / 2 = 61.14 pt.
    dompdf has no Arial: Helvetica is the core font with the same metrics (nothing to embed).
    All lengths are pt and every block has its own left offset + width, so nothing depends on dompdf's
    handling of negative margins.
    SV6, D-183 (lead, 2026-11-05: "Stempel tanda tangan … dibesarkan sedikit, nabrak atau kena tulisan gpp"), made
    bigger on 5 Nov 2026 (SV7, D-189: "tanda tangan di PDF surat tugas tolong dibesarkan, kena kata-kata tidak apa-apa")
    and a little smaller and further left again on 9 Nov 2026 (SV8, D-194: "tanda tangan kebesaran, dikecilin sedikit.
    Ttd sama stempelnya juga digeser agak ke kiri"): the signature + stamp image is 80 % of the SV7 size (student letter
    121.9 → 97.52 pt, guru letter 116 → 92.8 pt), taller than its reserved box (.gambar) and pulled up by half the
    difference with position: relative, so it overlaps the line above and the name below equally, and 17 pt to the left
    of where SV7 put it in its block. The boxes keep the letters' heights (the guru letter's is the three empty lines
    of the school's signature space): no line moves and the page count stays. Paint order: the image covers the line
    above, the name is drawn over the image (a stamp image needs a transparent background). Width follows the image's
    ratio; at 2:1 it fits the block, a much wider image runs into the right margin.

    Vars: $title · $letter ('siswa' | 'guru') · $verification (['code' => …, 'url' => …])
          · $pdfImages['qr.png'] (sandbox path) · $replaces (optional, B3: number of the revoked letter this one replaces)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: {{ $letter === 'guru' ? '23.8pt 17.83pt 15.3pt 55.2pt' : '56.7pt 61.14pt 56.7pt 61.14pt' }}; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 12pt; line-height: 14.9pt; color: #000; }
        p, div { margin: 0; padding: 0; }
        table { border-collapse: collapse; border-spacing: 0; }
        td, th { padding: 0; vertical-align: top; text-align: left; font-weight: normal; }
        .tengah { text-align: center; }
        .rata { text-align: justify; }
        .tebal { font-weight: bold; }
        .garis-bawah { text-decoration: underline; }
        .halaman-baru { page-break-before: always; }
        .utuh { page-break-inside: avoid; }

        /* Letterhead: Pemprov logo | text | school logo, then a thick + thin rule. */
        .kop td { vertical-align: top; }
        .kop .kop-teks { text-align: center; }
        .kop .kop-1 { font-size: 12pt; line-height: 13.8pt; }
        .kop .kop-2, .kop .kop-3 { font-size: 14pt; line-height: 16.1pt; font-weight: bold; }
        .kop .kop-4 { font-size: 9pt; line-height: 10.35pt; }
        .kop .kop-kanan { text-align: right; }
        .garis-tebal { border-top: 2.7pt solid #000; height: 0; font-size: 0; line-height: 0; }
        .garis-tipis { border-top: 0.9pt solid #000; height: 0; font-size: 0; line-height: 0; margin-top: 0.9pt; }

        /* SV8, D-194: the guru kop of the school's PDF (partials/kop-guru). Offsets from the page margin = the left emblem's
           corner (55.2 pt, 23.8 pt): Pemprov emblem 81.7 x 85.05 pt, school emblem 63.85 x 85.05 pt at x 513.6 pt and
           2.55 pt lower; the lines centred on x 322.53 pt in a box wider than the gap between the emblems (the address
           line runs to their edges), first line 30.72 pt from the top, 14 pt lines 14.05 and 16.2 pt under it, the
           9 pt lines 16.34 pt under that and 10.4 pt apart; the web address host blue and underlined (0.6 pt); the rule
           (2.7 pt, 0.9 pt gap, 0.9 pt; slightly tilted on the paper, here level in the middle) from x 78.85 to 552.6 pt
           at 114.99 pt. dompdf makes a Helvetica line 1.0175 times its line-height (font height 0.925 x the font height
           ratio 1.1), so every guru line-height is the PDF's line pitch / 1.0175 (14.89 → 14.634 pt). */
        .kop-g { position: relative; height: 91.19pt; }
        .kop-g img { position: absolute; height: 85.05pt; }
        .kop-g .kiri { left: 0; top: 0; width: 81.7pt; }
        .kop-g .kanan { left: 458.4pt; top: 2.55pt; width: 63.85pt; }
        /* positioned too, so the text is drawn over the emblems ("Jalan" starts over the Pemprov emblem, as on the paper) */
        .kop-g .kop-teks { position: relative; margin-left: 62.33pt; width: 410pt; padding-top: 5.485pt; text-align: center; }
        .kop-g .kop-1 { font-size: 12pt; line-height: 13.425pt; }
        .kop-g .kop-2, .kop-g .kop-3 { font-size: 14pt; line-height: 15.921pt; font-weight: bold; }
        .kop-g .kop-rinci { padding-top: 0.84pt; }
        .kop-g .kop-4 { font-size: 9pt; line-height: 10.221pt; }
        .kop-g .tautan { color: #00f; border-bottom: 0.6pt solid #00f; }
        .kop-garis-g { margin-left: 23.65pt; width: 473.75pt; }

        /* The sheet carries the kop as one picture, 473 pt wide: the same kop, smaller, logos inside the rule.
           473 pt is also the width of the page between the margins, so the kop and every block below it are centred.
           D-154 (the school's printed letter): the emblems are 75 pt high and start above the first line, the longest
           address line (344.6 pt at 8.2 pt) still fits between them (473 - 70 - 56.5 = 346.5 pt). */
        .kop-siswa { width: 473pt; }
        .kop-siswa .kop-kiri { width: 70pt; padding-top: 3pt; }
        .kop-siswa .kop-kanan { width: 56.5pt; padding-top: 3pt; }
        .kop-siswa .kop-teks { padding-top: 11pt; }
        .kop-siswa .kop-1 { font-size: 11pt; line-height: 12.65pt; }
        .kop-siswa .kop-2, .kop-siswa .kop-3 { font-size: 13pt; line-height: 14.95pt; }
        .kop-siswa .kop-4 { font-size: 8.2pt; line-height: 9.4pt; }
        .kop-siswa img { height: 75pt; }
        .kop-garis-siswa { width: 473pt; margin-top: 8pt; }

        /* Surat Perintah Tugas (guru), SV8, D-194: the school's PDF of 2026-11-09 (partials/guru-langsung). x of the PDF
           minus the 55.2 pt page margin; the spacers (.g-jarak-*) put each block's first line where the PDF has it.
           "Dasar" and "Untuk": label x 90.42, colon 147.85, number 163.05, text 181.05 pt, lines set by
           App\Domain\Letters\JustifiedLines (partials/guru-paragraf: one block per line that never wraps again) and
           ending at 548.46 pt (the school's lines end one space before the 551.8 pt edge), "Untuk" 0.2 pt further
           left as in the PDF, lines 14.89 pt apart. "Kepada":
           label 85.03, colon 164.65, "1." 178.85, Nama / Pangkat/Gol / NIP / Jabatan 192.85, colon 270.88, value
           277.7 pt, rows 13.8 pt apart. Titles centred on their own axes (311.13, 308.53, 326.74 pt). Signature at
           333.3 pt, the image in the three empty lines between the signer title and the name. */
        .g-jarak-judul { height: 6.33pt; }
        .g-judul, .g-nomor { margin-left: 35.22pt; text-align: center; line-height: 13.563pt; }
        .g-judul { width: 441.42pt; }
        .g-nomor { width: 436.21pt; }
        .g-jarak-dasar { height: 14.157pt; }
        .g-daftar { margin-left: 35.22pt; line-height: 14.634pt; }
        .g-daftar td { padding: 0; }
        .g-daftar .k { width: 57.43pt; }
        .g-daftar .s { width: 15.2pt; }
        .g-daftar .n { width: 18pt; }
        .g-daftar .isi { width: 367.41pt; }
        .g-daftar .baris { white-space: nowrap; }
        .g-untuk { margin-left: 35.02pt; }
        .g-untuk .isi { width: 367.61pt; }
        .g-jarak-perintah { height: 13.61pt; }
        .g-perintah { margin-left: 35.22pt; width: 472.64pt; text-align: center; line-height: 14.634pt; }
        .g-jarak-kepada { height: 6.973pt; }
        .g-kepada { margin-left: 29.83pt; line-height: 13.563pt; }
        .g-kepada .k { width: 79.62pt; }
        .g-kepada .s { width: 14.2pt; }
        .g-kepada .n { width: 14pt; }
        .g-kepada .label { width: 78.03pt; }
        .g-kepada .titik { width: 6.82pt; }
        .g-kepada .nilai { width: 270.76pt; }
        .g-jarak-untuk { height: 12.93pt; }
        .g-jarak-ttd { height: 14.93pt; }
        .g-ttd { margin-left: 278.1pt; width: 240pt; line-height: 14.634pt; }
        /* SV8, D-194: the box = the three empty lines of the school's signature space; the image 80 % of SV7's 116 pt,
           centred on the box, 17 pt left of the text. */
        .g-ttd .gambar { height: 44.67pt; }
        .g-ttd .gambar img { height: 92.8pt; position: relative; top: -24.065pt; left: -17pt; }

        /* Surat Tugas (siswa). */
        .siswa { font-size: 11pt; line-height: 15.95pt; }
        .s-lebar { width: 473pt; }
        /* D-154: the title stands about half a row under the rule, "Dasar" a little under the Nomor (the school's letter). */
        .s-jarak-kop { height: 9.5pt; }
        .s-judul { width: 473pt; text-align: center; font-size: 12pt; }
        .s-baris { height: 15.95pt; }
        .s-baris-15 { height: 15pt; }
        .s-jarak-dasar { height: 4.5pt; }
        .s-dasar { width: 473pt; }
        .s-dasar td { line-height: 15pt; }
        .s-dasar .k { width: 103pt; }
        .s-dasar .s { width: 9pt; text-align: center; }
        /* D-157, SV8 D-195: more than one Dasar text (the template's, then the invitation) is a numbered list, one row each,
           the text hanging under itself as in the guru letter; the student letter keeps its 11 pt, 15 pt rows and columns. */
        .s-daftar { width: 100%; }
        .s-daftar .n { width: 16pt; }
        /* SV8, D-196 (teams up to 30): the member table may run onto a second page. Its header row repeats there
           (thead), a row never splits (a long name wraps inside its cell, the row grows), the header never stays alone
           at a page end, and the last two rows go with everything under the table (activity, schedule, closing and
           signature, which never split): the next page always starts with the header and rows, never with the
           signature alone. The table itself keeps to the intro above it (a break is looked for between its rows); when
           the whole table has to move (one or two members under a long Dasar list), the intro moves with it. */
        .s-lebar + .s-baris, .s-murid { page-break-before: avoid; }
        .s-murid { width: 473pt; }
        .s-murid thead { display: table-header-group; }
        .s-murid tr { page-break-inside: avoid; }
        .s-murid thead tr, .s-murid tbody tr:nth-last-child(2) { page-break-after: avoid; }
        .s-murid ~ * { page-break-before: avoid; page-break-inside: avoid; }
        .s-murid th, .s-murid td { border: 0.5pt solid #000; height: 20.1pt; line-height: 13pt; vertical-align: middle; text-align: center; padding: 0 1.5pt; }
        .s-murid th { font-weight: bold; }
        .s-murid .no { width: 21.75pt; }
        .s-murid .nama { text-align: left; overflow-wrap: break-word; }
        .s-murid .nis { width: 69pt; }
        .s-murid .kelas { width: 74.25pt; }
        .s-pelaksanaan { margin-left: 24.75pt; width: 448.25pt; }
        .s-pelaksanaan .k { width: 70.5pt; }
        .s-pelaksanaan .s { width: 9pt; text-align: center; }
        .s-pelaksanaan .awal { width: 103.5pt; }
        .s-pelaksanaan .sd { width: 24.75pt; text-align: center; }
        .s-ttd { margin-left: 246.5pt; width: 226.5pt; text-align: center; }
        /* SV8, D-194: the image 80 % of SV7's (D-189: 121.9 pt, twice the box) = 97.52 pt, half of the 36.57 pt it is
           taller than its box above it, and 17 pt to the left of the block's middle. */
        .s-ttd .gambar { height: 60.95pt; }
        .s-ttd .gambar img { height: 97.52pt; position: relative; top: -18.285pt; left: -17pt; }
        .s-ttd .nama { font-weight: bold; text-decoration: underline; }

        /* Verification QR + code: small, bottom-left, on every page. */
        .verif { position: fixed; left: 0; font-size: 6pt; line-height: 7.2pt; color: #333; }
        /* SV8, D-194: under "Kepada" (x 85.03 pt); the one-page guru letter leaves the lines beside it free. */
        .verif-guru { bottom: 0; left: 29.83pt; }
        .verif-siswa { bottom: -42.7pt; }
        .verif td { vertical-align: middle; }
        .verif .qr { width: 50pt; }
        .verif .qr img { width: 46pt; height: 46pt; }
        .verif .teks { width: 280pt; }
    </style>
</head>
<body class="{{ $letter }}">
    <div class="verif verif-{{ $letter }}">
        <table>
            <tr>
                <td class="qr">@isset($pdfImages['qr.png'])<img src="{{ $pdfImages['qr.png'] }}" alt="Kode QR verifikasi">@endisset</td>
                <td class="teks">
                    Keaslian surat ini dapat diperiksa dengan memindai kode QR atau membuka<br>
                    {{ $verification['url'] }}<br>
                    Kode verifikasi: {{ $verification['code'] }}
                    @if (! empty($replaces))<br>Surat ini menggantikan surat nomor {{ $replaces }} yang telah dicabut.@endif
                </td>
            </tr>
        </table>
    </div>

    @yield('content')
</body>
</html>
