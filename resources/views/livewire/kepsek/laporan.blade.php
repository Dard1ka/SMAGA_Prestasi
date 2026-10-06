{{-- Kepala Sekolah · Laporan (UI-MAP §4b, backlog #6, B5). Numbers come from ExportPrestasiData; this view only formats. --}}
@php
    $groupTables = [
        'bidang' => ['Per bidang', 'Bidang'],
        'tingkat' => ['Per tingkat', 'Tingkat'],
        'capaian' => ['Per capaian', 'Capaian'],
        'pembimbing' => ['Per guru pembimbing', 'Guru pembimbing'],
        'kelas' => ['Per kelas', 'Kelas'],
    ];
    $statusChip = ['alumni' => ['gray', 'Alumni'], 'nonaktif' => ['red', 'Nonaktif']];
@endphp
<div class="flex flex-col gap-[22px]">
    @include('livewire.partials.resume-download')
    <x-sth.card as="section" aria-labelledby="h-periode" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-0.5">
                <h2 id="h-periode" class="title-card">{{ $period->label }}</h2>
                <p class="muted m-0 text-[13.5px]">Prestasi terverifikasi menurut tanggal final lomba.</p>
            </div>
            @if ($canExport)
                <span class="flex flex-wrap gap-2">
                    <x-sth.button variant="soft" size="sm" icon="download" wire:click="export('pdf')" wire:loading.attr="disabled" wire:target="export">Download PDF</x-sth.button>
                    <x-sth.button variant="soft" size="sm" icon="download" wire:click="export('xlsx')" wire:loading.attr="disabled" wire:target="export">Download Excel</x-sth.button>
                </span>
            @endif
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-sth.select wire:model.live="tahun" label="Periode" :options="$yearOptions" />
            @if ($tahun === 'rentang')
                <x-sth.input wire:model.live="dari" type="date" label="Dari tanggal" />
                <x-sth.input wire:model.live="sampai" type="date" label="Sampai tanggal" />
            @endif
            <x-sth.select wire:model.live="bidang" label="Bidang" placeholder="Semua bidang" :options="$bidangOptions" />
        </div>
        @if ($canExport)
            <p class="m-0 text-sm muted">Download memuat nama siswa (tanpa NIS/NISN), diberi tanda nama kamu dan waktu download, dan tercatat di log audit.</p>
        @endif
        @if ($suratUrl)
            <x-sth.link :href="$suratUrl" icon="mail" icon-right="chevron-right">Surat atas nama saya</x-sth.link>
        @endif
    </x-sth.card>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" wire:loading.class="opacity-70">
        <x-sth.stat-tile icon="award" tone="gold" label="Prestasi terverifikasi" :value="number_format($summary['totals']['prestasi'], 0, ',', '.')" :note="$pointsTotal.' poin'" />
        <x-sth.stat-tile icon="users" tone="blue" label="Siswa berprestasi" :value="number_format($summary['totals']['siswa'], 0, ',', '.')" note="minimal 1 prestasi" />
        <x-sth.stat-tile icon="trophy" tone="green" label="Lomba" :value="number_format($summary['totals']['lomba'], 0, ',', '.')" note="dengan hasil terverifikasi" />
        <x-sth.stat-tile icon="bars" tone="orange" label="Nasional ke atas" :value="number_format($summary['totals']['nasional'], 0, ',', '.')" note="nasional dan internasional" />
    </div>

    <div class="grid gap-[22px] xl:grid-cols-2" wire:loading.class="opacity-70">
        @foreach ($groupTables as $key => [$title, $head])
            @php $groups = collect($summary[$key])->filter(fn ($g) => $g['prestasi'] > 0 || in_array($key, ['bidang', 'tingkat'], true)); @endphp
            <section aria-labelledby="h-grup-{{ $key }}" @class(['flex min-w-0 flex-col gap-2', 'xl:col-span-2' => $key === 'kelas'])>
                <h2 id="h-grup-{{ $key }}" class="title-card">{{ $title }}</h2>
                @if ($groups->isEmpty())
                    <p class="muted m-0 text-sm">Tidak ada data.</p>
                @else
                    <x-sth.table :caption="$title.', '.$period->label">
                        <x-slot:head>
                            <th scope="col">{{ $head }}</th>
                            <th scope="col" class="w-24 text-right">Prestasi</th>
                            <th scope="col" class="w-20 text-right">Siswa</th>
                            <th scope="col" class="w-24 text-right">Poin</th>
                        </x-slot:head>
                        @foreach ($groups as $group)
                            <tr wire:key="g-{{ $key }}-{{ $loop->index }}">
                                <td class="font-semibold">{{ $group['label'] }}</td>
                                <td class="num text-right">{{ $group['prestasi'] }}</td>
                                <td class="num text-right">{{ $group['siswa'] }}</td>
                                <td class="num text-right">{{ $fmt($group['points']) }}</td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif
            </section>
        @endforeach
    </div>

    <section aria-labelledby="h-rincian" class="flex flex-col gap-2">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="h-rincian" class="title-card">Rincian prestasi</h2>
            <span class="muted text-[13.5px]">
                {{ $rowCount > count($preview) ? count($preview).' terbaru dari '.$rowCount.'. Download untuk daftar lengkap.' : $rowCount.' prestasi' }}
            </span>
        </div>
        @if ($preview === [])
            <x-sth.empty-state icon="award" title="Belum ada prestasi terverifikasi pada periode ini">Ganti periode atau bidang.</x-sth.empty-state>
        @else
            <x-sth.table :caption="'Rincian prestasi, '.$period->label">
                <x-slot:head>
                    <th scope="col">Nama</th>
                    <th scope="col">Kelas</th>
                    <th scope="col">Lomba</th>
                    <th scope="col">Tingkat</th>
                    <th scope="col">Capaian</th>
                    <th scope="col" class="text-right">Poin</th>
                    <th scope="col">Tanggal</th>
                </x-slot:head>
                @foreach ($preview as $row)
                    <tr wire:key="r-{{ $loop->index }}">
                        <td class="font-semibold">
                            {{ $row['name'] }}
                            @isset($statusChip[$row['status']])
                                <x-sth.chip :tone="$statusChip[$row['status']][0]" size="sm" class="ml-1">{{ $statusChip[$row['status']][1] }}</x-sth.chip>
                            @endisset
                        </td>
                        <td>{{ $row['kelas'] }}</td>
                        <td><span class="flex flex-wrap items-center gap-1.5"><x-sth.chip size="sm" :bidang="$row['bidang']" />{{ $row['lomba'] }}</span></td>
                        <td>{{ $row['tingkat'] }}</td>
                        <td>{{ $row['capaian'] }}</td>
                        <td class="num text-right">{{ $row['points'] }}</td>
                        <td class="num whitespace-nowrap">{{ $row['date'] }}</td>
                    </tr>
                @endforeach
            </x-sth.table>
        @endif
    </section>
</div>
