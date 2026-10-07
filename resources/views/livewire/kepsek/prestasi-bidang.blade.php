{{-- Kepala Sekolah · Prestasi per bidang (internal variant of board Prestasi-Bidang, backlog #6, B5). All names, never NIS/NISN. --}}
@php
    $onClass = [
        'sains' => 'border-sains bg-sains-bg',
        'riset' => 'border-riset bg-riset-bg',
        'seni' => 'border-seni bg-seni-bg',
        'olahraga' => 'border-olahraga bg-olahraga-bg',
    ];
    $numClass = ['sains' => 'text-sains', 'riset' => 'text-riset', 'seni' => 'text-seni', 'olahraga' => 'text-olahraga'];
    $barClass = ['sains' => 'bg-sains-chart', 'riset' => 'bg-riset-chart', 'seni' => 'bg-seni-chart', 'olahraga' => 'bg-olahraga-chart'];
    $statusChip = ['alumni' => ['gray', 'Alumni'], 'nonaktif' => ['red', 'Nonaktif']];
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-end gap-3">
        <x-sth.select wire:model.live="tahun" label="Tahun ajaran" :options="$yearOptions" placeholder="Semua tahun" class="w-full sm:w-[200px]" />
        <x-sth.select wire:model.live="tingkat" label="Tingkat" :options="$levelOptions" placeholder="Semua tingkat" class="w-full sm:w-[200px]" />
        <span class="muted text-sm" wire:loading>Memuat…</span>
        @if ($laporanUrl)
            <x-sth.button variant="quiet" size="sm" icon="file" href="{{ $laporanUrl }}" class="sm:ml-auto">Buka laporan</x-sth.button>
        @endif
    </div>

    <div class="grid items-start gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[260px_400px_minmax(0,1fr)]" wire:loading.class="opacity-70">
        {{-- Level 1: bidang --}}
        <x-sth.card as="section" aria-labelledby="h-bidang" class="flex min-w-0 flex-col gap-3.5">
            <div class="flex items-baseline justify-between gap-2">
                <h2 id="h-bidang" class="m-0 text-lg font-bold">Bidang</h2>
                <span class="num text-xs font-semibold text-muted">{{ $total }} prestasi</span>
            </div>
            <div class="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-2 lg:grid-cols-1">
                @foreach ($tiles as $b)
                    @php $on = $b['value'] === $selected->value; @endphp
                    <button type="button" wire:click="pilihBidang('{{ $b['value'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @class(['flex w-full cursor-pointer items-center gap-3 rounded-card border-2 p-3 text-left', $onClass[$b['key']] => $on, 'border-line bg-surface hover:bg-subtle' => ! $on])>
                        <span class="cover-{{ $b['key'] }} inline-flex size-10 shrink-0 items-center justify-center rounded-control"><x-sth.icon :name="$b['key']" /></span>
                        <span class="flex min-w-0 flex-1 flex-col gap-2">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-md font-bold text-ink">{{ $b['label'] }}</span>
                                <span class="display num text-xl leading-[26px] font-bold {{ $numClass[$b['key']] }}">{{ $b['count'] }}<span class="sr-only"> prestasi</span></span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="block h-1.5 flex-1 overflow-hidden rounded-full bg-ink/10" aria-hidden="true">
                                    <span class="block h-1.5 rounded-full {{ $barClass[$b['key']] }} w-pct-{{ $b['pct'] }}"></span>
                                </span>
                                <span class="num w-9 text-right text-xs font-semibold text-ink-2">{{ $b['pct'] }}%</span>
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        </x-sth.card>

        {{-- Level 2: siswa --}}
        <x-sth.card as="section" aria-labelledby="h-siswa" class="flex min-w-0 flex-col gap-3.5">
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 id="h-siswa" class="m-0 text-lg font-bold">Siswa berprestasi</h2>
                    <x-sth.chip size="sm" :bidang="$selected" />
                </div>
                <span class="text-xs text-muted">Urut menurut poin di bidang ini. Semua nama tampil (data internal).</span>
            </div>

            @if ($students === [])
                <x-sth.empty-state icon="search" title="Belum ada prestasi untuk filter ini." :level="3" :card="false" class="py-8">
                    Ganti tahun ajaran atau tingkat.
                </x-sth.empty-state>
            @else
                <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                    @foreach ($students as $s)
                        @php $on = $s['id'] === $siswa; @endphp
                        <li wire:key="ks-{{ $s['id'] }}">
                            <button type="button" wire:click="pilihSiswa('{{ $s['id'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                @class(['flex w-full cursor-pointer items-center gap-3 rounded-card border-2 px-3 py-2.5 text-left', 'border-primary bg-primary-tint' => $on, 'border-transparent bg-surface hover:bg-page' => ! $on])>
                                <x-sth.avatar :name="$s['name']" size="sm" />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-md font-bold text-ink">{{ $s['name'] }}</span>
                                    <span class="text-xs text-muted">
                                        {{ $s['kelas'] !== '' ? $s['kelas'] : '–' }} · {{ $s['count'] }} prestasi · {{ $s['best'] }}
                                    </span>
                                </span>
                                @isset($statusChip[$s['status']])
                                    <x-sth.chip :tone="$statusChip[$s['status']][0]" size="sm">{{ $statusChip[$s['status']][1] }}</x-sth.chip>
                                @endisset
                                <x-sth.chip tone="gold" size="sm" class="num">{{ $s['pointsLabel'] }}</x-sth.chip>
                            </button>
                        </li>
                    @endforeach
                </ul>
                @if ($hasMore)
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-xs text-muted">Menampilkan {{ count($students) }} dari {{ $studentTotal }} siswa</span>
                        <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="tampilkanLebih">Tampilkan lebih banyak</x-sth.button>
                    </div>
                @endif
            @endif
        </x-sth.card>

        {{-- Level 3: detail siswa --}}
        <x-sth.card as="section" aria-label="Detail prestasi siswa" aria-live="polite" class="flex min-w-0 flex-col gap-3 lg:col-span-2 xl:col-span-1">
            @if ($detail !== null)
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-sth.avatar :name="$detail['name']" />
                        <div class="flex min-w-0 flex-col">
                            <h2 class="m-0 truncate text-lg font-bold">{{ $detail['name'] }}</h2>
                            <span class="text-xs text-muted">
                                {{ $detail['kelas'] !== '' ? 'Kelas '.$detail['kelas'] : 'Kelas –' }} · {{ $detail['count'] }} prestasi · {{ $detail['pointsLabel'] }} poin
                                @isset($statusChip[$detail['status']]) · {{ $statusChip[$detail['status']][1] }} @endisset
                            </span>
                        </div>
                    </div>
                    <x-sth.button variant="quiet" size="sm" icon="x" wire:click="tutupSiswa">Tutup</x-sth.button>
                </div>
                <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
                    @foreach ($detail['items'] as $item)
                        <li class="flex flex-col gap-1 rounded-tile border border-line p-3">
                            <span class="flex flex-wrap items-center gap-2">
                                <x-sth.chip size="sm" :bidang="$item['bidang']" />
                                <x-sth.chip :tone="$item['podium'] ? 'gold' : 'gray'" size="sm">{{ $item['capaian'] }}</x-sth.chip>
                                <span class="num ml-auto text-sm font-bold">{{ $item['points'] }} poin</span>
                            </span>
                            <span class="text-md font-bold">{{ $item['lomba'] }}</span>
                            <span class="text-xs text-muted">
                                {{ $item['tingkat'] }} · {{ $item['date'] }}@if ($item['organizer'] !== '') · {{ $item['organizer'] }}@endif @if ($item['mentor'] !== '') · Pembimbing {{ $item['mentor'] }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-10 text-center">
                    <span class="inline-flex size-16 items-center justify-center rounded-card bg-primary-tint text-primary"><x-sth.icon name="user" :size="28" /></span>
                    <p class="display m-0 max-w-[300px] text-lg font-bold">Pilih siswa untuk melihat detail prestasinya</p>
                    <p class="m-0 max-w-[300px] text-sm text-muted">Lomba, capaian, poin, dan guru pembimbing tampil di sini.</p>
                </div>
            @endif
        </x-sth.card>
    </div>
</div>
