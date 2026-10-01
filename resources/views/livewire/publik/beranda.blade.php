{{-- Publik · Beranda prestasi (board `Main`). Data: PublicAchievements::overview()/recent()/ranking(). --}}
@php
    $barClass = ['sains' => 'bg-sains-chart', 'riset' => 'bg-riset-chart', 'seni' => 'bg-seni-chart', 'olahraga' => 'bg-olahraga-chart'];
    $latest = $recent[0] ?? null;
    $top = array_values($top);
    $chartText = collect($overview['years'])->map(fn (array $y) => $y['tahun'].($y['current'] ? ' (berjalan)' : '').': '.$y['total'].' prestasi')->implode('; ');
@endphp
<div class="flex flex-col gap-6">
    {{-- Hero --}}
    <section aria-labelledby="hero-title" class="grid items-center gap-8 xl:grid-cols-[minmax(0,1fr)_560px] xl:gap-10">
        <div class="flex flex-col items-start gap-3.5 py-2 xl:py-6">
            <x-sth.chip tone="blue" icon="award" class="h-[30px] px-3 text-[13px]">Prestasi SMA Negeri 3 Semarang</x-sth.chip>
            <h1 id="hero-title" class="m-0 max-w-[600px] text-[36px] leading-[1.06] font-extrabold tracking-[-0.02em] text-balance md:text-[52px]">Prestasi siswa SMAGA, dalam satu tempat.</h1>
            <p class="m-0 text-[17px] leading-normal text-ink-2">Capaian lomba siswa yang sudah diverifikasi sekolah.</p>
            <div class="flex flex-wrap items-center gap-3 pt-2.5">
                <x-sth.button :href="route('public.prestasi')" icon-right="chevron-right">Lihat prestasi</x-sth.button>
                @if (\Illuminate\Support\Facades\Route::has('login'))
                    <x-sth.button variant="quiet" :href="route('login')">Masuk</x-sth.button>
                @endif
            </div>
        </div>

        <div aria-hidden="true" class="relative hidden h-[264px] xl:block">
            <span class="absolute top-2.5 left-[150px] h-60 w-[280px] rounded-full bg-riset-cover blur-2xl"></span>
            @if ($overview['top_bidang'])
                <div class="card absolute top-0 right-0 z-[1] w-[250px] rotate-3 overflow-hidden">
                    <div class="cover-{{ $overview['top_bidang']['key'] }} relative h-28 p-3">
                        <x-sth.chip tone="glass">Bidang terbanyak</x-sth.chip>
                        <x-sth.icon :name="$overview['top_bidang']['key']" :size="56" class="absolute right-4 bottom-3 opacity-70" />
                    </div>
                    <div class="flex flex-col gap-0.5 px-4 pt-3 pb-3.5">
                        <span class="display text-base font-bold">Bidang {{ $overview['top_bidang']['label'] }}</span>
                        <span class="text-[12.5px] text-muted">{{ $overview['top_bidang']['count'] }} prestasi terverifikasi</span>
                    </div>
                </div>
            @endif
            @if ($latest)
                <div class="card absolute top-[50px] left-4 z-[2] flex w-[308px] -rotate-[2.5deg] flex-col gap-2.5 p-[18px]">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex size-[46px] items-center justify-center rounded-full bg-medal text-gold"><x-sth.icon name="award" :size="24" /></span>
                        <x-sth.chip tone="gold">{{ $latest['result_label'] }} {{ $latest['level_label'] }}</x-sth.chip>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="display text-[22px] leading-tight font-extrabold">{{ $latest['lomba'] }}</span>
                        <span class="text-[12.5px] leading-snug text-muted">{{ $latest['organizer'] }}</span>
                    </div>
                    @php $person = $latest['people'][0]; @endphp
                    <div class="flex items-center gap-2.5">
                        @include('livewire.publik.partials.avatar', ['person' => $person, 'size' => 'xs'])
                        <span class="truncate text-sm font-bold">{{ $person['name'] }}</span>
                        @if ($person['kelas'])<span class="text-[12.5px] text-muted">{{ $person['kelas'] }}</span>@endif
                    </div>
                </div>
            @endif
            <div class="card absolute top-[186px] left-[272px] z-[3] flex w-[214px] rotate-[1.5deg] items-center gap-3 px-4 py-3.5">
                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-gold-bg text-gold"><x-sth.icon name="check" /></span>
                <div class="flex flex-col">
                    <span class="display num text-[28px] leading-[1.1] font-extrabold">{{ number_format($overview['total'], 0, ',', '.') }}</span>
                    <span class="text-[12.5px] text-muted">prestasi terverifikasi</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Stat tiles --}}
    <section aria-label="Ringkasan prestasi" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 xl:gap-5">
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
            <x-sth.page-header id="h-bidang" title="Prestasi per bidang">
                <x-slot:actions>
                    <x-sth.link :href="route('public.prestasi')" icon-right="chevron-right">Lihat siswa per bidang</x-sth.link>
                </x-slot:actions>
            </x-sth.page-header>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,400px)_minmax(0,1fr)] xl:gap-7">
                <ul class="m-0 grid list-none grid-cols-1 content-start gap-3 p-0 min-[420px]:grid-cols-2">
                    @foreach ($overview['bidang'] as $b)
                        <li>
                            <a href="{{ route('public.prestasi', ['bidang' => $b['value']]) }}" class="cover-{{ $b['key'] }} flex min-h-[72px] items-center gap-3 rounded-2xl px-4 py-3.5 no-underline hover:shadow-card">
                                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-white/70"><x-sth.icon :name="$b['key']" :size="22" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="text-sm leading-[18px] font-bold text-ink">{{ $b['label'] }}</span>
                                    <span class="flex items-baseline gap-1.5">
                                        <span class="display num text-2xl leading-7 font-extrabold">{{ $b['count'] }}</span>
                                        <span class="text-[12.5px] font-semibold text-ink">prestasi</span>
                                    </span>
                                </span>
                                <x-sth.icon name="chevron-right" :size="18" />
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="flex min-w-0 flex-col gap-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="m-0 text-[13.5px] font-bold">Per tahun ajaran</h3>
                        <ul class="m-0 flex list-none flex-wrap gap-2.5 p-0" aria-hidden="true">
                            @foreach ($overview['bidang'] as $b)
                                <li class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-ink-2">
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
                                    <span class="num text-[12.5px] font-bold">{{ $year['tahun'] }}</span>
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
                <p class="m-0 py-6 text-center text-[14.5px] text-muted">Belum ada peringkat. Poin muncul setelah prestasi diverifikasi.</p>
            @else
                <ol class="m-0 flex list-none flex-col gap-1 p-0">
                    @foreach ($top as $row)
                        <li class="flex min-h-11 items-center gap-3">
                            <span @class(['num inline-flex size-[26px] shrink-0 items-center justify-center rounded-full text-[12.5px] font-bold', 'bg-medal text-ink' => $row['rank'] === 1, 'bg-gray-bg text-gray' => $row['rank'] !== 1])><span class="sr-only">Peringkat </span>{{ $row['rank'] }}</span>
                            @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'sm'])
                            <span class="flex min-w-0 flex-1 flex-col">
                                @include('livewire.publik.partials.person-name', ['person' => $row, 'class' => 'text-sm leading-[18px]'])
                                <span class="truncate text-xs leading-4 text-muted">{{ collect([$row['kelas'], $row['main_bidang']])->filter()->implode(' · ') }}</span>
                            </span>
                            <span class="display num shrink-0 text-[17px] font-extrabold">{{ $row['points_label'] }}<span class="font-sans text-xs font-semibold text-muted"> poin</span></span>
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
                            <div class="cover-{{ $card['bidang_key'] }} flex h-14 items-center justify-between gap-2 px-4">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <x-sth.chip tone="gold">{{ $card['result_label'] }}</x-sth.chip>
                                    <x-sth.chip tone="glass">{{ $card['level_label'] }}</x-sth.chip>
                                </span>
                                <x-sth.chip tone="glass" :icon="$card['bidang_key']">{{ $card['bidang_label'] }}</x-sth.chip>
                            </div>
                            <div class="flex flex-1 flex-col gap-1 px-[18px] pt-3.5 pb-4">
                                <h3 class="m-0 text-lg leading-[22px] font-bold">
                                    <a href="{{ route('public.lomba.pemenang', $card['competition']) }}" class="text-ink hover:text-primary">{{ $card['lomba'] }}</a>
                                </h3>
                                @if ($card['organizer'] !== '')
                                    <span class="text-[13px] leading-[18px] text-muted">{{ $card['organizer'] }} · Tahun ajaran {{ $card['tahun'] }}</span>
                                @endif
                                @php $person = $card['people'][0]; $others = count($card['people']) - 1; @endphp
                                <div class="mt-auto flex min-w-0 items-center gap-2.5 pt-2">
                                    @include('livewire.publik.partials.avatar', ['person' => $person, 'size' => 'sm'])
                                    @include('livewire.publik.partials.person-name', ['person' => $person, 'class' => 'text-sm'])
                                    @if ($person['kelas'])<span class="shrink-0 text-[13px] text-muted">{{ $person['kelas'] }}</span>@endif
                                    @if ($others > 0)<span class="shrink-0 text-[13px] text-muted">+{{ $others }} anggota</span>@endif
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
