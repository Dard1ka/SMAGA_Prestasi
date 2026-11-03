{{--
    Sample brochure or poster of a lomba, portrait A4, one page (LOCAL demo data only; rendered by
    Database\Seeders\Support\Demo\DemoPdfRenderer). Two looks: 'brosur' (light page, info boxes, lists) and 'poster'
    (dark page, large title). Text comes from Database\Seeders\Support\Demo\DemoGuideline; the address is always
    example.test, whatever website the lomba row carries.
    Vars: $style · $c (colours) · $name · $nameSize · $organizer · $field · $tagline · $facts ([label, value]) ·
          $categories · $who · $prizes · $steps · $place · $deadline · $site · $email · $img (sandbox paths) · $contoh
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $name }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5pt; line-height: 15pt; color: #1c1c1c; }
        div { margin: 0; padding: 0; }
        table { border-collapse: collapse; }
        ul { margin: 0 0 0 5mm; padding: 0; }
        li { margin-bottom: 1mm; }
        .hal { position: relative; width: 210mm; height: 296.7mm; overflow: hidden; }
        .bg { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
        .contoh { position: absolute; left: 0; right: 0; bottom: 3mm; text-align: center; font-size: 7pt; line-height: 9pt; color: #ffffff; }

        /* brosur */
        .b-atas { position: absolute; left: 16mm; top: 15mm; width: 140mm; color: #ffffff; }
        .b-label { font-size: 9pt; font-weight: bold; letter-spacing: 2pt; }
        .b-oleh { font-size: 11pt; margin-top: 2mm; }
        .b-judul { font-size: {{ $nameSize }}pt; line-height: {{ $nameSize + 4 }}pt; font-weight: bold; margin-top: 6mm; }
        .b-slogan { font-family: Times, serif; font-style: italic; font-size: 14pt; line-height: 18pt; margin-top: 4mm; }
        .b-lambang { position: absolute; left: 164mm; top: 16mm; width: 28mm; height: 28mm; }
        .b-fakta { position: absolute; left: 16mm; top: 143mm; width: 178mm; }
        .b-fakta td { width: 33.3%; vertical-align: top; padding: 0 3mm 0 3.5mm; border-left: 2.4pt solid {{ $c['accent'] }}; }
        .b-fakta .k { font-size: 8.5pt; font-weight: bold; letter-spacing: 1.4pt; color: {{ $c['primary'] }}; }
        .b-fakta .v { font-size: 11.5pt; line-height: 15pt; font-weight: bold; margin-top: 0.8mm; }
        .b-kolom { position: absolute; top: 177mm; width: 84mm; }
        .b-kolom-1 { left: 16mm; }
        .b-kolom-2 { left: 110mm; }
        .b-kepala { font-size: 12.5pt; font-weight: bold; color: {{ $c['primary'] }}; margin-bottom: 2mm; }
        .b-langkah { position: absolute; left: 16mm; top: 227mm; width: 178mm; }
        .b-langkah td { vertical-align: middle; padding: 0 3mm 0 0; }
        .b-langkah .n { width: 9mm; }
        .b-langkah .n div { width: 7.5mm; height: 7.5mm; line-height: 7.5mm; border-radius: 3.75mm; background-color: {{ $c['accent'] }}; color: #1c1c1c; text-align: center; font-weight: bold; }
        .b-langkah .t { width: 50mm; font-size: 10pt; line-height: 13.5pt; }
        .b-bawah { position: absolute; left: 16mm; right: 16mm; bottom: 10.5mm; color: #ffffff; font-size: 10pt; }

        /* poster */
        .p-atas { position: absolute; left: 26mm; right: 26mm; top: 20mm; text-align: center; color: #ffffff; }
        .p-oleh { font-size: 10pt; font-weight: bold; letter-spacing: 2.4pt; color: {{ $c['accent'] }}; }
        .p-persembahan { font-family: Times, serif; font-style: italic; font-size: 11pt; margin-top: 1mm; }
        .p-lambang { width: 22mm; height: 22mm; margin-top: 9mm; }
        .p-judul { font-family: Times, serif; font-size: {{ $nameSize + 6 }}pt; line-height: {{ $nameSize + 10 }}pt; font-weight: bold; letter-spacing: 1pt; margin-top: 7mm; }
        .p-slogan { font-family: Times, serif; font-style: italic; font-size: 15pt; line-height: 19pt; color: {{ $c['accent'] }}; margin-top: 6mm; }
        .p-tengah { position: absolute; left: 30mm; right: 30mm; top: 158mm; text-align: center; color: #ffffff; }
        .p-label { font-size: 9pt; font-weight: bold; letter-spacing: 2.4pt; color: {{ $c['accent'] }}; }
        .p-kategori { font-size: 12pt; line-height: 17pt; margin-top: 1.5mm; }
        .p-batas { font-size: 23pt; line-height: 28pt; font-weight: bold; margin-top: 1mm; }
        .p-bawah { position: absolute; left: 26mm; right: 26mm; top: 240mm; text-align: center; color: #ffffff; font-size: 11pt; line-height: 16pt; }
        .p-bawah .kecil { font-size: 9.5pt; color: {{ $c['soft'] }}; margin-top: 2mm; }
    </style>
</head>
<body>
<div class="hal">
    <img class="bg" src="{{ $img['bg.svg'] }}" alt="">

    @if ($style === 'poster')
        <div class="p-atas">
            <div class="p-oleh">{{ mb_strtoupper($organizer) }}</div>
            <div class="p-persembahan">mempersembahkan</div>
            <div><img class="p-lambang" src="{{ $img['lambang.svg'] }}" alt=""></div>
            <div class="p-judul">{{ mb_strtoupper($name) }}</div>
            <div class="p-slogan">{{ $tagline }}</div>
        </div>

        <div class="p-tengah">
            <div class="p-label">KATEGORI</div>
            <div class="p-kategori">{{ implode(' · ', $categories) }}</div>
            <div class="p-label" style="margin-top: 9mm;">BATAS PENDAFTARAN</div>
            <div class="p-batas">{{ $deadline }}</div>
        </div>

        <div class="p-bawah">
            <div>{{ $facts[1][0] }}: {{ $facts[1][1] }}@if ($place !== null) · {{ $place }}@endif</div>
            <div>Untuk siswa SMA/MA/SMK sederajat</div>
            <div class="kecil">Info dan pendaftaran: {{ $site }} · {{ $email }}</div>
        </div>
    @else
        <img class="b-lambang" src="{{ $img['lambang.svg'] }}" alt="">
        <div class="b-atas">
            <div class="b-label">INFO LOMBA · {{ mb_strtoupper($field) }}</div>
            <div class="b-oleh">{{ $organizer }}</div>
            <div class="b-judul">{{ $name }}</div>
            <div class="b-slogan">{{ $tagline }}</div>
        </div>

        <table class="b-fakta">
            <tr>
                @foreach ($facts as [$label, $value])
                    <td>
                        <div class="k">{{ mb_strtoupper($label) }}</div>
                        <div class="v">{{ $value }}</div>
                    </td>
                @endforeach
            </tr>
        </table>

        <div class="b-kolom b-kolom-1">
            <div class="b-kepala">Siapa yang bisa ikut?</div>
            <ul>
                @foreach ($who as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
        <div class="b-kolom b-kolom-2">
            <div class="b-kepala">Penghargaan</div>
            <ul>
                @foreach ($prizes as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>

        <div class="b-langkah">
            <div class="b-kepala">Cara mendaftar</div>
            <table>
                <tr>
                    @foreach ($steps as $i => $step)
                        <td class="n"><div>{{ $i + 1 }}</div></td>
                        <td class="t">{{ $step }}</td>
                    @endforeach
                </tr>
            </table>
        </div>

        <div class="b-bawah">Info dan pendaftaran: {{ $site }} · {{ $email }}</div>
    @endif

    <div class="contoh">{{ $contoh }}</div>
</div>
</body>
</html>
