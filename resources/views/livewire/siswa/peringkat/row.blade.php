{{-- One table row of Siswa · Peringkat (B4). $row from PublicAchievements::ranking() + `me`; $isSiswa = mode siswa. --}}
<tr wire:key="row-{{ $isSiswa ? 's' : 'k' }}-{{ $row['position'] }}" @class(['bg-primary-wash' => $row['me']]) @if ($row['me']) aria-current="true" @endif>
    <td class="num font-bold text-ink-2">{{ $row['rank'] }}</td>
    @if ($isSiswa)
        <td>
            <span class="flex min-w-0 items-center gap-3">
                @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'sm'])
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                        @include('livewire.publik.partials.person-name', ['person' => $row, 'wrap' => true])
                        @if ($row['me'])<x-sth.chip tone="blue" size="sm">Kamu</x-sth.chip>@endif
                    </span>
                    <span class="text-xs text-muted sm:hidden">{{ collect([$row['kelas'], $row['count'].' prestasi'])->filter()->implode(' · ') }}</span>
                </span>
            </span>
        </td>
        <td class="hidden sm:table-cell">{{ $row['kelas'] ?? '–' }}</td>
        <td class="hidden md:table-cell">{{ $row['main_bidang'] ?? '–' }}</td>
    @else
        <td>
            <span class="flex flex-col">
                <span class="flex flex-wrap items-center gap-1.5">
                    <span class="font-bold">{{ $row['kelas'] }}</span>
                    @if ($row['me'])<x-sth.chip tone="blue" size="sm">Kelasmu</x-sth.chip>@endif
                </span>
                <span class="text-xs text-muted sm:hidden">{{ $row['students'] }} siswa · {{ $row['count'] }} prestasi</span>
            </span>
        </td>
        <td class="num hidden sm:table-cell">{{ $row['students'] }}</td>
    @endif
    <td class="num hidden sm:table-cell">{{ $row['count'] }}</td>
    <td class="display num text-right text-base font-bold">{{ $row['points_label'] }}</td>
</tr>
