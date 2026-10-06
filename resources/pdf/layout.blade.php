{{--
    Base of both surat tugas PDFs (rendered by App\Domain\Letters\PdfRenderer with dompdf: no remote, no JS,
    no PHP, file:// inside resources/pdf + the render sandbox only). Data is printed with {{ }} only.

    M1-A (lead, 2026-10-04): both letters follow the school's own files measure by measure.
    - guru  = Word file "ST PENGUKUHAN KEG OPSI": A4, margins top/bottom 306, left 1418, right 1134 twips,
              Arial 12 pt (lists under "Dasar" and "Kepada" 11 pt), line pitch 1.08;
    - siswa = Excel sheet "ST_LOMBA … TIM": A4, margins left/right 0.55 in, top/bottom 0.79 in, Arial 11 pt
              (title 12 pt), rows 15.95 pt, the sheet's column widths (469.5 pt in all).
    dompdf has no Arial: Helvetica is the core font with the same metrics (nothing to embed).
    All lengths are pt and every block has its own left offset + width, so nothing depends on dompdf's
    handling of negative margins. The guru page is laid out from the left edge of the Pemprov logo, which sits
    29.85 pt left of the text edge in the Word file (hence the 41.05 pt page margin and the 29.85 pt offsets).

    Vars: $title · $letter ('siswa' | 'guru') · $verification (['code' => …, 'url' => …])
          · $pdfImages['qr.png'] (sandbox path) · $replaces (optional, B3: number of the revoked letter this one replaces)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: {{ $letter === 'guru' ? '15.3pt 24.5pt 15.3pt 41.05pt' : '56.7pt 39.7pt 56.7pt 39.7pt' }}; }
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

        .kop-guru { width: 529.8pt; }
        .kop-guru .kop-kiri { width: 79.3pt; padding-top: 8.5pt; }
        .kop-guru .kop-kanan { width: 64pt; padding-top: 11.05pt; }
        .kop-guru .kop-teks { padding-top: 13.8pt; padding-left: 4pt; }
        .kop-guru img { height: 85.05pt; }
        .kop-garis-guru { margin-left: 23.65pt; width: 473.75pt; margin-top: 2pt; }

        /* The sheet carries the kop as one picture, 473 pt wide: the same kop, smaller, logos inside the rule. */
        .kop-siswa { width: 473pt; }
        .kop-siswa .kop-kiri { width: 66pt; padding-top: 11pt; }
        .kop-siswa .kop-kanan { width: 54pt; padding-top: 11pt; }
        .kop-siswa .kop-teks { padding-top: 11pt; }
        .kop-siswa .kop-1 { font-size: 11pt; line-height: 12.65pt; }
        .kop-siswa .kop-2, .kop-siswa .kop-3 { font-size: 13pt; line-height: 14.95pt; }
        .kop-siswa .kop-4 { font-size: 8.2pt; line-height: 9.4pt; }
        .kop-siswa img { height: 70pt; }
        .kop-garis-siswa { width: 473pt; margin-top: 8pt; }

        /* Surat Perintah Tugas (guru). */
        .g-judul { margin-left: 29.85pt; width: 467.75pt; text-align: center; }
        .g-jarak-kop { height: 57.5pt; }
        .g-baris { height: 14.9pt; }
        .g-baris-kecil { height: 12.4pt; }
        .g-blok { margin-left: 50.9pt; }
        .g-blok td { padding: 0 5.4pt; }
        .g-blok .k-dasar { width: 46.15pt; }
        .g-blok .k-untuk { width: 45.9pt; }
        .g-blok .s { width: 7.2pt; text-align: center; }
        .g-blok .isi { width: 385.55pt; padding-left: 3.05pt; padding-right: 8.3pt; }
        .g-daftar { width: 100%; }
        .g-daftar td { padding: 0; }
        .g-daftar .n { width: 18pt; }
        .g-11, .g-11 td { font-size: 11pt; line-height: 13.65pt; }
        .g-perintah { margin-left: 59.6pt; width: 438pt; text-align: center; padding-bottom: 6pt; }
        .g-kepada { margin-left: 51.15pt; }
        .g-kepada td { height: 14.9pt; }
        .g-kepada .k { width: 49.65pt; padding-left: 5.4pt; }
        .g-kepada .s { width: 14.2pt; text-align: center; }
        .g-kepada .n { width: 18pt; padding-left: 3.65pt; }
        .g-kepada .label { width: 71.45pt; }
        .g-kepada .n, .g-kepada .label { font-size: 11pt; }
        .g-kepada .titik { width: 7.55pt; padding-left: 5.4pt; }
        .g-kepada .kurung { width: 25.75pt; padding-top: 4pt; font-size: 0; line-height: 0; }
        .g-kepada .kurung img { display: block; width: 15.75pt; height: 50pt; }
        .g-kepada .lampir { width: 120pt; }
        .g-kepada-akhir { height: 6pt; }
        .g-ttd { margin-left: 277.95pt; width: 240pt; }
        .g-ttd .gambar { height: 58pt; }
        .g-ttd .gambar img { height: 58pt; }
        .g-lampiran { margin-left: 29.85pt; }
        .g-lampiran-atas { height: 44.7pt; }
        .g-lampiran-kepala .k { width: 72pt; }
        .g-lampiran-judul { width: 438.25pt; text-align: center; }
        .g-pendamping { margin-left: 50.9pt; }
        .g-pendamping td { padding: 0 5.4pt; }
        .g-pendamping .no { width: 36.2pt; }
        .g-pendamping .label { width: 95.55pt; }
        .g-pendamping .nilai { width: 261.35pt; }
        .g-ttd-lampiran { margin-left: 281.85pt; width: 240pt; }

        /* Surat Tugas (siswa). */
        .siswa { font-size: 11pt; line-height: 15.95pt; }
        .s-lebar { width: 469.5pt; }
        .s-jarak-kop { height: 17.5pt; }
        .s-judul { width: 469.5pt; text-align: center; font-size: 12pt; }
        .s-baris { height: 15.95pt; }
        .s-baris-15 { height: 15pt; }
        .s-dasar { width: 469.5pt; }
        .s-dasar td { line-height: 15pt; }
        .s-dasar .k { width: 95.25pt; }
        .s-dasar .s { width: 9pt; text-align: center; }
        .s-murid { width: 469.5pt; }
        .s-murid th, .s-murid td { border: 0.5pt solid #000; height: 20.1pt; line-height: 13pt; vertical-align: middle; text-align: center; padding: 0 1.5pt; }
        .s-murid th { font-weight: bold; }
        .s-murid .no { width: 21.75pt; }
        .s-murid .nama { text-align: left; }
        .s-murid .nis { width: 69pt; }
        .s-murid .kelas { width: 74.25pt; }
        .s-pelaksanaan { margin-left: 24.75pt; width: 444.75pt; }
        .s-pelaksanaan .k { width: 70.5pt; }
        .s-pelaksanaan .s { width: 9pt; text-align: center; }
        .s-pelaksanaan .awal { width: 103.5pt; }
        .s-pelaksanaan .sd { width: 24.75pt; text-align: center; }
        .s-ttd { margin-left: 243pt; width: 226.5pt; text-align: center; }
        .s-ttd .gambar { height: 60.95pt; }
        .s-ttd .gambar img { height: 60.95pt; }
        .s-ttd .nama { font-weight: bold; text-decoration: underline; }

        /* Verification QR + code: small, bottom-left, on every page. */
        .verif { position: fixed; left: 0; font-size: 6pt; line-height: 7.2pt; color: #333; }
        .verif-guru { bottom: 0; left: 29.85pt; }
        .verif-siswa { bottom: -42.7pt; }
        .verif td { vertical-align: middle; }
        .verif .qr { width: 50pt; }
        .verif .qr img { width: 46pt; height: 46pt; }
        .verif .teks { width: 280pt; }
        /* Guru letter: the block shares the last lines of the page with the signature on the right, so it stays left of it. */
        .verif-guru .teks { width: 190pt; word-wrap: break-word; }
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
