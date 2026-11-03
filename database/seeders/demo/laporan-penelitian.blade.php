{{--
    Sample school research report, portrait A4, margins 4-3-3-3 cm, Times 12 pt with 1.5 spacing (LOCAL demo data
    only; rendered by Database\Seeders\Support\Demo\DemoPdfRenderer). Text and numbers come from
    Database\Seeders\Support\Demo\DemoResearchReport: our own wording, sample data, invented references.
    The renderer runs twice: the first pass finds the page of every element with data-toc, the second prints
    those page numbers in the table of contents ($toc). Page numbers at the foot are drawn by the renderer.
    Vars: $title · $competition · $authors · $mentor · $principal · $school · $city · $year · $dateText · $field ·
          $abstract · $keywords · $preface · $chapters · $references · $toc · $img (sandbox paths) · $contoh
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan penelitian: {{ $title }}</title>
    <style>
        @page { margin: 3cm 3cm 3cm 4cm; }
        body { margin: 0; font-family: Times, serif; font-size: 12pt; line-height: 18pt; color: #000; }
        div { margin: 0; padding: 0; }
        p { margin: 0; text-align: justify; text-indent: 1.25cm; }
        table { border-collapse: collapse; }
        .contoh { position: fixed; left: 0; right: 0; bottom: -2.25cm; text-align: center; font-family: Helvetica, Arial, sans-serif; font-size: 7pt; line-height: 9pt; color: #555; }
        .baru { page-break-before: always; }
        .tengah { text-align: center; }
        .tebal { font-weight: bold; }

        .sampul { text-align: center; }
        .sampul .judul { font-size: 16pt; line-height: 22pt; font-weight: bold; }
        .sampul .jenis { font-size: 14pt; font-weight: bold; margin-top: 10mm; letter-spacing: 1pt; }
        .sampul .untuk { margin-top: 6mm; }
        .sampul img { width: 34mm; height: 34mm; margin-top: 15mm; }
        .sampul .oleh { margin-top: 15mm; }
        .sampul .pembimbing { margin-top: 8mm; }
        .sampul .sekolah { margin-top: 20mm; font-size: 14pt; line-height: 20pt; font-weight: bold; }

        .kepala { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 9mm; }
        .data { width: 100%; }
        .data td { vertical-align: top; padding: 1mm 0; }
        .data .k { width: 42mm; }
        .data .s { width: 5mm; }
        .ttd { width: 100%; margin-top: 10mm; }
        .ttd td { width: 50%; text-align: center; vertical-align: top; }
        .ttd img { width: 38mm; height: 12.7mm; }
        .ttd .nm { font-weight: bold; text-decoration: underline; }
        .mengetahui { text-align: center; margin-top: 8mm; }
        .mengetahui img { width: 38mm; height: 12.7mm; }

        .abstrak-judul { text-align: center; font-weight: bold; }
        .abstrak-penulis { text-align: center; margin-bottom: 6mm; }
        .kunci { margin-top: 5mm; text-align: justify; }
        .kanan { margin-top: 10mm; margin-left: 85mm; text-align: center; }

        .isi { width: 100%; }
        .isi td { vertical-align: top; padding: 0.2mm 0; line-height: 16pt; border-bottom: 0.5pt dotted #999; }
        .isi .hal { width: 14mm; text-align: right; }
        .isi .anak { padding-left: 9mm; }

        .bab { text-align: center; font-size: 14pt; line-height: 20pt; font-weight: bold; margin-bottom: 8mm; }
        .sub { font-weight: bold; margin-top: 5mm; margin-bottom: 1mm; page-break-after: avoid; }
        ol, ul { margin: 0 0 0 7mm; padding: 0; }
        li { text-align: justify; padding-left: 1mm; }
        .tabel { margin: 4mm 0 4mm; page-break-inside: avoid; }
        .tabel .ket { text-align: center; margin-bottom: 1.5mm; }
        .tabel table { width: 100%; }
        .tabel th, .tabel td { border: 0.75pt solid #000; padding: 1.2mm 1.5mm; font-size: 11pt; line-height: 14pt; text-align: center; vertical-align: middle; }
        .tabel th { font-weight: bold; background-color: #eeeeee; }
        .tabel .catatan { font-size: 10pt; line-height: 13pt; font-style: italic; margin-top: 1.2mm; }
        .pustaka { text-align: justify; padding-left: 1.25cm; text-indent: -1.25cm; margin-bottom: 3mm; }
        .catatan-pustaka { font-size: 10pt; line-height: 13pt; font-style: italic; margin-bottom: 5mm; }
    </style>
</head>
<body>
    <div class="contoh">{{ $contoh }}</div>

    {{-- Sampul --}}
    <div class="sampul">
        <div class="judul">{{ mb_strtoupper($title) }}</div>
        <div class="jenis">LAPORAN PENELITIAN</div>
        <div class="untuk">Diajukan untuk mengikuti<br>{{ $competition }}</div>
        <div><img src="{{ $img['lambang.svg'] }}" alt=""></div>
        <div class="oleh">Disusun oleh:</div>
        @foreach ($authors as $author)
            <div>{{ $author['name'] }} (NIS {{ $author['nis'] }})</div>
        @endforeach
        <div class="pembimbing">Guru pembimbing:</div>
        <div>{{ $mentor }}</div>
        <div class="sekolah">{{ mb_strtoupper($school) }}<br>KOTA {{ mb_strtoupper($city) }}<br>{{ $year }}</div>
    </div>

    {{-- Lembar pengesahan --}}
    <div class="baru kepala" data-toc="pengesahan">LEMBAR PENGESAHAN</div>
    <table class="data">
        <tr><td class="k">Judul penelitian</td><td class="s">:</td><td>{{ $title }}</td></tr>
        <tr><td class="k">Bidang</td><td class="s">:</td><td>{{ $field }}</td></tr>
        <tr>
            <td class="k">Penyusun</td><td class="s">:</td>
            <td>
                @foreach ($authors as $author)
                    <div>{{ $author['name'] }} · NIS {{ $author['nis'] }} · kelas {{ $author['class'] }}</div>
                @endforeach
            </td>
        </tr>
        <tr><td class="k">Sekolah</td><td class="s">:</td><td>{{ $school }}</td></tr>
        <tr><td class="k">Guru pembimbing</td><td class="s">:</td><td>{{ $mentor }}</td></tr>
        <tr><td class="k">Diajukan untuk</td><td class="s">:</td><td>{{ $competition }}</td></tr>
    </table>
    <p style="margin-top: 7mm;">Laporan penelitian ini telah diperiksa dan disetujui oleh guru pembimbing untuk diajukan pada {{ $competition }}.</p>
    <div class="kanan">{{ $dateText }}</div>
    <table class="ttd">
        <tr>
            <td>
                <div>Guru Pembimbing,</div>
                <div><img src="{{ $img['ttd-1.svg'] }}" alt=""></div>
                <div class="nm">{{ $mentor }}</div>
            </td>
            <td>
                <div>{{ count($authors) > 1 ? 'Ketua Tim,' : 'Penyusun,' }}</div>
                <div><img src="{{ $img['ttd-2.svg'] }}" alt=""></div>
                <div class="nm">{{ $authors[0]['name'] }}</div>
            </td>
        </tr>
    </table>
    <div class="mengetahui">
        <div>Mengetahui,</div>
        <div>Kepala {{ $school }}</div>
        <div><img src="{{ $img['ttd-3.svg'] }}" alt=""></div>
        <div class="tebal">{{ $principal }}</div>
    </div>

    {{-- Abstrak --}}
    <div class="baru kepala" data-toc="abstrak">ABSTRAK</div>
    <div class="abstrak-judul">{{ $title }}</div>
    <div class="abstrak-penulis">{{ implode(', ', array_column($authors, 'name')) }} · {{ $school }}</div>
    @foreach ($abstract as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
    <div class="kunci"><span class="tebal">Kata kunci:</span> {{ $keywords }}</div>

    {{-- Kata pengantar --}}
    <div class="baru kepala" data-toc="pengantar">KATA PENGANTAR</div>
    @foreach ($preface as $paragraph)
        <p>{{ $paragraph }}</p>
    @endforeach
    <div class="kanan">
        <div>{{ $dateText }}</div>
        <div>Penulis</div>
    </div>

    {{-- Daftar isi --}}
    <div class="baru kepala" data-toc="isi">DAFTAR ISI</div>
    <table class="isi">
        @foreach ([['pengesahan', 'LEMBAR PENGESAHAN'], ['abstrak', 'ABSTRAK'], ['pengantar', 'KATA PENGANTAR'], ['isi', 'DAFTAR ISI']] as [$key, $label])
            <tr><td>{{ $label }}</td><td class="hal">{{ $toc[$key] ?? '' }}</td></tr>
        @endforeach
        @foreach ($chapters as $chapter)
            <tr><td>{{ $chapter['number'] }} {{ $chapter['title'] }}</td><td class="hal">{{ $toc[$chapter['key']] ?? '' }}</td></tr>
            @foreach ($chapter['sections'] as $section)
                <tr><td class="anak">{{ $section['number'] }} {{ $section['title'] }}</td><td class="hal">{{ $toc[$section['key']] ?? '' }}</td></tr>
            @endforeach
        @endforeach
        <tr><td>DAFTAR PUSTAKA</td><td class="hal">{{ $toc['pustaka'] ?? '' }}</td></tr>
    </table>

    {{-- Bab --}}
    @foreach ($chapters as $chapter)
        <div class="baru bab" data-toc="{{ $chapter['key'] }}">{{ $chapter['number'] }}<br>{{ $chapter['title'] }}</div>
        @foreach ($chapter['sections'] as $section)
            <div class="sub" data-toc="{{ $section['key'] }}">{{ $section['number'] }} {{ $section['title'] }}</div>
            @foreach ($section['blocks'] as [$type, $value])
                @if ($type === 'p')
                    <p>{{ $value }}</p>
                @elseif ($type === 'ol')
                    <ol>
                        @foreach ($value as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ol>
                @elseif ($type === 'ul')
                    <ul>
                        @foreach ($value as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                @elseif ($type === 'table')
                    <div class="tabel">
                        <div class="ket">{{ $value['caption'] }}</div>
                        <table>
                            <tr>
                                @foreach ($value['head'] as $cell)
                                    <th>{{ $cell }}</th>
                                @endforeach
                            </tr>
                            @foreach ($value['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                        @if (! empty($value['note']))
                            <div class="catatan">{{ $value['note'] }}</div>
                        @endif
                    </div>
                @endif
            @endforeach
        @endforeach
    @endforeach

    {{-- Daftar pustaka --}}
    <div class="baru kepala" data-toc="pustaka">DAFTAR PUSTAKA</div>
    <div class="catatan-pustaka">Catatan: daftar pustaka ini contoh untuk data uji. Penulis, judul, dan penerbitnya rekaan.</div>
    @foreach ($references as $reference)
        <div class="pustaka">{{ $reference }}</div>
    @endforeach
</body>
</html>
