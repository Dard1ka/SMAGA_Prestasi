{{--
    Publik · Peringkat kumulatif (board `Peringkat`, D-09). Rows come ranked from PublicAchievements::ranking().
    Filters: mode siswa | kelas, bidang, angkatan and who is shown (siswa aktif | alumni | semua, siswa mode only).
    An alumnus shows the chip "Alumni" where the class stands, never the last class (lead, 2026-10-04).
--}}
@php
    $isSiswa = $mode === 'siswa';
    $shown = count($top) + count($rest);
    $caption = 'Peringkat '.$noun;
@endphp
<div class="flex flex-col gap-5">
    <x-sth.page-header :level="1" title="Peringkat prestasi" subtitle="Akumulasi poin dari semua lomba yang sudah diverifikasi." />

    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_400px] xl:grid-cols-[minmax(0,1fr)_456px]">
        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="seg seg-solid border border-line-input bg-surface" role="group" aria-label="Tampilan peringkat">
                    <button type="button" class="seg-btn h-9" aria-pressed="{{ $isSiswa ? 'true' : 'false' }}" wire:click="pilihMode('siswa')">Siswa</button>
                    <button type="button" @class(['seg-btn h-9', 'cursor-not-allowed text-placeholder' => ! $kelasAvailable]) aria-pressed="{{ $isSiswa ? 'false' : 'true' }}"
                        wire:click="pilihMode('kelas')" @if (! $kelasAvailable) disabled aria-describedby="peringkat-catatan" @endif>Kelas</button>
                </div>
                <div class="grid w-full grid-cols-2 gap-3 sm:flex sm:w-auto">
                    <x-sth.select wire:model.live="bidang" label="Bidang" hide-label size="sm" placeholder="Semua bidang" :options="$bidangOptions" class="sm:w-[190px]" />
                    <x-sth.select wire:model.live="angkatan" label="Angkatan" hide-label size="sm" placeholder="Semua angkatan" :options="$angkatanOptions" class="sm:w-[236px]" />
                </div>
                @if ($isSiswa)
                    <div class="seg" role="group" aria-label="Siswa yang ditampilkan">
                        @foreach ($statusOptions as $value => $label)
                            <button type="button" class="seg-btn h-9" wire:key="status-{{ $value }}" aria-pressed="{{ $status === $value ? 'true' : 'false' }}" wire:click="pilihStatus('{{ $value }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            @if ($note)
                <p id="peringkat-catatan" class="m-0 text-xs text-muted">{{ $note }}</p>
            @endif

            <div class="flex flex-col gap-4" wire:loading.class="opacity-70">
                @if ($total === 0)
                    <x-sth.empty-state icon="bars" title="Belum ada data untuk filter ini.">{{ $noun === 'alumni' ? 'Ganti bidang atau angkatan, atau pilih Semua.' : 'Ganti bidang atau angkatan.' }}</x-sth.empty-state>
                @else
                    <ol class="m-0 grid grid-cols-1 list-none gap-4 p-0 sm:grid-cols-3" aria-label="{{ $caption }}, tiga teratas">
                        @foreach ($top as $row)
                            <li wire:key="top-{{ $mode }}-{{ $row['position'] }}">
                                {{-- One row: medal (gold, silver, bronze) + who; below a line: points left, count right. --}}
                                <article class="card flex h-full min-w-0 flex-col p-[18px]">
                                    <div class="flex min-w-0 items-start gap-3.5">
                                        <span @class(['display num inline-flex size-11 shrink-0 items-center justify-center rounded-full text-lg font-bold text-ink', 'bg-medal' => $row['rank'] === 1, 'bg-medal-2' => $row['rank'] === 2, 'bg-medal-3' => $row['rank'] === 3, 'bg-gray-bg' => $row['rank'] > 3])><span class="sr-only">Peringkat </span>{{ $row['rank'] }}</span>
                                        @if ($isSiswa)
                                            <div class="flex min-w-0 flex-col gap-0.5">
                                                @include('livewire.publik.partials.person-name', ['person' => $row, 'class' => 'text-lg leading-6', 'wrap' => true])
                                                @if ($row['alumni'] && $row['named'])
                                                    <span class="text-sm text-muted">Alumni</span>
                                                @elseif ($row['kelas'])
                                                    <span class="text-sm text-muted">{{ $row['kelas'] }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <div class="flex min-w-0 flex-col gap-0.5">
                                                <span class="text-lg leading-6 font-bold">Kelas {{ $row['kelas'] }}</span>
                                                <span class="text-sm text-muted">{{ $row['angkatan'] ? 'Angkatan '.$row['angkatan'].' · ' : '' }}{{ $row['students'] }} siswa berprestasi</span>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mt-auto pt-4"><div class="flex items-baseline justify-between gap-3 border-t border-line-soft pt-3">
                                        <span class="flex items-baseline gap-1.5">
                                            <span class="display num text-3xl leading-none font-bold">{{ $row['points_label'] }}</span>
                                            <span class="text-sm font-semibold text-muted">poin</span>
                                        </span>
                                        <span class="num text-sm text-muted">{{ $row['count'] }} prestasi</span>
                                    </div></div>
                                </article>
                            </li>
                        @endforeach
                    </ol>

                    @if ($rest !== [])
                        <x-sth.table :caption="$caption.', urutan 4 sampai '.$shown.' dari '.$total">
                            <x-slot:head>
                                <th scope="col" class="w-20 sm:w-24">Peringkat</th>
                                @if ($isSiswa)
                                    <th scope="col">Siswa</th>
                                    <th scope="col" class="hidden w-24 sm:table-cell">Kelas</th>
                                    <th scope="col" class="hidden w-24 sm:table-cell">Prestasi</th>
                                @else
                                    <th scope="col">Kelas</th>
                                    <th scope="col" class="hidden w-28 sm:table-cell">Angkatan</th>
                                    <th scope="col" class="hidden w-36 sm:table-cell">Siswa berprestasi</th>
                                    <th scope="col" class="hidden w-24 sm:table-cell">Prestasi</th>
                                @endif
                                <th scope="col" class="w-24 text-right">Poin</th>
                            </x-slot:head>
                            @foreach ($rest as $row)
                                <tr wire:key="row-{{ $mode }}-{{ $row['position'] }}">
                                    <td class="num font-bold text-ink-2">{{ $row['rank'] }}</td>
                                    @if ($isSiswa)
                                        <td>
                                            <span class="flex min-w-0 items-center gap-3">
                                                @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'sm'])
                                                <span class="flex min-w-0 flex-col gap-0.5">
                                                    @include('livewire.publik.partials.person-name', ['person' => $row, 'wrap' => true])
                                                    <span class="text-xs text-muted sm:hidden">{{ collect([$row['kelas_label'], $row['count'].' prestasi'])->filter()->implode(' · ') }}</span>
                                                </span>
                                            </span>
                                        </td>
                                        <td class="hidden sm:table-cell">
                                            @if ($row['alumni'])
                                                <x-sth.chip size="sm">Alumni</x-sth.chip>
                                            @else
                                                {{ $row['kelas'] ?? '–' }}
                                            @endif
                                        </td>
                                        <td class="num hidden sm:table-cell">{{ $row['count'] }}</td>
                                    @else
                                        <td>
                                            <span class="flex flex-col">
                                                <span class="font-bold">{{ $row['kelas'] }}</span>
                                                <span class="text-xs text-muted sm:hidden">{{ $row['angkatan'] ? 'Angkatan '.$row['angkatan'].' · ' : '' }}{{ $row['students'] }} siswa · {{ $row['count'] }} prestasi</span>
                                            </span>
                                        </td>
                                        <td class="num hidden sm:table-cell">{{ $row['angkatan'] ?? '–' }}</td>
                                        <td class="num hidden sm:table-cell">{{ $row['students'] }}</td>
                                        <td class="num hidden sm:table-cell">{{ $row['count'] }}</td>
                                    @endif
                                    <td class="display num text-right text-base font-bold">{{ $row['points_label'] }}</td>
                                </tr>
                            @endforeach
                        </x-sth.table>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="num text-xs text-muted">Menampilkan {{ $shown }} dari {{ $total }} {{ $noun }}</span>
                        @if ($hasMore)
                            <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="tampilkanLebih">Tampilkan lebih banyak</x-sth.button>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Cara poin dihitung --}}
        <x-sth.card as="aside" aria-labelledby="h-cara" class="flex min-w-0 flex-col gap-3.5 p-[22px]">
            <div class="flex flex-col gap-1">
                <h2 id="h-cara" class="title-section">Cara poin dihitung</h2>
                <p class="m-0 text-sm text-muted">Contoh: Juara I Nasional ajang Puspresnas (Kategori A)</p>
            </div>
            <x-sth.rumus-poin :tingkat="$weights['example']['tingkat']" :capaian="$weights['example']['capaian']"
                :penyelenggara="$weights['example']['penyelenggara']" :total="$weights['example']['total']" label="Contoh rumus poin" />
            <div class="h-px bg-line" aria-hidden="true"></div>

            @foreach ([['Bobot capaian', 'Capaian', $weights['results']]] as [$tableCaption, $column, $items])
                <table class="w-full border-collapse text-xs">
                    <caption class="pb-1.5 text-left text-sm font-bold text-ink">{{ $tableCaption }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="border-b border-line pb-[5px] text-left text-xs font-bold text-muted">{{ $column }}</th>
                            <th scope="col" class="border-b border-line pb-[5px] text-right text-xs font-bold text-muted">Bobot</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr>
                                <td class="border-b border-line-soft py-[5px] text-ink">{{ $item['label'] }}</td>
                                <td class="num border-b border-line-soft py-[5px] text-right font-bold">{{ $item['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @foreach ([['Bobot tingkat', 'Tingkat', $weights['levels']], ['Bobot penyelenggara', 'Kategori', $weights['organizers']]] as [$tableCaption, $column, $items])
                    <table class="w-full border-collapse text-xs">
                        <caption class="pb-1.5 text-left text-sm font-bold text-ink">{{ $tableCaption }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="border-b border-line pb-[5px] text-left text-xs font-bold text-muted">{{ $column }}</th>
                                <th scope="col" class="border-b border-line pb-[5px] text-right text-xs font-bold text-muted">Bobot</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                <tr>
                                    <td class="border-b border-line-soft py-[5px] text-ink">{{ $item['label'] }}</td>
                                    <td class="num border-b border-line-soft py-[5px] text-right font-bold">{{ $item['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
            </div>

            <div class="flex items-center gap-2.5 rounded-tile bg-primary-tint px-3 py-2.5 text-primary-on-tint">
                <x-sth.icon name="users" :size="18" />
                <span class="text-sm font-semibold">Anggota tim masing-masing mendapat poin penuh.</span>
            </div>
        </x-sth.card>
    </div>
</div>
