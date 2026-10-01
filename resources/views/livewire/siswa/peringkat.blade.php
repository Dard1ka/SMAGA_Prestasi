{{-- Siswa · Peringkat (backlog #2, B4). Rows come ranked from PublicAchievements::ranking(); `me` marks the own row/class. --}}
@php
    $shown = count($top) + count($rest);
    $caption = $isSiswa ? 'Peringkat siswa aktif' : 'Peringkat kelas';
@endphp
<div class="flex flex-col gap-5">
    {{-- Posisimu (PersonalRank: among active students, same number as Beranda and Portofolio) --}}
    <x-sth.card as="section" tone="gold" aria-labelledby="h-posisi" class="flex flex-col gap-4 sm:flex-row sm:items-center">
        <div class="flex min-w-0 flex-1 items-center gap-3.5">
            <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-medal text-white" aria-hidden="true"><x-sth.icon name="award" :size="24" /></span>
            <div class="flex min-w-0 flex-col gap-0.5">
                <h2 id="h-posisi" class="m-0 font-sans text-[13px] font-bold tracking-normal text-gold">Posisimu</h2>
                @if ($summary['rank'])
                    <p class="m-0 flex flex-wrap items-baseline gap-x-2">
                        <span class="display num text-[30px] leading-[1.1] font-extrabold">#{{ $summary['rank'] }}</span>
                        <span class="text-sm font-semibold text-gold">dari siswa aktif</span>
                    </p>
                @else
                    <p class="m-0 text-[15px] font-bold">Belum ada prestasi terverifikasi.</p>
                @endif
            </div>
        </div>
        <dl class="m-0 grid grid-cols-2 gap-3 sm:w-72">
            <div class="flex flex-col gap-0.5 rounded-tile bg-white/70 px-3.5 py-2.5">
                <dt class="text-[12.5px] font-semibold text-muted">Poin</dt>
                <dd class="display num m-0 text-xl font-extrabold">{{ $summary['points'] }}</dd>
            </div>
            <div class="flex flex-col gap-0.5 rounded-tile bg-white/70 px-3.5 py-2.5">
                <dt class="text-[12.5px] font-semibold text-muted">Prestasi</dt>
                <dd class="display num m-0 text-xl font-extrabold">{{ $summary['count'] }}</dd>
            </div>
        </dl>
    </x-sth.card>

    <div class="flex flex-wrap items-center gap-3">
        <div class="seg seg-solid border border-line-input bg-surface" role="group" aria-label="Tampilan peringkat">
            <button type="button" class="seg-btn h-9" aria-pressed="{{ $isSiswa ? 'true' : 'false' }}" wire:click="pilihMode('siswa')">Siswa</button>
            <button type="button" class="seg-btn h-9" aria-pressed="{{ $isSiswa ? 'false' : 'true' }}" wire:click="pilihMode('kelas')">Kelas</button>
        </div>
        <div class="grid w-full grid-cols-2 gap-3 sm:flex sm:w-auto">
            <x-sth.select wire:model.live="bidang" label="Bidang" hide-label size="sm" placeholder="Semua bidang" :options="$bidangOptions" class="sm:w-[190px]" />
            <x-sth.select wire:model.live="angkatan" label="Angkatan" hide-label size="sm" placeholder="Semua angkatan" :options="$angkatanOptions" class="sm:w-[236px]" />
        </div>
        @if ($filtered)
            <x-sth.button variant="quiet" size="sm" icon="x" wire:click="resetFilter">Hapus filter</x-sth.button>
        @endif
    </div>

    @if ($filtered && $isSiswa)
        <p class="m-0 text-[13.5px] text-muted" role="status">
            @if ($me)
                Di filter ini kamu di peringkat <span class="num font-bold text-ink">#{{ $me['rank'] }}</span> dengan <span class="num font-bold text-ink">{{ $me['points_label'] }}</span> poin.
            @else
                Kamu belum punya prestasi untuk filter ini.
            @endif
        </p>
    @endif

    <div class="flex flex-col gap-4" wire:loading.class="opacity-70">
        @if ($total === 0)
            <x-sth.empty-state icon="bars" title="Belum ada data untuk filter ini.">
                Ganti bidang atau angkatan.
            </x-sth.empty-state>
        @else
            <ol class="m-0 grid list-none gap-4 p-0 sm:grid-cols-3" aria-label="{{ $caption }}, tiga teratas">
                @foreach ($top as $row)
                    <li wire:key="top-{{ $isSiswa ? 's' : 'k' }}-{{ $row['position'] }}">
                        <article @class(['card flex h-full min-w-0 flex-col gap-3.5 p-[18px]', 'border-primary ring-2 ring-primary' => $row['me']])>
                            <div class="flex items-center justify-between gap-2">
                                <span @class(['display num inline-flex size-9 shrink-0 items-center justify-center rounded-full text-base font-extrabold', 'bg-medal text-ink' => $row['rank'] === 1, 'bg-gray-bg text-gray' => $row['rank'] !== 1])><span class="sr-only">Peringkat </span>{{ $row['rank'] }}</span>
                                @if ($row['me'])
                                    <x-sth.chip tone="blue">{{ $isSiswa ? 'Kamu' : 'Kelasmu' }}</x-sth.chip>
                                @else
                                    <x-sth.chip class="num">{{ $row['count'] }} prestasi</x-sth.chip>
                                @endif
                            </div>
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($isSiswa)
                                    @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'xl'])
                                    <div class="flex min-w-0 flex-col gap-1">
                                        @include('livewire.publik.partials.person-name', ['person' => $row, 'class' => 'text-base leading-5'])
                                        @if ($row['kelas'])<span class="text-[13px] text-muted">{{ $row['kelas'] }}</span>@endif
                                    </div>
                                @else
                                    <span class="display inline-flex size-12 shrink-0 items-center justify-center rounded-tile bg-primary-tint text-sm font-extrabold text-primary-on-tint">{{ $row['kelas'] }}</span>
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <span class="text-base leading-5 font-bold">Kelas {{ $row['kelas'] }}</span>
                                        <span class="text-[13px] text-muted">{{ $row['students'] }} siswa berprestasi</span>
                                    </div>
                                @endif
                            </div>
                            <div class="mt-auto flex items-baseline gap-1.5">
                                <span class="display num text-[30px] leading-none font-extrabold">{{ $row['points_label'] }}</span>
                                <span class="text-[13px] font-semibold text-muted">poin</span>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ol>

            @if ($rest !== [] || $meExtra)
                <x-sth.table :caption="$caption.', urutan 4 sampai '.$shown.' dari '.$total">
                    <x-slot:head>
                        <th scope="col" class="w-20 sm:w-24">Peringkat</th>
                        @if ($isSiswa)
                            <th scope="col">Siswa</th>
                            <th scope="col" class="hidden w-24 sm:table-cell">Kelas</th>
                            <th scope="col" class="hidden w-36 md:table-cell">Bidang utama</th>
                        @else
                            <th scope="col">Kelas</th>
                            <th scope="col" class="hidden w-36 sm:table-cell">Siswa berprestasi</th>
                        @endif
                        <th scope="col" class="hidden w-24 sm:table-cell">Prestasi</th>
                        <th scope="col" class="w-24 text-right">Poin</th>
                    </x-slot:head>
                    @foreach ($rest as $row)
                        @include('livewire.siswa.peringkat.row', ['row' => $row, 'isSiswa' => $isSiswa])
                    @endforeach
                    @if ($meExtra)
                        <tr aria-hidden="true">
                            <td colspan="6" class="py-1 text-center text-muted">⋯</td>
                        </tr>
                        @include('livewire.siswa.peringkat.row', ['row' => $meExtra, 'isSiswa' => $isSiswa])
                    @endif
                </x-sth.table>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="num text-[13px] text-muted">Menampilkan {{ $shown }} dari {{ $total }} {{ $isSiswa ? 'siswa' : 'kelas' }}</span>
                @if ($hasMore)
                    <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="tampilkanLebih">Tampilkan lebih banyak</x-sth.button>
                @endif
            </div>
        @endif

        <p class="m-0 text-[13px] text-muted">
            Nama siswa lain hanya tampil jika mereka setuju dipublikasikan. Alumni ada di
            <a href="{{ $publicUrl }}" class="font-semibold">peringkat publik</a>.
        </p>
    </div>
</div>
