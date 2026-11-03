{{--
    Sample "piagam penghargaan", portrait A4, the formal style of sports events (LOCAL demo data only; rendered by
    Database\Seeders\Support\Demo\DemoPdfRenderer). Frame, emblem, banner, medal and stamp are our own drawings
    (Database\Seeders\Support\Demo\DemoArt); the stamp reads CONTOH. Data is printed with {{ }} only.
    Vars: $c (colours) · $organizer · $committee · $number · $rows ([label, value]) · $result · $resultSize ·
          $body · $closing · $placeDate · $signers · $img (sandbox paths) · $contoh (footer line)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Piagam penghargaan {{ $rows[0][1] }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Times, serif; font-size: 12pt; color: #1d1d1d; }
        p, div { margin: 0; padding: 0; }
        table { border-collapse: collapse; }
        .hal { position: relative; width: 210mm; height: 296.7mm; overflow: hidden; }
        .bg { position: absolute; left: 0; top: 0; width: 210mm; height: 297mm; }
        .isi { position: absolute; left: 24mm; right: 24mm; top: 21mm; text-align: center; }
        .lambang { width: 21mm; height: 21mm; }
        .penyelenggara { font-family: Helvetica, Arial, sans-serif; font-size: 12.5pt; font-weight: bold; letter-spacing: 0.8pt; color: {{ $c['dark'] }}; margin-top: 2mm; }
        .panitia { font-family: Helvetica, Arial, sans-serif; font-size: 9.5pt; letter-spacing: 0.5pt; color: #333; margin-top: 1.2mm; }
        .garis-tebal { width: 150mm; margin: 3.5mm auto 0; border-top: 2pt solid {{ $c['primary'] }}; height: 0; font-size: 0; line-height: 0; }
        .garis-tipis { width: 150mm; margin: 0.8mm auto 0; border-top: 0.6pt solid {{ $c['accent'] }}; height: 0; font-size: 0; line-height: 0; }
        .judul { font-size: 31pt; line-height: 34pt; font-weight: bold; letter-spacing: 2.5pt; color: {{ $c['primary'] }}; margin-top: 9mm; }
        .nomor { font-size: 10.5pt; margin-top: 1.5mm; }
        .kepada { font-size: 12pt; margin-top: 9mm; }
        .data { width: 136mm; margin: 4mm auto 0; }
        .data td { font-size: 12.5pt; line-height: 17pt; padding: 1.1mm 0; text-align: left; vertical-align: top; }
        .data .k { width: 38mm; }
        .data .s { width: 5mm; }
        .data .nama { font-size: 14.5pt; font-weight: bold; }
        .sebagai { font-size: 12pt; margin-top: 7mm; }
        .pita { position: relative; width: 116mm; height: 16mm; margin: 2.5mm auto 0; }
        .pita img { position: absolute; left: 0; top: 0; width: 116mm; height: 15.95mm; }
        .pita div { position: absolute; left: 10mm; right: 10mm; top: {{ $resultSize >= 17 ? 2.3 : ($resultSize >= 14 ? 3.4 : 4.2) }}mm; text-align: center; color: #ffffff; font-weight: bold; font-size: {{ $resultSize }}pt; line-height: {{ $resultSize + 1 }}pt; letter-spacing: 1pt; }
        .uraian { width: 152mm; margin: 7mm auto 0; font-size: 12pt; line-height: 18pt; }
        .tanggal { position: absolute; left: 0; right: 0; bottom: 84mm; text-align: center; font-size: 11.5pt; }
        .ttd { position: absolute; bottom: 45mm; width: 74mm; text-align: center; }
        .ttd-1 { left: 23mm; }
        .ttd-2 { left: 113mm; }
        .ttd .peran { font-size: 11.5pt; }
        .ttd img { width: 42mm; height: 14mm; margin-top: 1mm; }
        .ttd .nm { font-size: 11.5pt; font-weight: bold; text-decoration: underline; }
        .stempel { position: absolute; left: 20mm; bottom: 47mm; width: 25mm; height: 25mm; }
        .medali { position: absolute; left: 96mm; bottom: 18mm; width: 18mm; height: 25.2mm; }
        .contoh { position: absolute; left: 0; right: 0; bottom: 14.6mm; text-align: center; font-family: Helvetica, Arial, sans-serif; font-size: 7pt; color: #555; }
    </style>
</head>
<body>
<div class="hal">
    <img class="bg" src="{{ $img['bg.svg'] }}" alt="">

    <div class="isi">
        <div><img class="lambang" src="{{ $img['lambang.svg'] }}" alt=""></div>
        <div class="penyelenggara">{{ $organizer }}</div>
        <div class="panitia">{{ $committee }}</div>
        <div class="garis-tebal"></div>
        <div class="garis-tipis"></div>

        <div class="judul">PIAGAM PENGHARGAAN</div>
        <div class="nomor">{{ $number }}</div>

        <div class="kepada">Diberikan kepada:</div>
        <table class="data">
            @foreach ($rows as $i => $row)
                <tr>
                    <td class="k">{{ $row[0] }}</td>
                    <td class="s">:</td>
                    <td class="{{ $i === 0 ? 'nama' : '' }}">{{ $row[1] }}</td>
                </tr>
            @endforeach
        </table>

        <div class="sebagai">sebagai</div>
        <div class="pita">
            <img src="{{ $img['pita.svg'] }}" alt="">
            <div>{{ $result }}</div>
        </div>

        <div class="uraian">{{ $body }} {{ $closing }}</div>
    </div>

    <div class="tanggal">{{ $placeDate }}</div>

    <img class="stempel" src="{{ $img['stempel.svg'] }}" alt="">
    @foreach ($signers as $i => $signer)
        <div class="ttd ttd-{{ $i + 1 }}">
            <div class="peran">{{ $signer['role'] }},</div>
            <div><img src="{{ $img['ttd-'.($i + 1).'.svg'] }}" alt=""></div>
            <div class="nm">{{ $signer['name'] }}</div>
        </div>
    @endforeach

    <img class="medali" src="{{ $img['medali.svg'] }}" alt="">

    <div class="contoh">{{ $contoh }}</div>
</div>
</body>
</html>
