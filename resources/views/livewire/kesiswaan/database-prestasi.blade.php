{{-- Kesiswaan · Database prestasi (backlog #4, B4). Data prepared in App\Livewire\Kesiswaan\DatabasePrestasi. --}}
<div class="flex flex-col gap-[22px]">
    @include('livewire.partials.resume-download')
    <section aria-label="Ringkasan" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="award" tone="blue" label="Prestasi" :value="$totals['count']" :note="$active === [] ? 'semua terverifikasi' : 'sesuai filter'" />
        <x-sth.stat-tile icon="users" tone="blue" label="Siswa berprestasi" :value="$totals['students']" note="siswa berbeda" />
        <x-sth.stat-tile icon="trophy" tone="gold" label="Total poin" :value="$totals['points']" note="jumlah poin prestasi" />
        <x-sth.stat-tile icon="check-circle" tone="green" label="Juara I–III" :value="$totals['podium']" note="termasuk medali" />
    </section>

    <x-sth.card padding="none" class="flex min-w-0 flex-col">
        <div class="flex flex-col gap-3 border-b border-line p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Siswa" icon="search" placeholder="Nama atau NIS" maxlength="100" />
                <x-sth.select wire:model.live="tahun" label="Tahun ajaran" placeholder="Semua tahun" :options="$options['tahun']" />
                <x-sth.select wire:model.live="bidang" label="Bidang" placeholder="Semua bidang" :options="$options['bidang']" />
                <x-sth.select wire:model.live="tingkat" label="Tingkat" placeholder="Semua tingkat" :options="$options['tingkat']" />
            </div>
            <details class="group" wire:ignore.self @if ($moreActive > 0) open @endif>
                <summary class="lnk inline-flex cursor-pointer items-center gap-1.5 text-sm">
                    <x-sth.icon name="filter" :size="16" />
                    Filter lainnya @if ($moreActive > 0)<span class="num">({{ $moreActive }} aktif)</span>@endif
                </summary>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-sth.select wire:model.live="kelas" label="Kelas" placeholder="Semua kelas" :options="$options['kelas']" />
                    <x-sth.select wire:model.live="angkatan" label="Angkatan" placeholder="Semua angkatan" :options="$options['angkatan']" />
                    <x-sth.select wire:model.live="jenis" label="Jenis penyelenggara" placeholder="Semua jenis" :options="$options['jenis']" />
                    <x-sth.select wire:model.live="ajang" label="Status ajang" placeholder="Semua status" :options="$options['ajang']" />
                    <x-sth.select wire:model.live="pembimbing" label="Pembimbing" placeholder="Semua pembimbing" :options="$options['pembimbing']" />
                    <x-sth.select wire:model.live="tipe" label="Individu/tim" placeholder="Semua" :options="$options['tipe']" />
                    <x-sth.select wire:model.live="capaian" label="Capaian" placeholder="Semua capaian" :options="$options['capaian']" />
                </div>
            </details>
        </div>

        <div class="flex min-h-14 flex-wrap items-center justify-between gap-3 border-b border-line bg-subtle px-4 py-2.5">
            <div class="flex min-w-0 flex-wrap items-center gap-2" aria-live="polite">
                <span class="num text-sm text-muted">
                    @if ($page->total() > 0)
                        {{ $page->firstItem() }}–{{ $page->lastItem() }} dari {{ $totals['count'] }} prestasi
                    @else
                        0 prestasi
                    @endif
                </span>
                @foreach ($active as $label)
                    <x-sth.chip size="sm" tone="blue">{{ $label }}</x-sth.chip>
                @endforeach
                @if ($active !== [])
                    <x-sth.button variant="quiet" size="sm" icon="x" wire:click="resetFilters">Hapus filter</x-sth.button>
                @endif
            </div>
            @if ($canExport)
                <x-sth.button variant="quiet" size="sm" icon="download" wire:click="export" wire:loading.attr="disabled" wire:target="export"
                              :disabled="$page->total() === 0">Download Excel</x-sth.button>
            @endif
        </div>

        <div wire:loading.class="opacity-70" wire:target="q,tahun,bidang,tingkat,kelas,angkatan,jenis,ajang,pembimbing,tipe,capaian,gotoPage,nextPage,previousPage">
            @if ($rows === [])
                <div class="p-4">
                    <x-sth.empty-state icon="search" title="Tidak ada prestasi" :card="false">
                        {{ $active === [] ? 'Belum ada prestasi yang diverifikasi.' : 'Ubah atau hapus filter.' }}
                    </x-sth.empty-state>
                </div>
            @else
                <x-sth.table caption="Daftar prestasi terverifikasi" :card="false" fixed table-class="min-w-[560px]">
                    <x-slot:head>
                        <th scope="col">Siswa</th>
                        <th scope="col">Lomba</th>
                        <th scope="col" class="hidden w-36 lg:table-cell">Bidang</th>
                        <th scope="col" class="hidden w-28 md:table-cell">Tingkat</th>
                        <th scope="col" class="hidden w-36 sm:table-cell">Capaian</th>
                        <th scope="col" class="hidden w-28 xl:table-cell">Tahun</th>
                        <th scope="col" class="w-20 text-right">Poin</th>
                        <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($rows as $row)
                        <tr wire:key="pr-{{ $row['id'] }}">
                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-sth.avatar :name="$row['name']" size="sm" class="hidden sm:inline-flex" />
                                    <div class="flex min-w-0 flex-col">
                                        <span class="font-bold break-words">{{ $row['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $row['kelas'] }}@if ($row['nis']) · <span class="num">{{ $row['nis'] }}</span>@endif</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex min-w-0 flex-col">
                                    <span class="font-semibold break-words">{{ $row['lomba'] }}</span>
                                    <span class="text-xs text-muted">
                                        {{ collect([$row['organizer'], $row['tipe']])->filter()->implode(' · ') }}
                                        <span class="sm:hidden"> · {{ $row['capaian'] }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="hidden lg:table-cell">@if ($row['bidang'])<x-sth.chip :bidang="$row['bidang']" size="sm" />@endif</td>
                            <td class="hidden text-sm md:table-cell">{{ $row['tingkat'] }}</td>
                            <td class="hidden sm:table-cell"><span @class(['text-sm', 'font-bold' => $row['podium']])>{{ $row['capaian'] }}</span></td>
                            <td class="num hidden text-sm xl:table-cell">{{ $row['tahun'] }}</td>
                            <td class="display num text-right text-base font-bold">{{ $row['points'] }}</td>
                            <td class="text-right">
                                <x-sth.button variant="quiet" size="sm" wire:click="showDetail('{{ $row['id'] }}')" aria-haspopup="dialog">Detail<span class="sr-only"> prestasi {{ $row['name'] }}, {{ $row['lomba'] }}</span></x-sth.button>
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
            @endif
        </div>

        @if ($page->hasPages())
            <div class="border-t border-line px-4 py-3">{{ $page->links() }}</div>
        @endif
    </x-sth.card>

    <x-sth.modal :id="\App\Livewire\Kesiswaan\DatabasePrestasi::DRAWER" title="Detail prestasi" variant="drawer">
        @if ($detail)
            <div class="flex flex-col gap-5">
                <section aria-labelledby="h-dp-siswa" class="flex flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <x-sth.avatar :name="$detail['name']" size="lg" />
                        <h3 id="h-dp-siswa" class="title-card m-0">{{ $detail['name'] }}</h3>
                    </div>
                    <dl class="m-0 grid grid-cols-2 gap-3">
                        @foreach ($detail['student'] as $fact)
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-xs font-semibold text-muted">{{ $fact['label'] }}</dt>
                                <dd class="num m-0 font-bold">{{ $fact['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <div class="h-px bg-line" aria-hidden="true"></div>

                <section aria-labelledby="h-dp-lomba" class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1.5">
                        @if ($detail['bidang'])<x-sth.chip :bidang="$detail['bidang']" size="sm" icon class="self-start" />@endif
                        <h3 id="h-dp-lomba" class="title-card m-0">{{ $detail['lomba'] }}</h3>
                    </div>
                    <dl class="m-0 grid grid-cols-2 gap-3">
                        @foreach ($detail['competition'] as $fact)
                            <div class="flex flex-col gap-0.5">
                                <dt class="text-xs font-semibold text-muted">{{ $fact['label'] }}</dt>
                                <dd class="m-0 font-bold">{{ $fact['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section aria-labelledby="h-dp-hasil" class="flex flex-col gap-3 rounded-tile bg-subtle p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="h-dp-hasil" class="title-card m-0">{{ $detail['capaian'] }}</h3>
                        <x-sth.chip tone="gold" class="num">{{ $detail['pointsLabel'] }} poin</x-sth.chip>
                    </div>
                    <x-sth.rumus-poin :tingkat="$detail['rumus']['tingkat']" :capaian="$detail['rumus']['capaian']"
                        :penyelenggara="$detail['rumus']['penyelenggara']" :total="$detail['rumus']['total']" label="Rumus poin" />
                    <p class="m-0 text-xs text-muted">
                        Diverifikasi {{ $detail['verified'] }}@if ($detail['verifier']) oleh {{ $detail['verifier'] }}@endif.
                        @if ($detail['weightVersion'])Bobot versi {{ $detail['weightVersion'] }}.@endif
                    </p>
                </section>

                <section aria-labelledby="h-dp-tim" class="flex flex-col gap-2.5">
                    <h3 id="h-dp-tim" class="title-card m-0">{{ $detail['tipe'] ?? 'Peserta' }}</h3>
                    @if ($detail['mentor'])
                        <p class="m-0 text-sm"><span class="text-muted">Pembimbing:</span> <span class="font-semibold">{{ $detail['mentor'] }}</span></p>
                    @endif
                    @if (count($detail['members']) > 1)
                        <ul class="m-0 flex list-none flex-col gap-2 p-0">
                            @foreach ($detail['members'] as $member)
                                <li class="flex items-center gap-2.5">
                                    <x-sth.avatar :name="$member['name']" size="sm" />
                                    <span class="flex min-w-0 flex-col">
                                        <span class="font-bold">{{ $member['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $member['meta'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($detail['files'] !== [])
                    <section aria-labelledby="h-dp-file" class="flex flex-col gap-2">
                        <h3 id="h-dp-file" class="title-card m-0">Sertifikat</h3>
                        @foreach ($detail['files'] as $file)
                            @if ($file['preview'])
                                {{-- Preview popup (<x-sth.preview>) on top of this drawer; the href stays the download. --}}
                                <x-sth.button variant="quiet" size="sm" icon="eye" :href="$file['href']" class="self-start" aria-haspopup="dialog"
                                    :data-sth-preview="$file['preview']" :data-sth-preview-type="$file['previewType']"
                                    :data-sth-preview-name="$file['name']"><span class="sr-only">Buka </span>{{ $file['name'] }}</x-sth.button>
                            @else
                                <x-sth.button variant="quiet" size="sm" icon="download" :href="$file['href']" class="self-start"><span class="sr-only">Download </span>{{ $file['name'] }}</x-sth.button>
                            @endif
                        @endforeach
                    </section>
                @endif
            </div>
        @else
            <p class="m-0 text-sm text-muted">Pilih satu prestasi di tabel.</p>
        @endif
    </x-sth.modal>
</div>
