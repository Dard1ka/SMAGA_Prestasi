{{-- Siswa · Cari lomba (board Katalog-Lomba). Data prepared in App\Livewire\Siswa\Lomba\Katalog + LombaPresenter. --}}
<div class="flex flex-col gap-[18px]">
    <x-slot:actions>
        <x-sth.button variant="quiet" icon="plus" data-sth-open="usul" aria-haspopup="dialog">Usulkan lomba</x-sth.button>
    </x-slot:actions>

    <section aria-label="Filter lomba" class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div role="group" aria-label="Bidang" class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0 sm:pb-0">
            @foreach ($bidangOptions as $value => $label)
                <button type="button" class="fchip shrink-0" aria-pressed="{{ $bidang === $value ? 'true' : 'false' }}"
                        wire:click="pilihBidang('{{ $value }}')">{{ $label }}</button>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center">
            <x-sth.select wire:model.live="tingkat" id="f-tingkat" label="Tingkat" hide-label size="sm"
                          placeholder="Semua tingkat" :options="$tingkatOptions" class="sm:w-44" />
            <x-sth.select wire:model.live="status" id="f-status" label="Status pendaftaran" hide-label size="sm"
                          placeholder="Semua status" :options="$statusOptions" class="sm:w-44" />
            <x-sth.input wire:model.live.debounce.400ms="q" id="f-cari" type="search" label="Cari nama lomba" hide-label
                         icon="search" placeholder="Nama lomba" maxlength="100" class="col-span-2 sm:w-60" />
        </div>
    </section>

    <p class="m-0 flex items-baseline gap-2" aria-live="polite">
        <span class="display num text-xl font-extrabold">{{ $page->total() }} lomba</span>
        @if ($page->total() > count($cards))
            <span class="num text-sm text-muted">Menampilkan {{ $page->firstItem() }}–{{ $page->lastItem() }} dari {{ $page->total() }}</span>
        @endif
    </p>

    @if ($cards === [])
        <x-sth.empty-state icon="search" :title="$filtered ? 'Belum ada lomba yang cocok' : 'Belum ada lomba di katalog'">
            {{ $filtered ? 'Coba ubah filter, atau usulkan lomba baru.' : 'Usulkan lomba yang ingin kamu ikuti.' }}
            <x-slot:actions>
                @if ($filtered)
                    <x-sth.button variant="quiet" size="sm" wire:click="hapusFilter">Hapus filter</x-sth.button>
                @endif
                <x-sth.button variant="soft" size="sm" data-sth-open="usul" aria-haspopup="dialog">Usulkan lomba</x-sth.button>
            </x-slot:actions>
        </x-sth.empty-state>
    @else
        <div class="grid grid-cols-1 gap-[18px] sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $card)
                <x-sth.lomba-card wire:key="lomba-{{ $card['id'] }}"
                    :title="$card['title']" :href="$card['href']" :bidang="$card['bidang']" :tingkat="$card['tingkat']"
                    :status="$card['status']" :status-tone="$card['statusTone']" :peserta="$card['peserta']"
                    :penyelenggara="$card['penyelenggara']" :date="$card['date']" :place="$card['place']"
                    :names="$card['names']" :count="$card['count']" :note="$card['note']" />
            @endforeach
        </div>

        @if ($page->hasPages())
            <nav aria-label="Halaman katalog" class="flex items-center justify-between gap-3">
                <x-sth.button variant="quiet" size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$page->onFirstPage()">Sebelumnya</x-sth.button>
                <span class="num text-sm font-semibold text-ink-2">Halaman {{ $page->currentPage() }} dari {{ $page->lastPage() }}</span>
                <x-sth.button variant="quiet" size="sm" icon-right="chevron-right" wire:click="nextPage" :disabled="! $page->hasMorePages()">Berikutnya</x-sth.button>
            </nav>
        @endif
    @endif

    <livewire:lomba.usulan-form />
</div>
