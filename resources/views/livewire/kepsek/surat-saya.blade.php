{{-- Kepala Sekolah · Surat atas nama saya (SECURITY §3m-5, backlog #6, B5). Read-only list of issued letters. --}}
<div class="flex flex-col gap-[22px]">
    <div class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="mail" tone="blue" label="Surat atas nama saya" :value="(string) $stats['total']" :note="$since ? 'sejak '.$since->translatedFormat('j M Y') : 'semua surat'" />
        <x-sth.stat-tile icon="calendar" tone="green" label="Bulan ini" :value="(string) $stats['month']" note="surat terbit" />
        <x-sth.stat-tile icon="clock" :tone="$stats['today'] > \App\Livewire\Kepsek\SuratSaya::DAILY_ALERT ? 'red' : 'gray'" label="Hari ini" :value="(string) $stats['today']" :note="'wajar sampai '.\App\Livewire\Kepsek\SuratSaya::DAILY_ALERT.' surat/hari'" />
        <x-sth.stat-tile icon="x" tone="orange" label="Dicabut" :value="(string) $stats['revoked']" note="diganti versi baru" />
    </div>

    @if ($alertDays !== [])
        <x-sth.alert tone="warning" title="Jumlah surat tidak biasa">
            Lebih dari {{ \App\Livewire\Kepsek\SuratSaya::DAILY_ALERT }} surat terbit pada
            {{ collect($alertDays)->map(fn ($d) => \Carbon\CarbonImmutable::parse($d)->translatedFormat('j M Y'))->implode(', ') }}.
            Tanyakan ke Tata Usaha bila tidak sesuai.
        </x-sth.alert>
    @endif

    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari nomor surat atau lomba" hide-label icon="search"
                     placeholder="Cari nomor surat atau lomba" class="w-full sm:w-80" />
        <x-sth.select wire:model.live="jenis" label="Jenis surat" hide-label size="sm" :options="$typeOptions" class="w-44" />
        <x-sth.select wire:model.live="status" label="Status" hide-label size="sm" :options="$statusOptions" class="w-44" />
        <span class="muted text-sm" wire:loading>Memuat…</span>
    </div>

    @if ($letters->isEmpty())
        <x-sth.empty-state icon="folder" :title="$q !== '' || $jenis !== '' || $status !== '' ? 'Tidak ada surat yang cocok' : 'Belum ada surat'">
            {{ $q !== '' || $jenis !== '' || $status !== '' ? 'Ubah pencarian atau filter.' : 'Surat tugas yang diterbitkan Tata Usaha tampil di sini.' }}
        </x-sth.empty-state>
    @else
        <x-sth.table :caption="'Surat atas nama saya, '.$letters->total().' surat'">
            <x-slot:head>
                <th scope="col">Nomor</th>
                <th scope="col">Jenis</th>
                <th scope="col">Terbit</th>
                <th scope="col">Lomba</th>
                <th scope="col">Dibuat oleh</th>
                <th scope="col">Status</th>
                <th scope="col" class="relative"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($letters as $letter)
                <tr wire:key="surat-{{ $letter->id }}">
                    <td class="num font-semibold whitespace-nowrap">
                        {{ $letter->full_number }}
                        @if (($letter->version ?? 1) > 1)<span class="muted text-xs font-normal"> · versi {{ $letter->version }}</span>@endif
                    </td>
                    <td>{{ $letter->type === \App\Enums\LetterType::Guru ? 'Guru' : 'Siswa' }}</td>
                    <td class="whitespace-nowrap">{{ $letter->issued_on?->translatedFormat('j M Y') }}</td>
                    <td>{{ $letter->registration?->competition?->name ?? '–' }}</td>
                    <td>{{ $letter->generator?->name ?? '–' }}</td>
                    <td>
                        @if ($letter->revoked_at)
                            <span class="flex flex-col items-start gap-1">
                                <x-sth.chip tone="red" size="sm">Dicabut</x-sth.chip>
                                @if ($letter->replacement)
                                    <span class="text-xs">
                                        Diganti
                                        @if ($letter->replacement->file_id)
                                            <button type="button" class="lnk num" wire:click="download('{{ $letter->replacement->id }}')">{{ $letter->replacement->full_number }}<span class="sr-only"> (download surat pengganti)</span></button>
                                        @else
                                            <span class="num">{{ $letter->replacement->full_number }}</span>
                                        @endif
                                    </span>
                                @endif
                                @if ($letter->revoke_reason)
                                    <span class="muted text-xs">{{ $letter->revoker?->name ? $letter->revoker->name.': ' : '' }}{{ $letter->revoke_reason }}</span>
                                @endif
                            </span>
                        @elseif ($letter->sent_at)
                            <x-sth.chip tone="green" size="sm">Terkirim</x-sth.chip>
                        @else
                            <x-sth.chip tone="orange" size="sm">Belum dikirim</x-sth.chip>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($letter->file_id)
                            <x-sth.button variant="quiet" size="sm" icon="download" wire:click="download('{{ $letter->id }}')">
                                Download<span class="sr-only"> {{ $letter->full_number }}</span>
                            </x-sth.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-sth.table>

        {{ $letters->links() }}
        <p class="m-0 text-sm muted">Keaslian surat bisa dicek lewat kode QR di setiap surat. Setiap download tercatat di log audit.</p>
    @endif
</div>
