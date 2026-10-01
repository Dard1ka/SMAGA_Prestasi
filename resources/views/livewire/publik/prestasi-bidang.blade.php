{{-- Publik · Prestasi per bidang (board `Prestasi-Bidang`): bidang → siswa → prestasi siswa. --}}
@php
    $onClass = [
        'sains' => 'border-sains bg-sains-bg',
        'riset' => 'border-riset bg-riset-bg',
        'seni' => 'border-seni bg-seni-bg',
        'olahraga' => 'border-olahraga bg-olahraga-bg',
    ];
    $numClass = ['sains' => 'text-sains', 'riset' => 'text-riset', 'seni' => 'text-seni', 'olahraga' => 'text-olahraga'];
    $barClass = ['sains' => 'bg-sains-chart', 'riset' => 'bg-riset-chart', 'seni' => 'bg-seni-chart', 'olahraga' => 'bg-olahraga-chart'];
    $key = $selected['key'];
    $chartNote = ($tingkatLabel !== null ? 'Tingkat '.$tingkatLabel : 'Semua tingkat').', per tahun ajaran';
    $chartText = collect($years)->map(fn (array $y) => $y['tahun'].' '.$y['count'].($y['current'] ? ' (masih berjalan)' : ''))->implode(', ');
@endphp
<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <x-sth.page-header :level="1" title="Prestasi per bidang" subtitle="Pilih bidang, lalu siswa, untuk melihat lomba dan capaiannya." />
        <div class="grid grid-cols-2 gap-3 sm:flex sm:items-end">
            <x-sth.select wire:model.live="tahun" label="Tahun ajaran" :options="$yearOptions" placeholder="Semua tahun" class="sm:w-[200px]" />
            <x-sth.select wire:model.live="tingkat" label="Tingkat" :options="$levelOptions" placeholder="Semua tingkat" class="sm:w-[200px]" />
        </div>
    </div>

    <div class="grid items-start gap-5 lg:grid-cols-[260px_minmax(0,1fr)] xl:grid-cols-[260px_400px_minmax(0,1fr)]" wire:loading.class="opacity-70">
        {{-- Level 1: bidang --}}
        <x-sth.card as="section" aria-labelledby="h-bidang" class="flex min-w-0 flex-col gap-3.5">
            <div class="flex items-baseline justify-between gap-2">
                <h2 id="h-bidang" class="m-0 text-lg font-bold">Bidang</h2>
                <span class="num text-[13px] font-semibold text-muted">{{ $summary['total'] }} prestasi</span>
            </div>
            <div class="grid grid-cols-1 gap-2.5 min-[420px]:grid-cols-2 lg:grid-cols-1">
                @foreach ($summary['bidang'] as $b)
                    @php $on = $b['value'] === $bidang; @endphp
                    <button type="button" wire:click="pilihBidang('{{ $b['value'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @class(['flex w-full cursor-pointer items-center gap-3 rounded-2xl border-2 p-3 text-left', $onClass[$b['key']] => $on, 'border-line bg-surface hover:bg-subtle' => ! $on])>
                        <span class="cover-{{ $b['key'] }} inline-flex size-10 shrink-0 items-center justify-center rounded-control"><x-sth.icon :name="$b['key']" /></span>
                        <span class="flex min-w-0 flex-1 flex-col gap-2">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-[15px] font-bold text-ink">{{ $b['label'] }}</span>
                                <span class="display num text-[22px] leading-[26px] font-extrabold {{ $numClass[$b['key']] }}">{{ $b['count'] }}<span class="sr-only"> prestasi</span></span>
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

            <div class="my-1 h-px bg-line" aria-hidden="true"></div>

            <div class="flex flex-col gap-2.5">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="m-0 text-[15.5px] font-bold">Perkembangan per tahun</h3>
                    <x-sth.chip size="sm" :bidang="$bidang" />
                </div>
                <span class="text-[12.5px] text-muted">{{ $chartNote }}</span>
                <div role="img" aria-label="Perkembangan prestasi {{ $selected['label'] }} per tahun ajaran: {{ $chartText }}" class="flex flex-col gap-2">
                    <div class="grid h-[176px] grid-cols-3 gap-1.5 border-b border-line-input">
                        @foreach ($years as $year)
                            @php $dim = $tahun !== '' && $tahun !== $year['tahun']; @endphp
                            <div class="flex h-full items-end justify-center">
                                <div class="flex h-[140px] w-10 items-end">
                                    <span @class(['relative block w-full rounded-t-[10px] rounded-b-[4px]', $barClass[$key], 'h-pct-'.$year['pct']])>
                                        <span class="display num absolute bottom-full left-1/2 mb-1.5 -translate-x-1/2 text-base leading-5 font-extrabold">{{ $year['count'] }}</span>
                                        @if ($dim)<span class="absolute inset-0 rounded-t-[10px] rounded-b-[4px] bg-surface/65"></span>@endif
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-3 gap-1.5">
                        @foreach ($years as $year)
                            <div class="flex flex-col items-center gap-px text-center">
                                <span class="num text-[12.5px] font-bold">{{ $year['tahun'] }}</span>
                                <span class="text-xs text-muted">{{ $year['current'] ? 'berjalan' : 'prestasi' }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-sth.card>

        {{-- Level 2: siswa --}}
        <x-sth.card as="section" aria-labelledby="h-siswa" class="flex min-w-0 flex-col gap-3.5">
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h2 id="h-siswa" class="m-0 text-lg font-bold">Siswa berprestasi</h2>
                    <x-sth.chip size="sm" :bidang="$bidang" />
                </div>
                <span class="text-[13px] text-muted">Urut menurut total poin</span>
            </div>

            @if ($students === [])
                <x-sth.empty-state icon="search" title="Belum ada prestasi untuk filter ini." :level="3" :card="false" class="py-8">
                    Ganti tahun ajaran atau tingkat.
                </x-sth.empty-state>
            @else
                <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
                    @foreach ($students as $s)
                        @php $on = $s['slug'] !== null && $s['slug'] === $siswa; @endphp
                        <li wire:key="st-{{ $loop->index }}-{{ $s['slug'] ?? 'anon' }}">
                            @if ($s['slug'] !== null)
                                <button type="button" wire:click="pilihSiswa('{{ $s['slug'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                    @class(['flex w-full cursor-pointer items-center gap-3 rounded-2xl border-2 px-3 py-2.5 text-left', 'border-primary bg-primary-tint' => $on, 'border-transparent bg-surface hover:bg-page' => ! $on])>
                                    @include('livewire.publik.partials.student-row', ['s' => $s])
                                </button>
                            @else
                                <div class="flex w-full items-center gap-3 rounded-2xl border-2 border-transparent px-3 py-2.5">
                                    @include('livewire.publik.partials.student-row', ['s' => $s])
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="m-0 text-[12.5px] text-muted">Nama siswa tampil bila ada persetujuan publikasi.</p>
            @endif
        </x-sth.card>

        {{-- Level 3: detail siswa --}}
        <x-sth.card as="section" aria-label="Detail prestasi siswa" aria-live="polite" class="flex min-w-0 flex-col gap-3 lg:col-span-2 xl:col-span-1">
            @if ($detail !== null)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <x-sth.link :href="route('public.prestasi.siswa', $detail['slug'])" icon-right="chevron-right">Buka halaman siswa</x-sth.link>
                    <x-sth.button variant="quiet" size="sm" icon="x" wire:click="tutupSiswa">Tutup</x-sth.button>
                </div>
                @include('livewire.publik.partials.siswa-detail', ['detail' => $detail, 'level' => 2])
            @else
                <div class="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-10 text-center">
                    <span class="inline-flex size-16 items-center justify-center rounded-card bg-primary-tint text-primary"><x-sth.icon name="user" :size="28" /></span>
                    <p class="display m-0 max-w-[300px] text-lg font-bold">Pilih siswa untuk melihat detail prestasinya</p>
                    <p class="m-0 max-w-[300px] text-sm text-muted">Lomba, capaian, dan poin tampil di sini.</p>
                </div>
            @endif
        </x-sth.card>
    </div>
</div>
