{{--
    One row of "Siswa berprestasi" (Prestasi per bidang, level 2). The caller wraps it in a <button>
    (consenting student, opens level 3) or a plain <div> (anonymous "Siswa kelas XI").
    s: PublicAchievements::studentsInBidang() row. A named alumnus has the chip "Alumni" and no class line
    (`kelas` is null for alumni); an anonymous alumnus is already labelled "Alumni".
--}}
@include('livewire.publik.partials.avatar', ['person' => $s, 'size' => 'lg'])
<span class="flex min-w-0 flex-1 flex-col gap-1.5">
    <span class="flex min-h-[22px] min-w-0 items-center gap-1.5">
        <span @class(['min-w-0 truncate text-md font-bold', 'text-ink' => $s['named'], 'text-ink-2' => ! $s['named']])>{{ $s['name'] }}</span>
        @if ($s['alumni'] && $s['named'])
            <x-sth.chip size="sm" class="shrink-0">Alumni</x-sth.chip>
        @endif
        <span class="num ml-auto shrink-0 text-xs font-semibold whitespace-nowrap text-muted">{{ $s['count'] }} prestasi</span>
    </span>
    <span class="flex flex-wrap items-center gap-1.5">
        @if ($s['kelas'])
            <span class="min-w-11 text-xs font-semibold text-ink-2">{{ $s['kelas'] }}</span>
        @endif
        <x-sth.chip size="sm" tone="gold">{{ $s['best'] }}</x-sth.chip>
        <x-sth.chip size="sm">{{ $s['best_tingkat'] }}</x-sth.chip>
    </span>
</span>
