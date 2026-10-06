{{--
    Plain sample page for a demo file that fits no other kind (LOCAL demo data only; rendered by
    Database\Seeders\Support\Demo\DemoPdfRenderer).
    Vars: $title · $purpose · $owner (a name of the demo set or null) · $img (sandbox paths) · $contoh
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 2.5cm; }
        body { margin: 0; font-family: Times, serif; font-size: 12pt; line-height: 18pt; color: #000; }
        div { margin: 0; padding: 0; }
        p { margin: 0 0 3mm; text-align: justify; }
        .contoh { position: fixed; left: 0; right: 0; bottom: -1.8cm; text-align: center; font-family: Helvetica, Arial, sans-serif; font-size: 7pt; line-height: 9pt; color: #555; }
        .kepala { text-align: center; border-bottom: 1.5pt solid #1f3a5f; padding-bottom: 5mm; margin-bottom: 9mm; }
        .kepala img { width: 20mm; height: 20mm; }
        .kepala .jenis { font-size: 10pt; letter-spacing: 2pt; color: #555; margin-top: 2mm; }
        .kepala .judul { font-size: 17pt; line-height: 22pt; font-weight: bold; margin-top: 1mm; }
        table { border-collapse: collapse; margin-bottom: 6mm; }
        td { vertical-align: top; padding: 1mm 0; }
        .k { width: 40mm; }
        .s { width: 5mm; }
    </style>
</head>
<body>
    <div class="contoh">{{ $contoh }}</div>

    <div class="kepala">
        <div><img src="{{ $img['lambang.svg'] }}" alt=""></div>
        <div class="jenis">DOKUMEN LAMPIRAN</div>
        <div class="judul">{{ $title }}</div>
    </div>

    <table>
        <tr><td class="k">Jenis berkas</td><td class="s">:</td><td>{{ $purpose }}</td></tr>
        @if ($owner !== null)
            <tr><td class="k">Di-upload oleh</td><td class="s">:</td><td>{{ $owner }}</td></tr>
        @endif
        <tr><td class="k">Sekolah</td><td class="s">:</td><td>SMA Negeri 3 Semarang</td></tr>
    </table>

    <p>Halaman ini mewakili isi berkas lampiran pada data contoh SMAGA Talent Hub. Berkas aslinya tidak ada, karena seluruh data pada lingkungan uji ini fiktif.</p>
    <p>Pada data sungguhan, di tempat ini terdapat dokumen yang di-upload pengguna, misalnya pengumuman hasil lomba, foto kegiatan, atau surat dari penyelenggara.</p>
</body>
</html>
