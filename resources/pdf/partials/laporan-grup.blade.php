{{-- One summary table of the laporan prestasi (owner B5). Vars: $groups (label, prestasi, siswa, points) · $head · $fmt --}}
<table class="grid">
    <thead>
        <tr>
            <th>{{ $head }}</th>
            <th class="r">Prestasi</th>
            <th class="r">Siswa</th>
            <th class="r">Poin</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($groups as $group)
            <tr>
                <td>{{ $group['label'] }}</td>
                <td class="r">{{ $group['prestasi'] }}</td>
                <td class="r">{{ $group['siswa'] }}</td>
                <td class="r">{{ $fmt($group['points']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Tidak ada data.</td></tr>
        @endforelse
    </tbody>
</table>
