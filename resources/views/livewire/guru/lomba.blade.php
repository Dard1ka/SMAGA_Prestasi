{{-- Guru · Lomba (backlog #3, B4): the published catalog, read-only. A guru no longer uploads lomba info (D-145). --}}
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari lomba" hide-label icon="search"
                     placeholder="Nama lomba atau penyelenggara" maxlength="100" class="w-full sm:w-80" />
        <x-sth.select wire:model.live="bidang" label="Bidang" hide-label placeholder="Semua bidang" :options="$bidangOptions" class="w-full sm:w-[190px]" />
    </div>

    <div class="@container flex flex-col gap-4" wire:loading.class="opacity-70">
        @if ($total === 0)
            <x-sth.empty-state icon="search" title="Belum ada lomba yang cocok">
                Ubah pencarian.
            </x-sth.empty-state>
        @else
            <div class="grid grid-cols-1 gap-4 @min-[900px]:grid-cols-2 @min-[1360px]:grid-cols-3">
                @foreach ($cards as $card)
                    <x-sth.lomba-card wire:key="lomba-{{ $card['id'] }}"
                        :title="$card['title']" :href="$card['href']" :bidang="$card['bidang']" :tingkat="$card['tingkat']"
                        :status="$card['status']" :status-tone="$card['statusTone']" :peserta="$card['peserta']"
                        :penyelenggara="$card['penyelenggara']" :date="$card['date']" :place="$card['place']"
                        :names="$card['names']" :count="$card['count']" :note="$card['note']" heading="h2"
                        :seed="$card['id']" :poster="$card['poster']" />
                @endforeach
            </div>
        @endif

        @if ($hasMore)
            <div class="flex justify-center">
                <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
            </div>
        @endif
    </div>

    @if ($detail)
        <x-sth.modal id="lomba-detail" :title="$detail['name']" variant="drawer" open wire:key="detail-{{ $detail['id'] }}">
            <div class="flex flex-col gap-5">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($detail['bidang'])<x-sth.chip :bidang="$detail['bidang']" icon />@endif
                    @if ($detail['published'])
                        <x-sth.chip :tone="$detail['window']['tone']">{{ $detail['window']['label'] }}</x-sth.chip>
                    @else
                        <x-sth.chip :tone="$detail['status']->tone()">{{ $detail['status']->label() }}</x-sth.chip>
                    @endif
                </div>

                @if ($detail['note'])
                    <x-sth.alert tone="error" :title="'Ditolak '.$detail['rejectedBy']">{{ $detail['note'] }}</x-sth.alert>
                @endif

                <dl class="m-0 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($detail['facts'] as $fact)
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-xs font-semibold text-muted">{{ $fact['label'] }}</dt>
                            <dd class="m-0 font-bold">{{ $fact['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($detail['description'])
                    <p class="m-0 text-md whitespace-pre-line">{{ $detail['description'] }}</p>
                @endif

                @if ($detail['juknis'] || $detail['website'])
                    <div class="flex flex-col gap-2">
                        @if ($detail['juknis'])
                            <x-sth.button variant="quiet" icon="download" :href="$detail['juknis']['href']">Download juknis · {{ $detail['juknis']['size'] }}</x-sth.button>
                        @endif
                        @if ($detail['website'])
                            <span class="text-sm"><x-sth.link :href="$detail['website']" external icon-right="external">Website resmi</x-sth.link> <span class="text-muted">({{ $detail['websiteHost'] }})</span></span>
                        @endif
                    </div>
                @endif

                <section aria-labelledby="h-binaan-lomba" class="flex flex-col gap-2">
                    <h3 id="h-binaan-lomba" class="title-card m-0">Siswa binaan di lomba ini</h3>
                    @if ($detail['binaan'] === [])
                        <p class="m-0 text-sm text-muted">Belum ada.</p>
                    @else
                        <ul class="m-0 flex list-none flex-col gap-1 p-0">
                            @foreach ($detail['binaan'] as $person)
                                <li><a href="{{ $person['href'] }}" class="flex items-center gap-2.5 rounded-tile p-2 hover:bg-hover">
                                    <x-sth.avatar :name="$person['name']" size="sm" />
                                    <span class="font-bold text-ink">{{ $person['name'] }}</span>
                                    @if ($person['kelas'])<span class="text-xs text-muted">{{ $person['kelas'] }}</span>@endif
                                </a></li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <x-sth.button variant="quiet" :href="$closeUrl">Tutup</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @endif
</div>
