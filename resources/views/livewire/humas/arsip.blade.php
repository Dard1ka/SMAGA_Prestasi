{{-- Humas · Arsip publikasi. Data is prepared in App\Livewire\Humas\Arsip. --}}
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari siswa atau lomba" hide-label icon="search" placeholder="Cari siswa atau lomba" class="w-full sm:w-80" />
        <p class="m-0 text-sm text-muted"><span class="num font-semibold text-ink">{{ $total }}</span> publikasi</p>
    </div>

    @if ($rows === [])
        @if ($q !== '')
            <x-sth.empty-state icon="search" title="Tidak ada publikasi yang cocok">
                Coba kata lain, misalnya nama siswa atau nama lomba.
                <x-slot:actions>
                    <x-sth.button variant="quiet" size="sm" wire:click="clearSearch">Hapus pencarian</x-sth.button>
                </x-slot:actions>
            </x-sth.empty-state>
        @else
            <x-sth.empty-state icon="archive" title="Belum ada publikasi yang terbit">
                Publikasi yang ditandai terbit akan tersimpan di sini.
            </x-sth.empty-state>
        @endif
    @else
        <x-sth.table caption="Arsip publikasi prestasi, {{ $total }} publikasi">
            <x-slot:head>
                <th scope="col">Lomba</th>
                <th scope="col">Siswa</th>
                <th scope="col">Capaian</th>
                <th scope="col">Kanal</th>
                <th scope="col">Terbit</th>
                <th scope="col"><span class="sr-only">Lihat</span></th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr wire:key="arsip-{{ $row['id'] }}">
                    <td class="font-semibold">{{ $row['competition'] }}</td>
                    <td>
                        <span class="block">{{ $row['student'] }}</span>
                        @if ($row['class'])
                            <span class="block text-[13px] text-muted">{{ $row['class'] }}</span>
                        @endif
                        @if ($row['consentWithdrawn'])
                            <x-sth.chip tone="orange" size="sm" class="mt-1">Izin dicabut</x-sth.chip>
                        @endif
                    </td>
                    <td><x-sth.chip tone="gold" size="sm">{{ $row['headline'] }}</x-sth.chip></td>
                    <td class="text-ink-2">{{ $row['channels'] }}</td>
                    <td class="num whitespace-nowrap">{{ $row['published'] }}</td>
                    <td class="text-right">
                        @if ($row['href'])
                            <x-sth.link :href="$row['href']">Lihat<span class="sr-only"> publikasi {{ $row['competition'] }}</span></x-sth.link>
                        @else
                            <span class="text-muted">–</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-sth.table>

        @if ($page->hasPages())
            <nav aria-label="Halaman arsip" class="flex flex-wrap items-center justify-between gap-3">
                <x-sth.button variant="quiet" size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$page->onFirstPage()">Sebelumnya</x-sth.button>
                <span class="num text-sm text-muted">Halaman {{ $page->currentPage() }} dari {{ $page->lastPage() }}</span>
                <x-sth.button variant="quiet" size="sm" icon-right="chevron-right" wire:click="nextPage" :disabled="! $page->hasMorePages()">Berikutnya</x-sth.button>
            </nav>
        @endif
    @endif
</div>
