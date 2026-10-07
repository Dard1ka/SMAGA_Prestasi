{{-- Lomba saya (UI-MAP #12, board Lomba-Saya). Data prepared in App\Livewire\Siswa\LombaSaya\Index; view only formats. --}}
<div class="grid items-start gap-5 lg:grid-cols-[minmax(300px,380px)_minmax(0,1fr)]">
    {{-- List: always on desktop; on phones only when no lomba was opened. --}}
    <section aria-label="Daftar pendaftaran lomba" @class(['flex min-w-0 flex-col gap-3', 'hidden lg:flex' => $explicit])>
        <nav class="seg seg-block" aria-label="Status lomba">
            @foreach (['berjalan' => 'Berjalan', 'selesai' => 'Selesai'] as $key => $label)
                <a href="{{ route('siswa.lomba-saya.index', $key === 'berjalan' ? [] : ['tab' => $key]) }}" wire:navigate
                   @class(['seg-btn', 'seg-on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                    {{ $label }}<span class="cnt num">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>

        @if ($cards === [])
            <x-sth.empty-state icon="clipboard" :title="$tab === 'selesai' ? 'Belum ada lomba selesai' : 'Belum ada lomba berjalan'">
                Cari lomba di katalog lalu daftar sebagai individu atau tim.
                <x-slot:actions>
                    @if (Route::has('siswa.lomba.index'))
                        <x-sth.button size="sm" icon="search" :href="route('siswa.lomba.index')">Cari lomba</x-sth.button>
                    @endif
                </x-slot:actions>
            </x-sth.empty-state>
        @else
            <ul class="m-0 flex list-none flex-col gap-3 p-0">
                @foreach ($cards as $card)
                    <li wire:key="reg-{{ $card['id'] }}">
                        <a href="{{ $card['href'] }}" wire:navigate @if ($card['current']) aria-current="true" @endif
                           @class([
                               'card flex flex-col gap-3 p-4 text-ink no-underline transition hover:border-primary-soft',
                               'lg:border-primary lg:ring-2 lg:ring-primary-tint' => $card['current'],
                           ])>
                            <span class="flex items-center gap-3">
                                @if ($card['bidang'])
                                    <x-sth.bidang-tile :bidang="$card['bidang']" size="sm" />
                                @endif
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="display ellip text-base font-bold">{{ $card['title'] }}</span>
                                    <span class="muted text-xs">{{ $card['meta'] }}</span>
                                </span>
                                <x-sth.icon name="chevron-right" :size="18" class="text-muted" />
                            </span>
                            <x-sth.tracker :status="$card['status']" compact />
                            <span><x-sth.chip :tone="$card['tone']" size="sm">{{ $card['chip'] }}</x-sth.chip></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Detail pane: desktop always (first item by default); phones only on /lomba-saya/{registration}. --}}
    <section aria-labelledby="detail-judul" @class(['flex min-w-0 flex-col gap-4', 'hidden lg:flex' => ! $explicit])>
        @if ($detail === null)
            <x-sth.empty-state icon="clipboard" title="Pilih lomba">Detail pendaftaran tampil di sini.</x-sth.empty-state>
        @else
            @php($c = $detail['competition'])
            <div class="lg:hidden">
                <x-sth.link :href="route('siswa.lomba-saya.index', $detail['status']->isFinal() ? ['tab' => 'selesai'] : [])" icon="chevron-left">Lomba saya</x-sth.link>
            </div>

            <x-sth.card as="article" class="flex flex-col gap-4">
                <div class="flex items-start gap-3.5">
                    @if ($c->bidang)
                        <x-sth.bidang-tile :bidang="$c->bidang" size="lg" />
                    @endif
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <h2 id="detail-judul" class="title-section">{{ $c->name }}</h2>
                        @if ($detail['sub'] !== '')
                            <p class="muted m-0 text-sm">{{ $detail['sub'] }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-ink-2">
                    <span class="flex flex-wrap gap-2">
                        @if ($c->bidang)
                            <x-sth.chip :bidang="$c->bidang" />
                        @endif
                        @if ($c->level)
                            <x-sth.chip>{{ $c->level->shortLabel() }}</x-sth.chip>
                        @endif
                        <x-sth.chip>{{ $detail['typeLabel'] }}</x-sth.chip>
                        <x-sth.chip :tone="$detail['tone']">{{ $detail['chip'] }}</x-sth.chip>
                    </span>
                    @if ($detail['dateText'] !== '')
                        <span class="inline-flex items-center gap-1.5"><x-sth.icon name="calendar" :size="16" />{{ $detail['dateText'] }}</span>
                    @endif
                    @if ($detail['place'])
                        <span class="inline-flex items-center gap-1.5"><x-sth.icon name="pin" :size="16" />{{ $detail['place'] }}</span>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <x-sth.tracker :status="$detail['status']" :dates="$detail['trackerDates']" />
                </div>
            </x-sth.card>

            {{-- Invitation (W2-A1 handles confirm/decline). --}}
            @if ($detail['pendingInvite'])
                <x-sth.alert tone="warning" title="Kamu diundang ke tim ini">
                    Konfirmasi ikut agar pendaftaran bisa dikirim ke Kepala Sekolah.
                    @if ($detail['inviteUrl'])
                        <x-sth.link :href="$detail['inviteUrl']" icon-right="chevron-right">Lihat undangan</x-sth.link>
                    @endif
                </x-sth.alert>
            @endif

            @include('livewire.siswa.lomba-saya.partials.status-card', ['d' => $detail])

            <div class="grid gap-4 xl:grid-cols-2">
                @include('livewire.siswa.lomba-saya.partials.letters-card', ['d' => $detail])
                @include('livewire.siswa.lomba-saya.partials.team-card', ['d' => $detail])
            </div>
        @endif
    </section>

    @if ($detail !== null && $detail['canCancel'])
        <x-sth.modal id="batal-daftar" title="Batalkan pendaftaran?" description="Pendaftaran yang dibatalkan tidak bisa dipulihkan. Kamu bisa mendaftar lagi selama pendaftaran lomba masih dibuka." size="sm">
            <p class="m-0 text-sm">{{ $detail['competition']->name }}</p>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
                <x-sth.button variant="danger" wire:click="cancel" wire:loading.attr="disabled">Batalkan pendaftaran</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @endif
</div>
