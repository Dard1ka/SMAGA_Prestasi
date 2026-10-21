{{--
    Humas · Arsip foto (D-156). Data is prepared in App\Livewire\Humas\ArsipFoto: verified reports whose whole team may
    be named (no disabled or deleted account; no consent since D-162), each with its photos. "Lihat" opens the preview popup (<x-sth.preview>), the href and
    "Download" are the file itself (files.show, audited).
--}}
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari siswa atau lomba" hide-label icon="search" placeholder="Cari siswa atau lomba" class="w-full sm:w-80" />
        <p class="m-0 text-sm text-muted"><span class="num font-semibold text-ink">{{ $total }}</span> laporan dengan foto</p>
    </div>

    @if ($hidden > 0)
        <p class="m-0 text-xs text-muted">
            {{ $hidden }} laporan tidak ditampilkan karena akun salah satu anggotanya nonaktif.
        </p>
    @endif

    @if ($rows === [])
        @if ($searching)
            <x-sth.empty-state icon="search" title="Tidak ada foto yang cocok">
                Coba kata lain, misalnya nama siswa atau nama lomba.
                <x-slot:actions>
                    <x-sth.button variant="quiet" size="sm" wire:click="clearSearch">Hapus pencarian</x-sth.button>
                </x-slot:actions>
            </x-sth.empty-state>
        @else
            <x-sth.empty-state icon="image" title="Belum ada foto dokumentasi">
                Foto dari laporan hasil siswa muncul di sini setelah laporannya diverifikasi.
            </x-sth.empty-state>
        @endif
    @else
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($rows as $row)
                <x-sth.card as="article" wire:key="foto-laporan-{{ $row['id'] }}" class="flex min-w-0 flex-col gap-3" aria-labelledby="foto-judul-{{ $row['id'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5 [overflow-wrap:anywhere]">
                            <h2 id="foto-judul-{{ $row['id'] }}" class="m-0 text-md font-bold">{{ $row['competition'] }}</h2>
                            <p class="muted m-0 text-xs">
                                {{ collect([$row['branch'], $row['level'] ? 'Tingkat '.$row['level'] : null, 'Diverifikasi '.$row['verified']])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        @if ($row['result'])
                            <x-sth.chip tone="gold" size="sm">{{ $row['result'] }}</x-sth.chip>
                        @endif
                    </div>

                    <p class="m-0 text-sm [overflow-wrap:anywhere]">{{ implode(', ', $row['students']) }}</p>

                    <ul class="m-0 flex list-none flex-col gap-2 p-0" aria-label="Foto {{ $row['competition'] }}">
                        @foreach ($row['photos'] as $photo)
                            <li wire:key="foto-{{ $photo['id'] }}" class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-subtle text-muted" aria-hidden="true"><x-sth.icon name="image" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="text-sm font-semibold [overflow-wrap:anywhere]">{{ $photo['name'] }}</span>
                                    <span class="muted text-xs">{{ $photo['size'] }}</span>
                                </span>
                                <div class="flex flex-wrap gap-2">
                                    @if ($photo['preview'])
                                        <x-sth.button variant="quiet" size="sm" icon="eye" :href="$photo['href']" :navigate="false" aria-haspopup="dialog"
                                            :data-sth-preview="$photo['preview']" :data-sth-preview-type="$photo['previewType']"
                                            :data-sth-preview-name="$photo['name']">Lihat<span class="sr-only"> {{ $photo['name'] }}</span></x-sth.button>
                                    @endif
                                    <x-sth.button variant="quiet" size="sm" icon="download" :href="$photo['href']" :navigate="false">Download<span class="sr-only"> {{ $photo['name'] }}</span></x-sth.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-sth.card>
            @endforeach
        </div>

        @if ($page->hasPages())
            <nav aria-label="Halaman arsip foto" class="flex flex-wrap items-center justify-between gap-3">
                <x-sth.button variant="quiet" size="sm" icon="chevron-left" wire:click="previousPage" :disabled="$page->onFirstPage()">Sebelumnya</x-sth.button>
                <span class="num text-sm text-muted">Halaman {{ $page->currentPage() }} dari {{ $page->lastPage() }}</span>
                <x-sth.button variant="quiet" size="sm" icon-right="chevron-right" wire:click="nextPage" :disabled="! $page->hasMorePages()">Berikutnya</x-sth.button>
            </nav>
        @endif
    @endif
</div>
