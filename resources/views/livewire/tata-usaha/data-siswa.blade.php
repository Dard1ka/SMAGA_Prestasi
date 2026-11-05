{{-- Tata Usaha › Data siswa (App\Livewire\TataUsaha\DataSiswa, SV6, D-187): students not in the Sampah, search and class filter,
     "Ubah data" opens the shared dialog App\Livewire\Akun\UbahDataSiswa, rendered once below. --}}
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" id="f-data-siswa-cari" label="Cari nama atau NISN" hide-label icon="search"
                     placeholder="Cari nama atau NISN" class="w-full sm:w-80" />
        <x-sth.select wire:model.live="kelas" id="f-data-siswa-kelas" label="Kelas" hide-label size="sm" :options="$classOptions" class="w-52" />
        <span class="muted text-sm" wire:loading>Memuat…</span>
    </div>

    @if ($rows === [])
        <x-sth.empty-state icon="users" :title="$filtered ? 'Tidak ada siswa yang cocok' : 'Belum ada data siswa'">
            {{ $filtered ? 'Ubah pencarian atau kelas.' : 'Siswa yang di-import Kesiswaan atau Admin tampil di sini.' }}
        </x-sth.empty-state>
    @else
        <x-sth.table :caption="'Data siswa, '.$students->total().' siswa'">
            <x-slot:head>
                <th scope="col">Nama</th>
                <th scope="col">NISN</th>
                <th scope="col">NIS</th>
                <th scope="col">Kelas</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr wire:key="data-siswa-{{ $row['id'] }}">
                    <td class="font-semibold [overflow-wrap:anywhere]">{{ $row['name'] }}</td>
                    <td class="num whitespace-nowrap">{{ $row['nisn'] }}</td>
                    <td class="num whitespace-nowrap">{{ $row['nis'] }}</td>
                    <td class="whitespace-nowrap">{{ $row['classroom'] }}</td>
                    <td><x-sth.chip :tone="$row['stateTone']" size="sm">{{ $row['stateLabel'] }}</x-sth.chip></td>
                    <td class="text-right whitespace-nowrap">
                        @if ($canEdit)
                            <x-sth.button variant="quiet" size="sm" icon="edit" wire:click="edit({{ $row['id'] }})" aria-haspopup="dialog">
                                Ubah data<span class="sr-only"> {{ $row['name'] }}</span>
                            </x-sth.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-sth.table>

        {{ $students->links() }}
    @endif

    @if ($canEdit)
        <livewire:akun.ubah-data-siswa :key="'ubah-data-siswa'" />
    @endif
</div>
