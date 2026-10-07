{{-- Guru · Lomba (backlog #3, B4): katalog (read-only) + upload saya. Uploads use W2-A1's Lomba\UsulanForm (<dialog id="usul">). --}}
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="seg flex-wrap" role="group" aria-label="Tampilkan">
            <button type="button" class="seg-btn" wire:click="setTab('katalog')" aria-pressed="{{ $isCatalog ? 'true' : 'false' }}">
                Katalog <span class="cnt">{{ $counts['katalog'] }}</span>
            </button>
            <button type="button" class="seg-btn" wire:click="setTab('unggahan')" aria-pressed="{{ $isCatalog ? 'false' : 'true' }}">
                Upload saya <span class="cnt">{{ $counts['unggahan'] }}</span>
            </button>
        </div>
        @if ($canUpload)
            <x-sth.button icon="plus" data-sth-open="usul" aria-haspopup="dialog">Upload info lomba</x-sth.button>
        @endif
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari lomba" hide-label icon="search"
                     :placeholder="$isCatalog ? 'Nama lomba atau penyelenggara' : 'Nama lomba'" maxlength="100" class="w-full sm:w-80" />
        @if ($isCatalog)
            <x-sth.select wire:model.live="bidang" label="Bidang" hide-label placeholder="Semua bidang" :options="$bidangOptions" class="w-full sm:w-[190px]" />
        @elseif ($counts['menunggu'] > 0)
            <x-sth.chip tone="gray" icon="clock" class="num">{{ $counts['menunggu'] }} menunggu verifikasi Kesiswaan</x-sth.chip>
        @endif
    </div>

    <div class="flex flex-col gap-4" wire:loading.class="opacity-70">
        @if ($total === 0)
            @if ($isCatalog)
                <x-sth.empty-state icon="search" title="Belum ada lomba yang cocok">
                    Ubah pencarian, atau upload info lomba baru.
                </x-sth.empty-state>
            @else
                <x-sth.empty-state icon="upload" title="Belum ada info lomba yang di-upload">
                    Kesiswaan memeriksa setiap upload sebelum tampil di katalog.
                    @if ($canUpload)
                        <x-slot:actions>
                            <x-sth.button variant="soft" size="sm" data-sth-open="usul" aria-haspopup="dialog">Upload info lomba</x-sth.button>
                        </x-slot:actions>
                    @endif
                </x-sth.empty-state>
            @endif
        @elseif ($isCatalog)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($cards as $card)
                    <x-sth.lomba-card wire:key="lomba-{{ $card['id'] }}"
                        :title="$card['title']" :href="$card['href']" :bidang="$card['bidang']" :tingkat="$card['tingkat']"
                        :status="$card['status']" :status-tone="$card['statusTone']" :peserta="$card['peserta']"
                        :penyelenggara="$card['penyelenggara']" :date="$card['date']" :place="$card['place']"
                        :names="$card['names']" :count="$card['count']" :note="$card['note']" heading="h2"
                        :seed="$card['id']" :poster="$card['poster']" />
                @endforeach
            </div>
        @else
            <x-sth.table caption="Info lomba yang di-upload, {{ $total }} lomba" fixed table-class="min-w-[520px]">
                <x-slot:head>
                    <th scope="col">Lomba</th>
                    <th scope="col" class="hidden w-32 sm:table-cell">Dikirim</th>
                    <th scope="col" class="w-44">Status</th>
                    <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                </x-slot:head>
                @foreach ($uploads as $row)
                    <tr wire:key="up-{{ $row['id'] }}">
                        <td>
                            <div class="flex min-w-0 flex-col">
                                <span class="font-bold break-words">{{ $row['name'] }}</span>
                                <span class="text-xs text-muted">{{ $row['organizer'] ?: 'Penyelenggara belum diisi' }}<span class="sm:hidden"> · {{ $row['sent'] }}</span></span>
                                @if ($row['note'])
                                    <span class="text-xs text-red">Catatan: {{ $row['note'] }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="num hidden text-sm sm:table-cell">{{ $row['sent'] }}</td>
                        <td>
                            <div class="flex flex-col items-start gap-1">
                                <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['status'] }}</x-sth.chip>
                                @if ($row['checked'])<span class="num text-xs text-muted">Diperiksa {{ $row['checked'] }}</span>@endif
                            </div>
                        </td>
                        <td class="text-right"><x-sth.link :href="$row['href']">Lihat<span class="sr-only"> {{ $row['name'] }}</span></x-sth.link></td>
                    </tr>
                @endforeach
            </x-sth.table>
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
                    <x-sth.alert tone="error" title="Ditolak Kesiswaan">{{ $detail['note'] }}</x-sth.alert>
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

    @if ($canUpload)
        <livewire:lomba.usulan-form :upload="true" />
    @endif
</div>
