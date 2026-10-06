{{--
    Sample "petunjuk teknis" of a lomba, portrait A4, several pages (LOCAL demo data only; rendered by
    Database\Seeders\Support\Demo\DemoPdfRenderer). Text comes from Database\Seeders\Support\Demo\DemoGuideline: our
    own wording, the schedule from the lomba's dates, contact data that is obviously fictional (example.test).
    "Halaman x dari y" is drawn by the renderer.
    Vars: $c (colours) · $name · $organizer · $year · $sections ([title, blocks]; block = ['p', text] |
          ['ol'|'ul', list] | ['table', ['head', 'rows', 'widths']]) · $placeDate · $signer · $img · $contoh
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Petunjuk teknis {{ $name }}</title>
    <style>
        @page { margin: 2.2cm 2.3cm 2.6cm 2.3cm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5pt; line-height: 15.5pt; color: #1c1c1c; }
        div { margin: 0; padding: 0; }
        p { margin: 0 0 2mm; text-align: justify; }
        table { border-collapse: collapse; }
        .contoh { position: fixed; left: 0; right: 0; bottom: -1.9cm; text-align: center; font-size: 7pt; line-height: 9pt; color: #555; }
        .kepala { width: 100%; background-color: {{ $c['primary'] }}; }
        .kepala td { padding: 6mm 6mm 6mm 0; vertical-align: middle; color: #ffffff; }
        .kepala .gambar { width: 30mm; padding: 5mm 0 5mm 6mm; }
        .kepala img { width: 22mm; height: 22mm; }
        .kepala .oleh { font-size: 9pt; letter-spacing: 1.2pt; }
        .kepala .jenis { font-size: 21pt; line-height: 25pt; font-weight: bold; letter-spacing: 1.5pt; margin-top: 1mm; }
        .kepala .nama { font-size: 12.5pt; line-height: 16pt; font-weight: bold; margin-top: 1mm; }
        .pita { height: 1.6mm; background-color: {{ $c['accent'] }}; font-size: 0; line-height: 0; margin-bottom: 6mm; }
        .bagian { font-size: 11.5pt; font-weight: bold; color: {{ $c['dark'] }}; border-bottom: 0.8pt solid {{ $c['accent'] }}; padding-bottom: 0.8mm; margin: 5mm 0 2.2mm; page-break-after: avoid; }
        ol, ul { margin: 0 0 2mm 6mm; padding: 0; }
        li { text-align: justify; padding-left: 1mm; margin-bottom: 0.6mm; }
        .tabel { width: 100%; margin: 1mm 0 3mm; page-break-inside: avoid; }
        .tabel th, .tabel td { border: 0.6pt solid #777; padding: 1.4mm 1.8mm; vertical-align: top; text-align: left; font-size: 10pt; line-height: 14pt; }
        .tabel th { background-color: {{ $c['soft'] }}; color: {{ $c['dark'] }}; font-weight: bold; }
        .tabel .no { text-align: center; }
        .ttd { position: relative; margin: 9mm 0 0 98mm; width: 66mm; text-align: center; page-break-inside: avoid; }
        .ttd .garis { width: 40mm; height: 13.3mm; }
        .ttd .stempel { position: absolute; left: -9mm; top: 4mm; width: 23mm; height: 23mm; }
        .ttd .nm { font-weight: bold; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="contoh">{{ $contoh }}</div>

    <table class="kepala">
        <tr>
            <td class="gambar"><img src="{{ $img['lambang.svg'] }}" alt=""></td>
            <td>
                <div class="oleh">{{ mb_strtoupper($organizer) }}</div>
                <div class="jenis">PETUNJUK TEKNIS</div>
                <div class="nama">{{ $name }}</div>
            </td>
        </tr>
    </table>
    <div class="pita"></div>

    @foreach ($sections as [$heading, $blocks])
        <div class="bagian">{{ chr(65 + $loop->index) }}. {{ mb_strtoupper($heading) }}</div>
        @foreach ($blocks as [$type, $value])
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
                <table class="tabel">
                    <tr>
                        @foreach ($value['head'] as $i => $cell)
                            <th width="{{ $value['widths'][$i] }}%" class="{{ $i === 0 ? 'no' : '' }}">{{ $cell }}</th>
                        @endforeach
                    </tr>
                    @foreach ($value['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $cell)
                                <td class="{{ $i === 0 ? 'no' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
    @endforeach

    <div class="ttd">
        <img class="stempel" src="{{ $img['stempel.svg'] }}" alt="">
        <div>{{ $placeDate }}</div>
        <div>{{ $signer['role'] }},</div>
        <div><img class="garis" src="{{ $img['ttd.svg'] }}" alt=""></div>
        <div class="nm">{{ $signer['name'] }}</div>
    </div>
</body>
</html>
