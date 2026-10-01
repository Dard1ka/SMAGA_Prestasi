{{-- Tata Usaha · Arsip surat. Read-only list of issued letters. --}}
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari nomor surat atau lomba" hide-label icon="search"
                     placeholder="Cari nomor surat atau lomba" class="w-full sm:w-80" />
        <x-sth.select wire:model.live="jenis" label="Jenis surat" hide-label size="sm" :options="$typeOptions" class="w-52" />
        <span class="muted text-[13.5px]" wire:loading>Memuat…</span>
    </div>

    @if ($letters->isEmpty())
        <x-sth.empty-state icon="folder" :title="$q !== '' || $jenis !== '' ? 'Tidak ada surat yang cocok' : 'Belum ada surat terbit'">
            {{ $q !== '' || $jenis !== '' ? 'Ubah pencarian atau jenis surat.' : 'Surat yang dibuat Tata Usaha tersimpan di sini.' }}
        </x-sth.empty-state>
    @else
        <x-sth.table :caption="'Arsip surat, '.$letters->total().' surat'">
            <x-slot:head>
                <th scope="col">Nomor</th>
                <th scope="col">Jenis</th>
                <th scope="col">Tanggal</th>
                <th scope="col">Lomba</th>
                <th scope="col">Untuk</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($letters as $letter)
                @php
                    $registration = $letter->registration;
                    $names = $letter->type === \App\Enums\LetterType::Guru
                        ? ($registration?->mentor?->name ?? '-')
                        : ($registration?->members->whereNotNull('confirmed_at')->whereNull('declined_at')->map(fn ($m) => $m->student?->user?->name)->filter()->implode(', ') ?: '-');
                @endphp
                <tr wire:key="letter-{{ $letter->id }}">
                    <td class="num font-semibold whitespace-nowrap">{{ $letter->full_number }}</td>
                    <td>{{ $letter->type === \App\Enums\LetterType::Guru ? 'Guru' : 'Siswa' }}</td>
                    <td class="whitespace-nowrap">{{ $letter->issued_on?->translatedFormat('j M Y') }}</td>
                    <td>{{ $registration?->competition?->name }}</td>
                    <td class="max-w-64">{{ $names }}</td>
                    <td>
                        @if ($letter->sent_at)
                            <x-sth.chip tone="green" size="sm">Terkirim</x-sth.chip>
                        @else
                            <x-sth.chip tone="orange" size="sm">Belum dikirim</x-sth.chip>
                        @endif
                    </td>
                    <td class="text-right">
                        @if ($letter->file_id)
                            <x-sth.button variant="quiet" size="sm" icon="download" wire:click="download('{{ $letter->id }}')">
                                Unduh<span class="sr-only"> {{ $letter->full_number }}</span>
                            </x-sth.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-sth.table>

        {{ $letters->links() }}
    @endif
</div>
