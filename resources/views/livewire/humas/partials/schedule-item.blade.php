{{-- One scheduled publication in Humas · Jadwal. $item comes from Jadwal::item(). --}}
<li wire:key="jadwal-{{ $item['id'] }}" class="flex flex-col gap-2 rounded-tile border border-line bg-surface px-3 py-2.5 sm:flex-row sm:items-center sm:gap-3">
    <span class="num shrink-0 text-xs font-bold text-primary-on-tint sm:w-24">{{ $item['time'] }}</span>
    <div class="flex min-w-0 flex-1 flex-col">
        <span class="text-md leading-snug font-bold">{{ $item['title'] }}</span>
        <span class="text-xs text-muted">{{ $item['student'] }} · {{ $item['headline'] }}</span>
        @if ($item['channels'] !== '')
            <span class="text-xs font-semibold text-ink-2">{{ $item['channels'] }}</span>
        @endif
    </div>
    <x-sth.button variant="quiet" size="sm" :href="$item['href']" class="self-start sm:self-auto">Buka</x-sth.button>
</li>
