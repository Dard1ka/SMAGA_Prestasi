{{-- Humas · Jadwal. Data is prepared in App\Livewire\Humas\Jadwal (read-only). --}}
<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px] xl:items-start">
    <section aria-labelledby="jadwal-publikasi" class="flex min-w-0 flex-col gap-3">
        <x-sth.page-header id="jadwal-publikasi" title="Publikasi terjadwal" />

        @if ($overdue !== [])
            <x-sth.card tone="warn" padding="sm" as="section" aria-labelledby="jadwal-lewat" class="flex flex-col gap-2">
                <h3 id="jadwal-lewat" class="m-0 flex items-center gap-2 text-[15px] font-bold text-orange">
                    <x-sth.icon name="clock" :size="18" />Lewat jadwal, belum ditandai terbit
                </h3>
                <ul class="m-0 flex list-none flex-col gap-2 p-0">
                    @foreach ($overdue as $item)
                        @include('livewire.humas.partials.schedule-item', ['item' => $item])
                    @endforeach
                </ul>
            </x-sth.card>
        @endif

        @forelse ($days as $day)
            <x-sth.card as="section" padding="sm" wire:key="day-{{ $day['key'] }}" aria-labelledby="hari-{{ $day['key'] }}" class="flex flex-col gap-2">
                <h3 id="hari-{{ $day['key'] }}" class="m-0 flex items-center gap-2 text-[15px] font-bold">
                    <x-sth.icon name="calendar" :size="18" class="text-primary" />{{ $day['label'] }}
                </h3>
                <ul class="m-0 flex list-none flex-col gap-2 p-0">
                    @foreach ($day['items'] as $item)
                        @include('livewire.humas.partials.schedule-item', ['item' => $item])
                    @endforeach
                </ul>
            </x-sth.card>
        @empty
            @if ($overdue === [])
                <x-sth.empty-state icon="calendar" title="Belum ada publikasi terjadwal" :level="3">
                    Jadwalkan publikasi dari antrean.
                    <x-slot:actions>
                        <x-sth.button variant="soft" size="sm" :href="route('humas.beranda')">Buka antrean publikasi</x-sth.button>
                    </x-slot:actions>
                </x-sth.empty-state>
            @endif
        @endforelse
    </section>

    @if ($canEvents)
        <section aria-labelledby="jadwal-apresiasi" class="flex min-w-0 flex-col gap-3">
            <x-sth.page-header id="jadwal-apresiasi" title="Apresiasi mendatang">
                <x-slot:actions>
                    <x-sth.link :href="route('humas.apresiasi.index')" icon-right="chevron-right">Kelola</x-sth.link>
                </x-slot:actions>
            </x-sth.page-header>

            @forelse ($events as $event)
                <x-sth.card as="article" padding="sm" wire:key="event-{{ $event['id'] }}" class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="m-0 text-[15px] leading-snug font-bold">{{ $event['name'] }}</h3>
                            <p class="m-0 text-[13px] text-muted">{{ $event['date'] }}</p>
                        </div>
                        <x-sth.chip tone="blue" size="sm" class="num">{{ $event['count'] }} prestasi</x-sth.chip>
                    </div>
                    @if ($event['items'] !== [])
                        <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                            @foreach ($event['items'] as $row)
                                <li wire:key="apr-{{ $row['id'] }}" class="flex flex-col rounded-tile bg-subtle px-3 py-2">
                                    <span class="text-[13.5px] font-semibold">{{ $row['student'] }}</span>
                                    <span class="text-[12.5px] text-muted">{{ $row['headline'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="m-0 text-[13px] text-muted">Belum ada prestasi di acara ini.</p>
                    @endif
                </x-sth.card>
            @empty
                <x-sth.empty-state icon="award" title="Belum ada acara apresiasi" :level="3">
                    Buat acara, misalnya upacara Senin, lalu masukkan prestasi.
                </x-sth.empty-state>
            @endforelse
        </section>
    @endif
</div>
