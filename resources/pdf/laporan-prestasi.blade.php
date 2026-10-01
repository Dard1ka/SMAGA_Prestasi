{{--
    Laporan prestasi (Kepala Sekolah, backlog #6, owner B5). Rendered by App\Domain\Letters\PdfRenderer (no remote,
    no JS, no PHP). Static template: data printed with {{ }} only. Watermark = diagonal text + footer line on every page.
    Vars: $meta (title, period, bidang, watermark, generatedAt, exporter) · $summary (ExportPrestasiData::summary) ·
          $rows (detail rows, formatted) · $fmt (points formatter) · $tpl (App\Actions\Templates\LetterTemplate, kop lines)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $meta['title'] }} · {{ $meta['period'] }}</title>
    <style>
        @page { margin: 1.4cm 1.5cm 2cm 1.5cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.35; color: #111; }
        .mark { position: fixed; top: 38%; left: -2cm; width: 24cm; text-align: center; transform: rotate(-30deg); font-size: 22pt; color: #e5e7eb; font-weight: bold; }
        .foot { position: fixed; bottom: -1.3cm; left: 0; right: 0; font-size: 7pt; color: #555; border-top: 0.5pt solid #bbb; padding-top: 3pt; }
        .foot .page { float: right; }
        .foot .page:after { content: "Halaman " counter(page); }
        .mt8 { margin-top: 8pt; }
        .mt6 { margin-top: 6pt; }
        .kop { width: 100%; border-bottom: 2pt double #000; margin-bottom: 10pt; text-align: center; padding-bottom: 4pt; }
        .kop .t { font-weight: bold; font-size: 10pt; }
        .kop .t.big { font-size: 11.5pt; }
        .kop .d { font-size: 8pt; }
        h1 { font-size: 13pt; margin: 0 0 2pt 0; }
        h2 { font-size: 10.5pt; margin: 12pt 0 4pt 0; }
        .muted { color: #555; }
        table { border-collapse: collapse; width: 100%; }
        .kpi td { border: 0.6pt solid #bbb; padding: 5pt; text-align: center; width: 20%; }
        .kpi .v { font-size: 13pt; font-weight: bold; }
        .grid th, .grid td { border: 0.6pt solid #999; padding: 2.5pt 4pt; vertical-align: top; }
        .grid th { background: #f3f4f6; text-align: left; }
        .r { text-align: right; }
        .two td.col { width: 50%; vertical-align: top; padding: 0 4pt 0 0; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="mark">{{ $meta['watermark'] }}</div>
    <div class="foot"><span>{{ $meta['watermark'] }}</span> <span class="page"></span></div>

    <div class="kop">
        @foreach ($tpl->kopTitleLines() as $line)
            <div class="t {{ $loop->last ? 'big' : '' }}">{{ $line }}</div>
        @endforeach
        @foreach ($tpl->kopDetailLines() as $line)
            <div class="d">{{ $line }}</div>
        @endforeach
    </div>

    <h1>{{ $meta['title'] }}</h1>
    <div class="muted">{{ $meta['period'] }} · {{ $meta['bidang'] }} · dibuat {{ $meta['generatedAt'] }}</div>

    <table class="kpi mt8">
        <tr>
            <td><div class="v">{{ $summary['totals']['prestasi'] }}</div>prestasi terverifikasi</td>
            <td><div class="v">{{ $summary['totals']['siswa'] }}</div>siswa berprestasi</td>
            <td><div class="v">{{ $summary['totals']['lomba'] }}</div>lomba</td>
            <td><div class="v">{{ $summary['totals']['nasional'] }}</div>nasional ke atas</td>
            <td><div class="v">{{ $fmt($summary['totals']['points']) }}</div>total poin</td>
        </tr>
    </table>

    <table class="two mt6">
        <tr>
            <td class="col">
                <h2>Per bidang</h2>
                @include('pdf::partials.laporan-grup', ['groups' => $summary['bidang'], 'head' => 'Bidang'])
                <h2>Per tingkat</h2>
                @include('pdf::partials.laporan-grup', ['groups' => $summary['tingkat'], 'head' => 'Tingkat'])
            </td>
            <td class="col">
                <h2>Per capaian</h2>
                @include('pdf::partials.laporan-grup', ['groups' => $summary['capaian'], 'head' => 'Capaian'])
                <h2>Per guru pembimbing</h2>
                @include('pdf::partials.laporan-grup', ['groups' => $summary['pembimbing'], 'head' => 'Guru pembimbing'])
            </td>
        </tr>
    </table>

    <h2>Per kelas</h2>
    @include('pdf::partials.laporan-grup', ['groups' => $summary['kelas'], 'head' => 'Kelas'])

    <div class="page-break"></div>
    <h2>Rincian prestasi ({{ count($rows) }})</h2>
    @if ($rows === [])
        <p class="muted">Tidak ada prestasi terverifikasi pada periode ini.</p>
    @else
        <table class="grid">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama</th>
                    <th>Kelas</th>
                    <th>Lomba</th>
                    <th>Bidang</th>
                    <th>Tingkat</th>
                    <th>Capaian</th>
                    <th class="r">Poin</th>
                    <th>Tanggal</th>
                    <th>Pembimbing</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="r">{{ $loop->iteration }}</td>
                        <td>{{ $row['name'] }}@if ($row['status'] !== 'Aktif') ({{ $row['status'] }})@endif</td>
                        <td>{{ $row['kelas'] }}</td>
                        <td>{{ $row['lomba'] }}</td>
                        <td>{{ $row['bidang'] }}</td>
                        <td>{{ $row['tingkat'] }}</td>
                        <td>{{ $row['capaian'] }}</td>
                        <td class="r">{{ $row['points'] }}</td>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['mentor'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
