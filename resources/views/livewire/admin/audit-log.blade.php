{{-- Admin · Log audit (UI-MAP §5, backlog #8, B5). Read-only: no edit or delete anywhere. --}}
<div class="flex flex-col gap-[22px]">
    <x-sth.card as="section" aria-labelledby="h-audit-filter" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="h-audit-filter" class="title-card">Saring log <span class="muted text-[13.5px] font-normal" wire:loading>Memuat…</span></h2>
            <span class="flex flex-wrap gap-2">
                @if ($filtered)
                    <x-sth.button variant="quiet" size="sm" icon="x" wire:click="resetFilters">Hapus filter</x-sth.button>
                @endif
                <x-sth.button variant="soft" size="sm" icon="download" wire:click="export" wire:loading.attr="disabled" wire:target="export">Download CSV</x-sth.button>
            </span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <x-sth.select wire:model.live="pelaku" label="Pelaku" :options="$actorOptions" />
            <x-sth.select wire:model.live="peristiwa" label="Peristiwa" :options="$eventOptions" />
            <x-sth.select wire:model.live="subjek" label="Subjek" placeholder="Semua subjek" :options="$subjectOptions" />
            <x-sth.input wire:model.live="dari" type="date" label="Dari tanggal" />
            <x-sth.input wire:model.live="sampai" type="date" label="Sampai tanggal" />
            <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Nama pelaku" icon="search" placeholder="Nama atau nama pengguna" />
        </div>
        <p class="m-0 text-sm muted">Download CSV berisi baris sesuai filter (maks. 10.000) dan ikut tercatat di log ini.</p>
    </x-sth.card>

    @if ($entries->isEmpty())
        <x-sth.empty-state icon="shield" :title="$filtered ? 'Tidak ada catatan yang cocok' : 'Log audit masih kosong'">
            {{ $filtered ? 'Ubah atau hapus filter.' : 'Perubahan penting akan tercatat di sini.' }}
        </x-sth.empty-state>
    @else
        <x-sth.table :caption="'Log audit, '.number_format($entries->total(), 0, ',', '.').' catatan, terbaru dulu'">
            <x-slot:head>
                <th scope="col" class="w-44">Waktu</th>
                <th scope="col" class="w-48">Pelaku</th>
                <th scope="col">Peristiwa</th>
                <th scope="col" class="w-44">Subjek</th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr wire:key="audit-{{ $row['id'] }}" class="align-top">
                    <td class="num whitespace-nowrap text-sm">{{ $row['at'] }}</td>
                    <td>
                        @if ($row['system'])
                            <x-sth.chip tone="gray" size="sm">Sistem</x-sth.chip>
                        @else
                            <span class="font-semibold">{{ $row['actor'] }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="flex flex-col gap-1">
                            <span class="font-semibold">{{ $row['eventLabel'] }}</span>
                            <span class="text-[12.5px] muted"><code>{{ $row['event'] }}</code>@if ($row['log'] !== '') · log {{ $row['log'] }}@endif</span>
                            @if ($row['reason'])
                                <span class="text-sm">Alasan: {{ $row['reason'] }}</span>
                            @endif
                            @if ($row['properties'] || $row['changes'] || $row['hash'])
                                <details class="text-sm">
                                    <summary class="lnk cursor-pointer">Lihat detail<span class="sr-only"> catatan {{ $row['id'] }}</span></summary>
                                    <div class="mt-2 flex flex-col gap-2">
                                        @if ($row['properties'])
                                            <div>
                                                <span class="font-semibold">Properti</span>
                                                <pre class="m-0 mt-1 max-h-64 overflow-auto rounded-control bg-subtle p-3 text-[12px] leading-snug whitespace-pre-wrap break-all">{{ $row['properties'] }}</pre>
                                            </div>
                                        @endif
                                        @if ($row['changes'])
                                            <div>
                                                <span class="font-semibold">Perubahan data</span>
                                                <pre class="m-0 mt-1 max-h-64 overflow-auto rounded-control bg-subtle p-3 text-[12px] leading-snug whitespace-pre-wrap break-all">{{ $row['changes'] }}</pre>
                                            </div>
                                        @endif
                                        @if ($row['hash'])
                                            <span class="muted text-[12px] break-all">Hash: <code>{{ $row['hash'] }}</code></span>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </span>
                    </td>
                    <td>
                        <span class="flex flex-col">
                            <span>{{ $row['subject'] }}</span>
                            @if ($row['subjectId'] !== '')
                                <span class="text-[12px] muted break-all"><code>{{ $row['subjectId'] }}</code></span>
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-sth.table>

        {{ $entries->links() }}
    @endif
</div>
