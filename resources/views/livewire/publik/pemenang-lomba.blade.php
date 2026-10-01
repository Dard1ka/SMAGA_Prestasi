{{-- Publik · Pemenang per lomba (D-31): verified results of one lomba grouped by capaian, best first. --}}
<div class="flex flex-col gap-5">
    <x-sth.link :href="route('public.prestasi', ['bidang' => $winners['bidang']])" icon="chevron-left" class="self-start">Prestasi {{ $winners['bidang_label'] }}</x-sth.link>

    <x-sth.card as="section" aria-labelledby="h-lomba" padding="lg" class="flex flex-col gap-4 px-4 sm:flex-row sm:items-center sm:px-6">
        <x-sth.bidang-tile :bidang="$winners['bidang']" size="lg" />
        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <x-sth.page-header :level="1" id="h-lomba" :title="$winners['lomba']"
                :subtitle="$winners['organizer'] !== '' ? 'Penyelenggara: '.$winners['organizer'].' ('.$winners['kategori_label'].')' : null" />
            <div class="flex flex-wrap items-center gap-2">
                <x-sth.chip :bidang="$winners['bidang']" icon />
                <x-sth.chip>{{ $winners['level_label'] }}</x-sth.chip>
                @if ($winners['final_label'])
                    <x-sth.chip icon="calendar">Final {{ $winners['final_label'] }}</x-sth.chip>
                @endif
                <x-sth.chip>Tahun ajaran {{ $winners['tahun'] }}</x-sth.chip>
            </div>
        </div>
        <div class="flex shrink-0 flex-col gap-px rounded-tile bg-page px-3.5 py-2.5 sm:w-36">
            <span class="text-xs font-semibold text-muted">Siswa berprestasi</span>
            <span class="display num text-2xl leading-7 font-extrabold">{{ $winners['count'] }}</span>
        </div>
    </x-sth.card>

    <div class="grid gap-5 lg:grid-cols-2">
        @foreach ($winners['groups'] as $group)
            <x-sth.card as="section" aria-labelledby="h-cap-{{ $loop->index }}" class="flex min-w-0 flex-col gap-3" wire:key="grp-{{ $group['result'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <span @class(['inline-flex size-9 shrink-0 items-center justify-center rounded-full', 'bg-medal text-gold' => $group['podium'], 'bg-gray-bg text-gray' => ! $group['podium']])><x-sth.icon name="award" :size="18" /></span>
                        <h2 id="h-cap-{{ $loop->index }}" class="title-section">{{ $group['label'] }}</h2>
                    </div>
                    <x-sth.chip tone="gold" class="num">{{ $group['points_label'] }} poin per siswa</x-sth.chip>
                </div>
                <ul class="m-0 grid list-none gap-1.5 p-0 sm:grid-cols-2">
                    @foreach ($group['people'] as $person)
                        <li class="flex min-h-12 min-w-0 items-center gap-2.5 rounded-control px-2 py-1.5 hover:bg-page">
                            @include('livewire.publik.partials.avatar', ['person' => $person, 'size' => 'sm'])
                            <span class="flex min-w-0 flex-col">
                                <span class="flex min-w-0 items-center gap-1.5">
                                    @include('livewire.publik.partials.person-name', ['person' => $person, 'class' => 'text-sm'])
                                    @if ($person['alumni'] && $person['named'])
                                        <x-sth.chip size="sm" class="shrink-0">Alumni</x-sth.chip>
                                    @endif
                                </span>
                                @if ($person['kelas'])
                                    <span class="text-xs text-muted">{{ $person['alumni'] ? 'Kelas terakhir ' : 'Kelas ' }}{{ $person['kelas'] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-sth.card>
        @endforeach
    </div>
    <p class="m-0 text-[13px] text-muted">Anggota tim masing-masing mendapat poin penuh. Nama siswa tampil bila ada persetujuan publikasi.</p>
</div>
