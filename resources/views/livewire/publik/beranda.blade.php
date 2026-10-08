{{-- Publik · Beranda prestasi (board `Main`). Data: PublicAchievements::overview()/recent()/ranking(). --}}
@php
    $barClass = ['sains' => 'bg-sains-chart', 'riset' => 'bg-riset-chart', 'seni' => 'bg-seni-chart', 'olahraga' => 'bg-olahraga-chart'];
    $top = array_values($top);
    $chartText = collect($overview['years'])->map(fn (array $y) => $y['tahun'].($y['current'] ? ' (berjalan)' : '').': '.$y['total'].' prestasi')->implode('; ');
@endphp
<div class="flex flex-col gap-6">
    {{-- Hero: one ink banner (lintasan motif on the right). Nothing floats beside the headline, and "Masuk" is
         only in the header. --}}
    <section aria-labelledby="hero-title" class="spanduk rounded-card px-5 py-8 sm:px-8 sm:py-10 xl:px-10 xl:py-12">
        <div class="flex max-w-[640px] flex-col items-start gap-3.5">
            <p class="m-0 inline-flex items-center gap-2 text-sm font-semibold text-on-primary-soft"><x-sth.icon name="award" :size="16" />Prestasi SMA Negeri 3 Semarang</p>
            <h1 id="hero-title" class="m-0 text-4xl leading-[1.06] font-bold tracking-[-0.02em] text-balance text-white md:text-5xl">Prestasi siswa SMAGA, dalam satu tempat.</h1>
            <p class="m-0 text-lg leading-normal text-on-primary-soft">Capaian lomba siswa yang sudah diverifikasi sekolah.</p>
            <x-sth.button variant="white" :href="route('public.prestasi')" icon-right="chevron-right" class="mt-2.5">Lihat prestasi</x-sth.button>
        </div>
    </section>

    {{-- Stat tiles --}}
    <section aria-label="Ringkasan prestasi" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4 xl:gap-5">
        <x-sth.stat-tile icon="award" tone="gold" label="Prestasi terverifikasi" :value="number_format($overview['total'], 0, ',', '.')"
            :note="$overview['since'] ? 'Sejak '.$overview['since'] : 'Belum ada data'" />
        <x-sth.stat-tile icon="trophy" tone="blue" label="Nasional & internasional" :value="number_format($overview['nasional'] + $overview['internasional'], 0, ',', '.')"
            :note="$overview['nasional'].' nasional · '.$overview['internasional'].' internasional'" />
        <x-sth.stat-tile icon="users" tone="blue" label="Siswa berprestasi" :value="number_format($overview['students'], 0, ',', '.')"
            note="Minimal 1 prestasi terverifikasi" />
        <x-sth.stat-tile icon="clipboard" tone="blue" :label="'Lomba diikuti '.$overview['tahun']" :value="number_format($overview['lomba_tahun'], 0, ',', '.')"
            note="Tahun ajaran berjalan" />
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] xl:grid-cols-[minmax(0,1fr)_400px]">
        {{-- Per bidang + per tahun ajaran --}}
        <x-sth.card as="section" aria-labelledby="h-bidang" class="flex min-w-0 flex-col gap-4 p-[22px]">
            {{-- No "Lihat siswa per bidang" link: the four tiles below go there. --}}
            <x-sth.page-header id="h-bidang" title="Prestasi per bidang" />

            <div class="grid gap-6 xl:grid-cols-[minmax(0,400px)_minmax(0,1fr)] xl:gap-7">
                {{-- auto-rows-fr + h-full: the four tiles are always the same size, also when a name takes two lines. --}}
                <ul class="m-0 grid auto-rows-fr list-none grid-cols-1 content-start gap-3 p-0 min-[420px]:grid-cols-2">
                    @foreach ($overview['bidang'] as $b)
                        <li>
                            <a href="{{ route('public.prestasi', ['bidang' => $b['value']]) }}" class="cover-{{ $b['key'] }} flex h-full min-h-[76px] items-center gap-3 rounded-card px-4 py-3.5 no-underline hover:shadow-card">
                                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-white"><x-sth.icon :name="$b['key']" :size="22" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="text-sm leading-[18px] font-bold text-ink">{{ $b['label'] }}</span>
                                    <span class="flex items-baseline gap-1.5">
                                        <span class="display num text-2xl leading-7 font-bold">{{ $b['count'] }}</span>
                                        <span class="text-xs font-semibold text-ink">prestasi</span>
                                    </span>
                                </span>
                                <x-sth.icon name="chevron-right" :size="18" />
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="flex min-w-0 flex-col gap-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="m-0 text-sm font-bold">Per tahun ajaran</h3>
                        <ul class="m-0 flex list-none flex-wrap gap-2.5 p-0" aria-hidden="true">
                            @foreach ($overview['bidang'] as $b)
                                <li class="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-2">
                                    <span class="inline-block size-2.5 rounded-[3px] {{ $barClass[$b['key']] }}"></span>{{ $b['key'] === 'seni' ? 'Seni' : $b['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="flex flex-col gap-2" aria-hidden="true">
                        <div class="grid h-[132px] grid-cols-3 gap-3 border-b border-line-input">
                            @foreach ($overview['years'] as $year)
                                <div class="flex items-end justify-center gap-[5px]">
                                    @foreach ($year['bidang'] as $bar)
                                        <div class="flex h-[100px] w-[18px] items-end">
                                            <span class="relative block w-full rounded-t-md rounded-b-[2px] {{ $barClass[$bar['key']] }} h-pct-{{ $bar['pct'] }}">
                                                <span class="num absolute bottom-full left-1/2 mb-1 -translate-x-1/2 text-xs leading-[14px] font-bold text-ink-2">{{ $bar['count'] }}</span>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach ($overview['years'] as $year)
                                <div class="flex flex-col items-center gap-px text-center">
                                    <span class="num text-xs font-bold">{{ $year['tahun'] }}</span>
                                    <span class="num text-xs text-muted">{{ $year['total'] }}{{ $year['current'] ? ' · berjalan' : ' prestasi' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="sr-only">
                        <table>
                            <caption>Prestasi per tahun ajaran dan bidang. {{ $chartText }}.</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Tahun ajaran</th>
                                    @foreach ($overview['bidang'] as $b)
                                        <th scope="col">{{ $b['label'] }}</th>
                                    @endforeach
                                    <th scope="col">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['years'] as $year)
                                    <tr>
                                        <th scope="row">{{ $year['tahun'] }}{{ $year['current'] ? ' (berjalan)' : '' }}</th>
                                        @foreach ($year['bidang'] as $bar)
                                            <td>{{ $bar['count'] }}</td>
                                        @endforeach
                                        <td>{{ $year['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </x-sth.card>

        {{-- Peringkat teratas --}}
        <x-sth.card as="section" aria-labelledby="h-top" class="flex min-w-0 flex-col gap-3 p-[22px]">
            <x-sth.page-header id="h-top" title="Peringkat teratas">
                <x-slot:actions>
                    <x-sth.link :href="route('public.peringkat')" icon-right="chevron-right">Lihat peringkat</x-sth.link>
                </x-slot:actions>
            </x-sth.page-header>
            @if ($top === [])
                <p class="m-0 py-6 text-center text-md text-muted">Belum ada peringkat. Poin muncul setelah prestasi diverifikasi.</p>
            @else
                <ol class="m-0 flex list-none flex-col gap-1 p-0">
                    @foreach ($top as $row)
                        <li class="flex min-h-11 items-center gap-3">
                            <span @class(['num inline-flex size-[26px] shrink-0 items-center justify-center rounded-full text-xs font-bold', 'bg-medal text-ink' => $row['rank'] === 1, 'bg-medal-2 text-ink' => $row['rank'] === 2, 'bg-medal-3 text-ink' => $row['rank'] === 3, 'bg-gray-bg text-gray' => $row['rank'] > 3])><span class="sr-only">Peringkat </span>{{ $row['rank'] }}</span>
                            @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'sm'])
                            <span class="flex min-w-0 flex-1 flex-col">
                                @include('livewire.publik.partials.person-name', ['person' => $row, 'class' => 'text-sm leading-[18px]'])
                                <span class="truncate text-xs leading-4 text-muted">{{ collect([$row['kelas_label'], $row['main_bidang']])->filter()->implode(' · ') }}</span>
                            </span>
                            <span class="display num shrink-0 text-lg font-bold">{{ $row['points_label'] }}<span class="font-sans text-xs font-semibold text-muted"> poin</span></span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-sth.card>
    </div>

    {{-- Prestasi terbaru --}}
    <section aria-labelledby="h-baru" class="flex flex-col gap-3">
        <x-sth.page-header id="h-baru" title="Prestasi terbaru">
            <x-slot:actions>
                <x-sth.link :href="route('public.prestasi')" icon-right="chevron-right">Lihat semua</x-sth.link>
            </x-slot:actions>
        </x-sth.page-header>
        @if ($recent === [])
            <x-sth.empty-state icon="award" title="Belum ada prestasi terverifikasi">Prestasi tampil di sini setelah diverifikasi sekolah.</x-sth.empty-state>
        @else
            <ul class="m-0 grid list-none gap-4 p-0 md:grid-cols-2 xl:grid-cols-3 xl:gap-6">
                @foreach ($recent as $card)
                    <li wire:key="recent-{{ $loop->index }}">
                        <article class="card flex h-full flex-col overflow-hidden">
                            <x-sth.sampul :bidang="$card['bidang_key']" :seed="$card['competition']" class="h-16 items-center justify-between gap-2 px-4">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <x-sth.chip tone="gold">{{ $card['result_label'] }}</x-sth.chip>
                                    <x-sth.chip tone="glass">{{ $card['level_label'] }}</x-sth.chip>
                                </span>
                                <x-sth.chip tone="glass" :icon="$card['bidang_key']">{{ $card['bidang_label'] }}</x-sth.chip>
                            </x-sth.sampul>
                            <div class="flex flex-1 flex-col gap-1 px-[18px] pt-3.5 pb-4 [overflow-wrap:anywhere]">
                                <h3 class="m-0 text-lg leading-[22px] font-bold">
                                    <a href="{{ route('public.lomba.pemenang', $card['competition']) }}" class="text-ink hover:text-primary">{{ $card['lomba'] }}</a>
                                </h3>
                                @if ($card['organizer'] !== '')
                                    <span class="text-xs leading-[18px] text-muted">{{ $card['organizer'] }} · Tahun ajaran {{ $card['tahun'] }}</span>
                                @endif
                                @php $person = $card['people'][0]; $others = count($card['people']) - 1; @endphp
                                <div class="mt-auto flex min-w-0 items-center gap-2.5 pt-2">
                                    @include('livewire.publik.partials.avatar', ['person' => $person, 'size' => 'sm'])
                                    @include('livewire.publik.partials.person-name', ['person' => $person, 'class' => 'text-sm'])
                                    @if ($person['kelas_label'])<span class="shrink-0 text-xs text-muted">{{ $person['kelas_label'] }}</span>@endif
                                    @if ($others > 0)<span class="shrink-0 text-xs text-muted">+{{ $others }} anggota</span>@endif
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
