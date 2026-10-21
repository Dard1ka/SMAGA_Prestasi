{{--
    Daftar siswa apresiasi (D-141). Rendered by App\Domain\Letters\PdfRenderer (no remote, no JS, no PHP). Static
    template: data printed with {{ }} only. Only students who may be named (NamedStudent); no NIS, NISN or points.
    Vars: $event (name, when) · $rows (no, name, class, result, competition, level) · $omitted ·
          $tpl (App\Actions\Templates\LetterTemplate, kop lines) · $printedAt
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Daftar apresiasi · {{ $event['name'] }}</title>
    <style>
        @page { margin: 1.5cm 1.8cm 2cm 1.8cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.4; color: #111; }
        .foot { position: fixed; bottom: -1.4cm; left: 0; right: 0; font-size: 8pt; color: #555; border-top: 0.5pt solid #bbb; padding-top: 3pt; }
        .foot .page { float: right; }
        .foot .page:after { content: "Halaman " counter(page); }
        .kop { width: 100%; border-bottom: 2pt double #000; margin-bottom: 12pt; text-align: center; padding-bottom: 4pt; }
        .kop .t { font-weight: bold; font-size: 10.5pt; }
        .kop .t.big { font-size: 12pt; }
        .kop .d { font-size: 8pt; }
        h1 { font-size: 14pt; margin: 0 0 3pt 0; }
        .event { font-size: 11pt; font-weight: bold; }
        .muted { color: #555; }
        .mt8 { margin-top: 8pt; }
        table { border-collapse: collapse; width: 100%; }
        .grid th, .grid td { border: 0.6pt solid #999; padding: 3pt 5pt; vertical-align: top; }
        .grid th { background: #f3f4f6; text-align: left; }
        .r { text-align: right; }
    </style>
</head>
<body>
    <div class="foot"><span>Dicetak {{ $printedAt }}</span> <span class="page"></span></div>

    <div class="kop">
        @foreach ($tpl->kopTitleLines() as $line)
            <div class="t {{ $loop->last ? 'big' : '' }}">{{ $line }}</div>
        @endforeach
        @foreach ($tpl->kopDetailLines() as $line)
            <div class="d">{{ $line }}</div>
        @endforeach
    </div>

    <h1>Daftar siswa apresiasi</h1>
    <div class="event">{{ $event['name'] }}</div>
    <div class="muted">{{ $event['when'] }}</div>

    <div class="mt8">
        @if ($rows === [])
            <p class="muted">Belum ada siswa yang bisa dicantumkan.</p>
        @else
            <table class="grid">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>Kelas</th>
                        <th>Capaian</th>
                        <th>Lomba</th>
                        <th>Tingkat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="r">{{ $row['no'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['class'] }}</td>
                            <td>{{ $row['result'] }}</td>
                            <td>{{ $row['competition'] }}</td>
                            <td>{{ $row['level'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($omitted > 0)
        <p class="muted mt8">{{ $omitted }} prestasi tidak dicantumkan karena akun siswanya nonaktif.</p>
    @endif
</body>
</html>
