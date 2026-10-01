{{--
    Base of every surat tugas PDF (rendered by App\Domain\Letters\PdfRenderer with dompdf: no remote, no JS,
    no PHP, file:// inside the sandbox only). Data is printed with {{ }} only. Core font Times (no font files).
    Vars: $title · $verification (['code' => …, 'url' => …]) · $pdfImages['qr.png'] (sandbox path)
          · $replaces (optional, B3: full number of the revoked letter this version replaces)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 1.6cm 2cm 3.2cm 2cm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.35; color: #000; }
        p { margin: 0 0 8pt 0; }
        table { border-collapse: collapse; }
        .kop { width: 100%; border-bottom: 3pt double #000; margin-bottom: 14pt; }
        .kop td { vertical-align: middle; text-align: center; padding-bottom: 6pt; }
        .kop .logo { width: 62pt; }
        .kop .logo div { width: 56pt; height: 56pt; border: 1pt dashed #777; font-size: 7pt; color: #555; line-height: 1.2; padding-top: 18pt; }
        .kop .l1 { font-size: 12pt; font-weight: bold; }
        .kop .l2 { font-size: 12pt; font-weight: bold; }
        .kop .l3 { font-size: 14pt; font-weight: bold; }
        .kop .l4 { font-size: 10pt; }
        .judul { text-align: center; font-weight: bold; font-size: 13pt; text-decoration: underline; margin: 0; }
        .judul-spasi { letter-spacing: 4pt; }
        .nomor { text-align: center; margin: 2pt 0 14pt 0; }
        .kv td { vertical-align: top; padding: 1pt 0; }
        .kv .k { width: 92pt; }
        .kv .s { width: 12pt; }
        .grid { width: 100%; margin: 6pt 0 10pt 0; }
        .grid th, .grid td { border: 0.8pt solid #000; padding: 3pt 5pt; font-size: 11.5pt; }
        .grid th { text-align: center; font-weight: bold; }
        .grid .c { text-align: center; }
        .justify { text-align: justify; }
        .ttd { width: 100%; margin-top: 16pt; page-break-inside: avoid; }
        .ttd td { vertical-align: top; }
        .ttd .kanan { width: 46%; }
        .ttd .img { height: 78pt; }
        .ttd .img img { height: 78pt; }
        .ttd .nama { font-weight: bold; text-decoration: underline; }
        .verif { position: fixed; bottom: -2.6cm; left: 0; right: 0; height: 2.2cm; font-size: 8pt; color: #333; }
        .verif td { vertical-align: middle; }
        .verif .qr { width: 64pt; }
        .verif .qr img { width: 58pt; height: 58pt; }
        .page-break { page-break-before: always; }
        .ol td { vertical-align: top; padding: 1pt 0; }
        .ol .n { width: 16pt; }
        .mt { margin-top: 10pt; }
        .w-no { width: 26pt; }
        .w-nis { width: 86pt; }
        .w-kelas { width: 70pt; }
        .w-pangkat { width: 90pt; }
        .w-nip { width: 110pt; }
        .w-jabatan { width: 60pt; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
    </style>
</head>
<body>
    <div class="verif">
        <table>
            <tr>
                <td class="qr">@isset($pdfImages['qr.png'])<img src="{{ $pdfImages['qr.png'] }}" alt="Kode QR verifikasi">@endisset</td>
                <td>
                    Keaslian surat ini dapat diperiksa dengan memindai kode QR atau membuka {{ $verification['url'] }}<br>
                    Kode verifikasi: {{ $verification['code'] }}
                    @if (! empty($replaces))<br>Surat ini menggantikan surat nomor {{ $replaces }} yang telah dicabut.@endif
                </td>
            </tr>
        </table>
    </div>

    @yield('content')
</body>
</html>
