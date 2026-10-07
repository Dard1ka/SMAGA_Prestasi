{{--
    Portofolio PDF (App\Livewire\Siswa\Portofolio::download). Rendered by dompdf with remote, JS and PHP off:
    no external assets, no images, only inline CSS in this file (the page CSP does not apply to a PDF).
    Contains no NIS/NISN or other identifiers beyond name + class.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Portofolio {{ $name }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #141a2b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; color: #1b2a4e; }
        .muted { color: #566072; }
        .stats { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .stats td { border: 1px solid #dde2e9; padding: 8px 10px; width: 25%; }
        .stats .v { font-size: 16px; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; font-size: 9.5px; color: #3a4254; background: #f6f7f9; border-bottom: 1px solid #dde2e9; padding: 6px; }
        table.list td { border-bottom: 1px solid #e8ebf0; padding: 6px; vertical-align: top; }
        .right { text-align: right; }
        .foot { margin-top: 22px; font-size: 9px; color: #566072; }
    </style>
</head>
<body>
    <h1>{{ $name }}</h1>
    <div class="muted">
        {{ $classroom }}@if ($angkatan) · Angkatan {{ $angkatan }}@endif · SMA Negeri 3 Semarang
    </div>

    <table class="stats">
        <tr>
            <td><div class="muted">Poin kumulatif</div><div class="v">{{ $totalPoints }}</div></td>
            <td><div class="muted">Peringkat sekolah</div><div class="v">{{ $rank !== null ? '#'.$rank : '–' }}</div></td>
            <td><div class="muted">Prestasi</div><div class="v">{{ $achievementCount }}</div></td>
            <td><div class="muted">Lomba diikuti</div><div class="v">{{ $registrationCount }}</div></td>
        </tr>
    </table>

    <h2>Prestasi terverifikasi</h2>
    @if ($achievements === [])
        <p class="muted">Belum ada prestasi terverifikasi.</p>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th>Lomba</th>
                    <th>Tingkat</th>
                    <th>Capaian</th>
                    <th>Tanggal</th>
                    <th>Rumus</th>
                    <th class="right">Poin</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($achievements as $item)
                    <tr>
                        <td><strong>{{ $item['title'] }}</strong><br><span class="muted">{{ $item['organizer'] }}</span></td>
                        <td>{{ $item['level'] }}</td>
                        <td>{{ $item['result'] }}</td>
                        <td>{{ $item['date'] }}</td>
                        <td>{{ $item['formula'] }}</td>
                        <td class="right"><strong>{{ $item['points'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Profil talenta</h2>
    @if ($talents === [])
        <p class="muted">Belum ada talenta.</p>
    @else
        <table class="list">
            <thead>
                <tr><th>Talenta</th><th>Status</th><th>Keterangan</th></tr>
            </thead>
            <tbody>
                @foreach ($talents as $talent)
                    <tr>
                        <td>{{ $talent['title'] }}</td>
                        <td>{{ $talent['status']->label() }}</td>
                        <td>{{ $talent['info'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">Dibuat dari SMAGA Talent Hub pada {{ $generatedOn }}. Poin dihitung dari bobot yang berlaku saat verifikasi.</p>
</body>
</html>
