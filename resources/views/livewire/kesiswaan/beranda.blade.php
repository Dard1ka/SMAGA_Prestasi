{{-- Kesiswaan beranda: queue sizes and the next items (no mockup board). Data prepared in App\Livewire\Kesiswaan\Beranda. --}}
<div class="flex flex-col gap-[22px]">
    <section aria-label="Ringkasan" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ($stats as $stat)
            <x-sth.stat-tile :icon="$stat['icon']" :tone="$stat['tone']" :label="$stat['label']" :value="$stat['value']" :note="$stat['note']" :href="$stat['href']" />
        @endforeach
    </section>

    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-3">
        <x-sth.card as="section" aria-labelledby="h-laporan" class="flex flex-col gap-3">
            <x-sth.page-header id="h-laporan" :level="2" title="Laporan hasil">
                <x-slot:actions><x-sth.link :href="route('kesiswaan.verifikasi.index')" icon-right="chevron-right">Buka</x-sth.link></x-slot:actions>
            </x-sth.page-header>
            @forelse ($reports as $item)
                <a href="{{ route('kesiswaan.verifikasi.show', $item['id']) }}" class="flex flex-col gap-0.5 rounded-tile border border-line p-3 text-ink no-underline hover:border-line-input hover:text-ink">
                    <span class="font-bold">{{ $item['title'] }}</span>
                    <span class="text-xs text-muted">{{ $item['sub'] }} · {{ $item['when'] }}</span>
                </a>
            @empty
                <p class="m-0 text-sm text-muted">Tidak ada laporan yang menunggu.</p>
            @endforelse
        </x-sth.card>

        <x-sth.card as="section" aria-labelledby="h-upload" class="flex flex-col gap-3">
            <x-sth.page-header id="h-upload" :level="2" title="Upload lomba">
                <x-slot:actions><x-sth.link :href="route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])" icon-right="chevron-right">Buka</x-sth.link></x-slot:actions>
            </x-sth.page-header>
            @forelse ($uploads as $item)
                <div class="flex flex-col gap-0.5 rounded-tile border border-line p-3">
                    <span class="font-bold">{{ $item['title'] }}</span>
                    <span class="text-xs text-muted">{{ $item['sub'] }} · {{ $item['when'] }}</span>
                </div>
            @empty
                <p class="m-0 text-sm text-muted">Antrean kosong.</p>
            @endforelse
        </x-sth.card>

        <x-sth.card as="section" aria-labelledby="h-batas" class="flex flex-col gap-3">
            <x-sth.page-header id="h-batas" :level="2" title="Batas daftar terdekat" />
            @forelse ($closing as $item)
                <div class="flex items-center gap-3">
                    @if ($item['bidang'])<x-sth.bidang-tile :bidang="$item['bidang']" size="sm" />@endif
                    <span class="flex min-w-0 flex-col">
                        <span class="ellip font-bold">{{ $item['title'] }}</span>
                        <span class="text-xs text-muted">Batas {{ $item['date'] }}</span>
                    </span>
                </div>
            @empty
                <p class="m-0 text-sm text-muted">Belum ada lomba yang dibuka.</p>
            @endforelse
        </x-sth.card>
    </div>
</div>
