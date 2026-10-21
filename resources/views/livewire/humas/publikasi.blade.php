{{-- Humas · Antrean publikasi (UI-MAP #22, board Humas-Publikasi). Data is prepared in App\Livewire\Humas\Publikasi. --}}
<div class="flex flex-col gap-5">
    @if ($q !== '')
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-muted">Hasil pencarian untuk</span>
            <x-sth.chip tone="blue">{{ $q }}</x-sth.chip>
            <x-sth.button variant="quiet" size="sm" icon="x" wire:click="clearSearch">Hapus pencarian</x-sth.button>
        </div>
    @endif

    @if ($boardEmpty && $panel === null)
        @if ($q !== '')
            <x-sth.empty-state icon="search" title="Tidak ada prestasi yang cocok">
                Coba kata lain, misalnya nama siswa atau nama lomba.
            </x-sth.empty-state>
        @else
            <x-sth.empty-state icon="megaphone" title="Belum ada prestasi untuk dipublikasikan">
                Prestasi yang sudah diverifikasi akan muncul di sini.
            </x-sth.empty-state>
        @endif
    @else
        <div class="flex flex-col gap-5 xl:flex-row xl:items-start">
            <section aria-label="Papan publikasi" class="grid grid-cols-1 min-w-0 flex-1 gap-4 md:grid-cols-3 md:items-start md:gap-3">
                @foreach ($lanes as $lane)
                    <div class="flex min-w-0 flex-col gap-2.5" wire:key="lane-{{ $lane['key'] }}">
                        <div class="flex h-8 items-center gap-2 px-0.5">
                            <span class="size-2 shrink-0 rounded-full {{ $lane['dot'] }}" aria-hidden="true"></span>
                            <h2 class="m-0 text-base leading-5 font-bold">{{ $lane['title'] }}</h2>
                            <x-sth.chip :tone="$lane['tone']" size="sm" class="num">{{ $lane['count'] }}</x-sth.chip>
                        </div>

                        @forelse ($lane['cards'] as $card)
                            @include('livewire.humas.partials.publication-card', ['card' => $card])
                        @empty
                            <p class="m-0 rounded-card border border-dashed border-line-dashed px-4 py-6 text-center text-sm text-muted">{{ $lane['empty'] }}</p>
                        @endforelse

                        @if ($lane['more'] > 0)
                            <x-sth.button variant="quiet" size="sm" block icon="chevron-down" wire:click="tampilkanLebih">Tampilkan lebih banyak ({{ $lane['more'] }} lagi)</x-sth.button>
                        @endif
                    </div>
                @endforeach

                <div class="flex min-w-0 flex-col gap-2.5" wire:key="lane-dipublikasikan">
                    <div class="flex h-8 items-center gap-2 px-0.5">
                        <span class="size-2 shrink-0 rounded-full bg-green" aria-hidden="true"></span>
                        <h2 class="m-0 text-base leading-5 font-bold">Dipublikasikan</h2>
                        <x-sth.chip tone="green" size="sm" class="num">{{ $publishedCount }}</x-sth.chip>
                    </div>

                    @forelse ($published as $card)
                        <article wire:key="pub-{{ $card['id'] }}" @class([
                            'card relative flex flex-col gap-1 [overflow-wrap:anywhere] border-l-4 py-3 pr-3 pl-3.5 has-[button:focus-visible]:outline-3 has-[button:focus-visible]:outline-offset-2 has-[button:focus-visible]:outline-focus',
                            $card['border'],
                            'border-2 border-primary ring-4 ring-primary-tint' => $card['selected'],
                        ])>
                            <h3 class="m-0 text-md leading-snug font-bold">
                                <button type="button" wire:click="select('{{ $card['id'] }}')" class="cursor-pointer text-left outline-none after:absolute after:inset-0 after:content-['']" @if ($card['selected']) aria-current="true" @endif>
                                    {{ $card['title'] }}
                                </button>
                            </h3>
                            <p class="m-0 text-xs leading-snug text-muted">{{ $card['student'] }} · {{ $card['result'] }}</p>
                            <p class="m-0 mt-0.5 flex items-center gap-1.5 text-xs font-semibold text-green">
                                <x-sth.icon name="check" :size="14" />{{ $card['publishedOn'] }}
                            </p>
                        </article>
                    @empty
                        <p class="m-0 rounded-card border border-dashed border-line-dashed px-4 py-6 text-center text-sm text-muted">Belum ada yang terbit.</p>
                    @endforelse

                    @if ($publishedMore > 0)
                        <x-sth.button variant="quiet" size="sm" block icon-right="chevron-right" :href="route('humas.arsip')">Lihat {{ $publishedMore }} lainnya</x-sth.button>
                    @endif
                </div>
            </section>

            <section class="card flex w-full min-w-0 flex-col gap-3 p-[18px] xl:w-[440px] xl:shrink-0" aria-labelledby="pub-judul">
                @if ($panel === null)
                    <h2 id="pub-judul" class="sr-only">Siapkan publikasi</h2>
                    <x-sth.empty-state icon="megaphone" title="Pilih prestasi di papan" :card="false" :level="3">
                        Data untuk flyer, caption, dan jadwal publikasi tampil di sini.
                    </x-sth.empty-state>
                @else
                    @include('livewire.humas.partials.publication-panel', ['panel' => $panel])
                @endif
            </section>
        </div>
    @endif

    {{--
        "Tandai dipublikasikan" asks first (askPublish() checks caption + channels, then opens this; publish() is the
        confirm step). It cannot be undone and tells the student, so the line under the title says who is told
        (D-109: never a notice nobody gets). Always pass a description: the <dialog> has wire:ignore.self.
    --}}
    <x-sth.modal :id="\App\Livewire\Humas\Publikasi::PUBLISH_DIALOG" title="Tandai sudah terbit?" :description="$publishing['notice'] ?? \App\Livewire\Humas\Publikasi::NOTICE_TOLD" size="sm">
        @if ($publishing)
            <div class="flex flex-col gap-3">
                <div class="rounded-tile bg-subtle px-3 py-2.5 [overflow-wrap:anywhere]">
                    <p class="m-0 font-semibold">{{ $publishing['name'] }}</p>
                    <p class="m-0 text-xs text-muted">{{ $publishing['who'] }}</p>
                    @if ($publishing['channels'] !== '')
                        <p class="m-0 text-xs text-muted">Kanal: {{ $publishing['channels'] }}</p>
                    @endif
                </div>
                @if ($publishing['team'] !== [])
                    {{-- D-160: the teammates' cards are published in the same step, each student told. --}}
                    <p class="m-0 text-md [overflow-wrap:anywhere]">Kartu anggota tim lain ikut ditandai terbit: {{ implode(', ', $publishing['team']) }}.</p>
                @endif
                <p class="m-0 text-md">Status menjadi Dipublikasikan dan tidak bisa dikembalikan ke antrean.</p>
            </div>
        @else
            <p class="m-0 text-md text-muted">Tidak ada publikasi yang dipilih. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($publishing)
                <x-sth.button icon="check" wire:click="publish" wire:loading.attr="disabled" wire:target="publish">Tandai dipublikasikan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
