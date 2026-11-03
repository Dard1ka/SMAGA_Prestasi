{{--
    Sample certificate, landscape A4 (LOCAL demo data only; rendered by Database\Seeders\Support\Demo\DemoPdfRenderer
    with dompdf: no remote, no JS, no PHP, core fonts). Six looks share this file: the theme sets the colours, the
    background picture and the measures in $l (Database\Seeders\Support\Demo\DemoCertificate). Our own layout and
    wording; no logo, stamp or signature of a real body. Data is printed with {{ }} only.
    Vars: $theme · $c (colours) · $l (measures) · $organizer · $committee · $heading · $subheading · $number ·
          $presented · $name · $affiliation · $team · $as · $result · $branch · $body · $closing · $placeDate ·
          $signers · $img (sandbox paths) · $contoh (footer line)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $heading }} {{ $name }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #222; }
        p, div { margin: 0; padding: 0; }
        .hal { position: relative; width: 297mm; height: 209.7mm; overflow: hidden; }
        .bg { position: absolute; left: 0; top: 0; width: 297mm; height: 210mm; }
        .isi { position: absolute; left: {{ $l['left'] }}mm; right: {{ $l['right'] }}mm; top: {{ $l['top'] }}mm; text-align: {{ $l['align'] }}; }
        .lambang { width: {{ $l['emblem'] }}mm; height: {{ $l['emblem'] }}mm; }
        .penyelenggara { font-size: 10.5pt; font-weight: bold; letter-spacing: 1.2pt; color: {{ $c['dark'] }}; margin-top: 1mm; }
        .panitia { font-size: 8.5pt; color: #555; margin-top: 0.8mm; }
        .judul { font-family: {{ $l['titleFont'] }}; font-weight: bold; font-size: {{ $l['titleSize'] }}pt; line-height: {{ $l['titleSize'] }}pt; letter-spacing: {{ $l['titleSpacing'] }}pt; color: {{ $l['titleColor'] }}; margin-top: {{ $l['titleGap'] }}mm; }
        .subjudul { font-size: 13pt; line-height: 15pt; letter-spacing: 7pt; color: #111; margin-top: 1.2mm; }
        .nomor { font-size: 8.5pt; color: #555; margin-top: 2.2mm; }
        .kepada { font-size: 11pt; margin-top: {{ $l['gap'] }}mm; }
        .nama { font-family: Times, serif; font-style: italic; font-weight: bold; font-size: {{ $l['nameSize'] }}pt; line-height: {{ $l['nameSize'] + 4 }}pt; color: {{ $l['nameColor'] }}; margin-top: 1.5mm; }
        .garis { width: {{ $l['rule'] }}mm; border-top: 0.8pt solid {{ $c['accent'] }}; height: 0; font-size: 0; line-height: 0; margin-top: 0.6mm; }
        .tengah .garis { margin-left: auto; margin-right: auto; }
        .asal { font-size: 10pt; margin-top: 2mm; }
        .tim { font-size: 9pt; color: #444; margin-top: 0.8mm; }
        .sebagai { font-size: 11pt; margin-top: {{ $l['gap'] - 1.5 }}mm; }
        .capaian { font-family: Times, serif; font-weight: bold; font-size: 22pt; line-height: 25pt; letter-spacing: 1.5pt; color: {{ $l['resultColor'] }}; margin-top: 0.4mm; }
        .cabang { font-size: 10.5pt; font-weight: bold; color: {{ $c['dark'] }}; margin-top: 0.6mm; }
        .uraian { font-size: 10.5pt; line-height: 14.5pt; width: {{ $l['text'] }}mm; margin-top: 3.2mm; }
        .tengah .uraian { margin-left: auto; margin-right: auto; }
        .tanggal { position: absolute; left: {{ $l['dateLeft'] }}mm; right: {{ $l['dateRight'] }}mm; bottom: {{ $l['dateBottom'] }}mm; text-align: center; font-size: 10pt; }
        .ttd { position: absolute; bottom: {{ $l['signBottom'] }}mm; width: 78mm; text-align: center; }
        .ttd-1 { left: {{ $l['sign1'] }}mm; }
        .ttd-2 { left: {{ $l['sign2'] }}mm; }
        .ttd img { width: 40mm; height: 13.3mm; }
        .ttd .nm { font-size: 10pt; font-weight: bold; color: {{ $l['signColor'] }}; }
        .ttd .jb { font-family: Times, serif; font-style: italic; font-size: 10pt; margin-top: 0.4mm; }
        .stempel { position: absolute; left: {{ $l['sign1'] + 5 }}mm; bottom: {{ $l['signBottom'] + 7 }}mm; width: 23mm; height: 23mm; }
        .medali { position: absolute; left: {{ $l['medalLeft'] }}mm; bottom: {{ $l['medalBottom'] }}mm; width: 26mm; height: 36.4mm; }
        .lambang-sisi { position: absolute; left: 19.5mm; top: 17mm; width: 25mm; height: 25mm; }
        .contoh { position: absolute; left: {{ $l['footLeft'] }}mm; right: 0; bottom: {{ $l['footBottom'] }}mm; text-align: center; font-size: 7pt; color: {{ $l['footColor'] }}; }
    </style>
</head>
<body>
<div class="hal {{ $l['align'] === 'center' ? 'tengah' : 'kiri' }}">
    <img class="bg" src="{{ $img['bg.svg'] }}" alt="">

    @if ($theme === 'kampus' && isset($img['lambang.svg']))
        <img class="lambang-sisi" src="{{ $img['lambang.svg'] }}" alt="">
    @endif

    <div class="isi">
        @if ($theme !== 'kampus' && isset($img['lambang.svg']))
            <div><img class="lambang" src="{{ $img['lambang.svg'] }}" alt=""></div>
        @endif
        <div class="penyelenggara">{{ $organizer }}</div>
        <div class="panitia">{{ $committee }}</div>

        <div class="judul">{{ $heading }}</div>
        <div class="subjudul">{{ $subheading }}</div>
        <div class="nomor">{{ $number }}</div>

        <div class="kepada">{{ $presented }}</div>
        <div class="nama">{{ $name }}</div>
        <div class="garis"></div>
        <div class="asal">{{ $affiliation }}</div>
        @if ($team !== null)
            <div class="tim">{{ $team }}</div>
        @endif

        <div class="sebagai">{{ $as }}</div>
        <div class="capaian">{{ $result }}</div>
        @if ($branch !== null)
            <div class="cabang">{{ $branch }}</div>
        @endif

        <div class="uraian">{{ $body }} {{ $closing }}</div>
    </div>

    <div class="tanggal">{{ $placeDate }}</div>

    <img class="stempel" src="{{ $img['stempel.svg'] }}" alt="">
    @foreach ($signers as $i => $signer)
        <div class="ttd ttd-{{ $i + 1 }}">
            <div><img src="{{ $img['ttd-'.($i + 1).'.svg'] }}" alt=""></div>
            <div class="nm">{{ $signer['name'] }}</div>
            <div class="jb">{{ $signer['role'] }}</div>
        </div>
    @endforeach

    <img class="medali" src="{{ $img['medali.svg'] }}" alt="">

    <div class="contoh">{{ $contoh }}</div>
</div>
</body>
</html>
