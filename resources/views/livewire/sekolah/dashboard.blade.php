{{-- Dashboard sekolah (mockup Dashboard-Sekolah.dc.html). All numbers come from the component; this view only formats. --}}
@php
    $chartBg = ['sains' => 'bg-sains-chart', 'seni' => 'bg-seni-chart', 'riset' => 'bg-riset-chart', 'olahraga' => 'bg-olahraga-chart'];
    $tileTone = ['sains' => 'bg-sains-bg text-sains', 'seni' => 'bg-seni-bg text-seni', 'riset' => 'bg-riset-bg text-riset', 'olahraga' => 'bg-olahraga-bg text-olahraga'];
    $toneBg = ['orange' => 'bg-orange-bg text-orange', 'red' => 'bg-red-bg text-red', 'gray' => 'bg-gray-bg text-gray'];
@endphp
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-center gap-3">
        @if ($demo)
            <x-sth.chip tone="orange" icon="info">Data contoh</x-sth.chip>
        @endif
        <x-sth.select wire:model.live="tahun" label="Tahun ajaran" hide-label size="sm" :options="$yearOptions" class="w-44" />
        <span class="muted text-sm" wire:loading>Memuat…</span>
        @if ($laporanUrl)
            <x-sth.button variant="quiet" size="sm" icon="download" href="{{ $laporanUrl }}" class="ml-auto">Download laporan</x-sth.button>
        @endif
    </div>

    <div class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="award" tone="gold" label="Prestasi terverifikasi" :value="number_format($kpi['prestasi'], 0, ',', '.')" :note="$kpi['prestasiNote']" />
        <x-sth.stat-tile icon="users" tone="blue" label="Siswa berprestasi" :value="number_format($kpi['siswa'], 0, ',', '.')" note="Minimal 1 prestasi terverifikasi" />
        <x-sth.stat-tile icon="check-circle" tone="orange" label="Menunggu persetujuan" :value="$kpi['menunggu']" :note="$approvalUrl ? 'Tinjau pendaftaran' : 'Pendaftaran siswa'" :href="$approvalUrl" />
        {{-- D-151: no report deadline; counts reports still missing more than 14 days after the lomba. --}}
        <x-sth.stat-tile icon="clock" tone="red" label="Belum lapor > 14 hari" :value="$kpi['lewat']" note="Lomba selesai lebih dari 14 hari lalu" />
    </div>

    <div class="grid gap-[22px] xl:grid-cols-3">
        <x-sth.card as="section" class="flex flex-col gap-4 xl:col-span-2" aria-labelledby="per-tahun-title">
            <div class="flex flex-col gap-1">
                <h2 id="per-tahun-title" class="title-card">
                    @if ($perYear['best'])
                        {{ $perYear['best']['year'] }} jadi tahun terbanyak: {{ $perYear['best']['total'] }} prestasi
                    @else
                        Prestasi per tahun ajaran
                    @endif
                </h2>
                <p class="muted m-0 text-sm">Prestasi terverifikasi per tahun ajaran dan bidang. {{ $currentYear }} masih berjalan.</p>
            </div>

            @if ($perYear['years'] === [])
                <x-sth.empty-state icon="bars" :card="false" title="Belum ada prestasi terverifikasi">Grafik muncul setelah sekolah memverifikasi laporan hasil.</x-sth.empty-state>
            @else
                <ul class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-xs font-semibold" aria-label="Keterangan warna">
                    @foreach (\App\Livewire\Sekolah\Dashboard::CHART_ORDER as $bidang)
                        <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full {{ $chartBg[$bidang->cssKey()] }}" aria-hidden="true"></span>{{ $bidang->label() }}</li>
                    @endforeach
                </ul>

                <div class="relative ml-8 h-56" aria-hidden="true">
                    <div class="absolute inset-0 flex flex-col-reverse justify-between">
                        @foreach ($perYear['ticks'] as $tick)
                            <div class="relative h-px bg-line-soft">
                                <span class="num absolute -top-2 -left-8 w-6 text-right text-xs leading-none text-muted">{{ $tick }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="absolute inset-0 flex items-end justify-around gap-3 px-2">
                        @foreach ($perYear['years'] as $year)
                            {{-- min-w-0: on a 360px phone the bars get narrower instead of leaving the card (QA7). --}}
                            <div class="flex h-full min-w-0 flex-1 items-end justify-center gap-1.5">
                                @foreach ($year['bars'] as $bar)
                                    <div class="relative flex h-full w-4 items-end sm:w-6">
                                        <span class="relative w-full rounded-t-[6px] {{ $chartBg[$bar['bidang']->cssKey()] }} h-pct-{{ $bar['pct'] }}" title="{{ $bar['bidang']->label() }} · {{ $bar['value'] }}">
                                            <span class="num absolute -top-4 left-1/2 -translate-x-1/2 text-xs leading-none font-bold text-ink-2">{{ $bar['value'] }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="ml-8 flex justify-around gap-3 px-2" aria-hidden="true">
                    @foreach ($perYear['years'] as $year)
                        <div class="flex flex-1 flex-col items-center text-center">
                            {{-- "2025/2026" may break after the slash: four labels did not fit a 360px phone (QA7). --}}
                            @php
                                [$yearStart, $yearEnd] = array_pad(explode('/', $year['year'], 2), 2, null);
                            @endphp
                            <span class="num text-xs font-bold">{{ $yearStart }}@if ($yearEnd !== null)/<wbr>{{ $yearEnd }}@endif</span>
                            <span class="muted text-xs">{{ $year['total'] }} prestasi{{ $year['running'] ? ' · berjalan' : '' }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- The box is sr-only, not the table: a table does not shrink to 1px and widened the page (QA7). --}}
                <div class="sr-only">
                    <table>
                        <caption>Prestasi terverifikasi per tahun ajaran dan bidang</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tahun ajaran</th>
                                @foreach (\App\Livewire\Sekolah\Dashboard::CHART_ORDER as $bidang)
                                    <th scope="col">{{ $bidang->label() }}</th>
                                @endforeach
                                <th scope="col">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perYear['years'] as $year)
                                <tr>
                                    <th scope="row">{{ $year['year'] }}{{ $year['running'] ? ' (berjalan)' : '' }}</th>
                                    @foreach ($year['bars'] as $bar)
                                        <td>{{ $bar['value'] }}</td>
                                    @endforeach
                                    <td>{{ $year['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-sth.card>

        <x-sth.card as="section" class="flex flex-col gap-4" aria-labelledby="per-bidang-title">
            <div class="flex flex-col gap-1">
                <h2 id="per-bidang-title" class="title-card">
                    @if (($perBidang[0]['value'] ?? 0) > 0)
                        {{ $perBidang[0]['bidang']->label() }} memimpin dengan {{ $perBidang[0]['value'] }} prestasi
                    @else
                        Prestasi per bidang
                    @endif
                </h2>
                <p class="muted m-0 text-sm">{{ $tahun === '' ? 'Kumulatif' : 'Tahun ajaran '.$tahun }} {{ $kpi['prestasi'] }} prestasi. Pilih bidang untuk detail.</p>
            </div>
            <ul class="m-0 flex list-none flex-col gap-3 p-0">
                @foreach ($perBidang as $row)
                    @php $key = $row['bidang']->cssKey(); @endphp
                    <li class="flex items-center gap-3">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control {{ $tileTone[$key] }}" aria-hidden="true"><x-sth.icon :name="$key" /></span>
                        <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-bold">{{ $row['bidang']->label() }}</span>
                                @if ($prestasiRoute)
                                    <a class="lnk text-xs" href="{{ route($prestasiRoute, ['bidang' => $row['bidang']->value] + ($tahun !== '' ? ['tahun' => $tahun] : [])) }}">Lihat siswa<span class="sr-only"> {{ $row['bidang']->label() }}</span></a>
                                @endif
                            </span>
                            <span class="block h-2.5 w-full rounded-full bg-subtle" aria-hidden="true">
                                <span class="block h-full rounded-full {{ $chartBg[$key] }} w-pct-{{ $row['pct'] }}"></span>
                            </span>
                        </span>
                        <span class="display num w-8 text-right text-lg font-bold">{{ $row['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-sth.card>
    </div>

    <div class="grid gap-[22px] xl:grid-cols-3">
        <x-sth.card as="section" class="flex flex-col gap-4" aria-labelledby="per-tingkat-title">
            <div class="flex flex-col gap-1">
                <h2 id="per-tingkat-title" class="title-card">{{ $perLevel['nationalUp'] }} prestasi nasional ke atas</h2>
                <p class="muted m-0 text-sm">Prestasi per tingkat lomba, {{ $tahun === '' ? 'kumulatif' : 'tahun ajaran '.$tahun }}.</p>
            </div>
            <ul class="m-0 flex list-none flex-col gap-3 p-0">
                @foreach ($perLevel['rows'] as $row)
                    <li class="grid grid-cols-[88px_1fr_36px] items-center gap-3">
                        <span class="text-sm font-semibold">{{ $row['level']->shortLabel() }}</span>
                        <span class="block h-2.5 rounded-full bg-subtle" aria-hidden="true">
                            <span class="block h-full rounded-full bg-primary w-pct-{{ $row['pct'] }}"></span>
                        </span>
                        <span class="num text-right text-sm font-bold">{{ $row['value'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="muted m-0 text-xs">Nasional dan internasional: {{ $perLevel['nationalUp'] }} dari {{ $perLevel['total'] }} prestasi.</p>
        </x-sth.card>

        <x-sth.card as="section" class="flex flex-col gap-3" aria-labelledby="teratas-title">
            <div class="flex items-baseline justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h2 id="teratas-title" class="title-card">Siswa teratas</h2>
                    <p class="muted m-0 text-sm">Poin kumulatif semua tahun.</p>
                </div>
                @if ($peringkatUrl)
                    <a class="lnk text-sm" href="{{ $peringkatUrl }}">Lihat peringkat</a>
                @endif
            </div>
            @if ($top === [])
                <p class="muted m-0 text-sm">Belum ada poin.</p>
            @else
                <ol class="m-0 flex list-none flex-col gap-2.5 p-0" aria-label="Lima siswa dengan poin kumulatif tertinggi">
                    @foreach ($top as $student)
                        <li class="flex items-center gap-3">
                            <span class="num w-5 text-center text-sm font-bold text-muted">{{ $student['rank'] }}</span>
                            <x-sth.avatar :name="$student['name']" size="sm" />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="ellip text-sm font-bold">{{ $student['name'] }}</span>
                                <span class="muted text-xs">{{ $student['meta'] }}@if ($student['alumni']) · Alumni @endif</span>
                            </span>
                            <x-sth.chip tone="gold" class="num">{{ $student['points'] }}</x-sth.chip>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-sth.card>

        {{-- The items name a lomba: a long word wraps inside the card instead of widening the grid (QA7). --}}
        <x-sth.card as="section" class="flex flex-col gap-3 [overflow-wrap:anywhere]" aria-labelledby="perhatian-title">
            <div class="flex items-center gap-2">
                <h2 id="perhatian-title" class="title-card">Perlu perhatian</h2>
                @if ($attention !== [])
                    <span class="cnt">{{ count($attention) }}</span>
                @endif
            </div>
            <p class="muted m-0 text-sm">Urut dari yang paling mendesak.</p>
            @forelse ($attention as $item)
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-control {{ $toneBg[$item['tone']] ?? $toneBg['gray'] }}" aria-hidden="true"><x-sth.icon name="alert" :size="18" /></span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        @if ($item['url'])
                            {{-- May take two lines: in the narrow card of a 1280px laptop the sentence does not fit on one. --}}
                            <a class="lnk text-sm leading-[1.35] font-bold whitespace-normal" href="{{ $item['url'] }}">{{ $item['title'] }}</a>
                        @else
                            <span class="text-sm font-bold">{{ $item['title'] }}</span>
                        @endif
                        <span class="muted text-xs">{{ $item['note'] }}</span>
                    </span>
                </div>
            @empty
                <p class="muted m-0 text-sm">Tidak ada yang perlu perhatian.</p>
            @endforelse
        </x-sth.card>
    </div>
</div>
