{{-- Guru · detail binaan (backlog #3, B4). Data prepared in App\Livewire\Guru\BinaanDetail (own binaan only). --}}
<div class="flex flex-col gap-[22px]">
    <x-sth.page-header :title="$title" :subtitle="$organizer ?: null"
        :breadcrumbs="[['label' => 'Siswa binaan', 'href' => $backUrl], ['label' => $title]]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @if ($competition?->bidang)<x-sth.chip :bidang="$competition->bidang" icon />@endif
                <x-sth.chip :tone="$tone">{{ $chip }}</x-sth.chip>
            </div>
        </x-slot:actions>
    </x-sth.page-header>

    <x-sth.card as="section" aria-label="Jejak prestasi" class="flex flex-col gap-4">
        <x-sth.tracker :status="$status" :dates="$trackerDates" />
        @if ($deadline)
            <x-sth.alert :tone="$deadline['overdue'] ? 'error' : 'warning'" title="Laporan hasil belum dikirim">
                Batas lapor {{ $deadline['dueText'] }}. Ketua tim mengirim laporan dari akunnya.
            </x-sth.alert>
        @endif
    </x-sth.card>

    <x-sth.facts label="Info lomba" :items="$facts" />

    <div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="flex min-w-0 flex-col gap-[22px]">
            {{-- Laporan hasil --}}
            <x-sth.card as="section" aria-labelledby="h-laporan" class="flex flex-col gap-3.5">
                <x-sth.page-header id="h-laporan" title="Laporan hasil" :level="2" />
                @if ($report === null)
                    <p class="m-0 text-sm text-muted">Belum ada laporan hasil.</p>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <x-sth.chip :tone="$report['tone']">{{ $report['status'] }}</x-sth.chip>
                        @if ($report['late'])<x-sth.chip tone="orange" icon="clock">Terlambat</x-sth.chip>@endif
                    </div>
                    <dl class="m-0 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-[12.5px] font-semibold text-muted">Hasil dilaporkan</dt>
                            <dd class="m-0 font-bold">{{ $report['claimed'] ?? '–' }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-[12.5px] font-semibold text-muted">Hasil diverifikasi</dt>
                            <dd class="m-0 font-bold">{{ $report['final'] ?? '–' }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <dt class="text-[12.5px] font-semibold text-muted">Dikirim</dt>
                            <dd class="num m-0 font-bold">{{ $report['submitted'] ?: '–' }}</dd>
                        </div>
                    </dl>
                    @if ($report['note'])
                        <x-sth.alert tone="warning" title="Catatan Kesiswaan">{{ $report['note'] }}</x-sth.alert>
                    @endif
                    @if ($report['files'] !== [])
                        <ul class="m-0 flex list-none flex-col gap-2 p-0" aria-label="Lampiran laporan">
                            @foreach ($report['files'] as $file)
                                <li class="flex items-center gap-3 rounded-tile border border-line p-3">
                                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-tile bg-primary-tint text-primary-on-tint" aria-hidden="true"><x-sth.icon name="file" /></span>
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="font-bold">{{ $file['kind'] }}</span>
                                        <span class="ellip text-[12.5px] text-muted">{{ $file['name'] }}</span>
                                    </span>
                                    <x-sth.button variant="quiet" size="sm" icon="download" :href="$file['href']">Download<span class="sr-only"> {{ $file['kind'] }}</span></x-sth.button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-sth.card>

            {{-- Poin --}}
            @if ($achievements !== [])
                <x-sth.table caption="Poin prestasi per anggota" caption-visible>
                    <x-slot:head>
                        <th scope="col">Siswa</th>
                        <th scope="col" class="w-36">Capaian</th>
                        <th scope="col" class="hidden w-44 sm:table-cell">Rumus</th>
                        <th scope="col" class="w-20 text-right">Poin</th>
                    </x-slot:head>
                    @foreach ($achievements as $row)
                        <tr wire:key="ach-{{ $loop->index }}">
                            <td class="font-bold">{{ $row['name'] }}</td>
                            <td>{{ $row['result'] }}</td>
                            <td class="num hidden text-[13px] text-muted sm:table-cell">{{ $row['rumus'] }}</td>
                            <td class="display num text-right text-base font-extrabold">{{ $row['points'] }}</td>
                        </tr>
                    @endforeach
                </x-sth.table>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-[22px]">
            {{-- Tim --}}
            <x-sth.card as="section" aria-labelledby="h-tim" class="flex flex-col gap-3">
                <x-sth.page-header id="h-tim" :title="count($members) > 1 ? 'Anggota tim' : 'Peserta'" :level="3" />
                <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
                    @foreach ($members as $member)
                        <li class="flex items-center gap-3">
                            <x-sth.avatar :name="$member['name']" size="sm" />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="ellip font-bold">{{ $member['name'] }}</span>
                                <span class="text-[12.5px] text-muted">{{ $member['meta'] }}</span>
                            </span>
                            <x-sth.chip :tone="$member['state'][1]" size="sm">{{ $member['state'][0] }}</x-sth.chip>
                        </li>
                    @endforeach
                </ul>
            </x-sth.card>

            {{-- Surat tugas --}}
            <x-sth.card as="section" aria-labelledby="h-surat" class="flex flex-col gap-3">
                <x-sth.page-header id="h-surat" title="Surat tugas" :level="3" />
                @if ($letters === [])
                    <p class="m-0 text-sm text-muted">Surat muncul di sini setelah Tata Usaha mengirimnya.</p>
                @else
                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($letters as $letter)
                            <li class="flex items-center gap-3 rounded-tile border border-line p-3">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-tile bg-primary-tint text-primary-on-tint" aria-hidden="true"><x-sth.icon name="mail" /></span>
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="font-bold">{{ $letter['title'] }}</span>
                                    <span class="num ellip text-[12.5px] text-muted">{{ $letter['meta'] }}</span>
                                </span>
                                <x-sth.icon-button icon="download" :label="'Download '.$letter['title'].' (PDF)'" :href="$letter['href']" size="sm" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-sth.card>
        </div>
    </div>
</div>
