{{-- Kesiswaan › Data guru (App\Livewire\Kesiswaan\DataGuru, SV8, D-197; Admin opens the same page): guru accounts with what the
     guru letter prints under "Kepada", a marker for the empty fields, "Ubah data" per row and "Tambah guru". Every sentence of
     a rule comes from the component or the Actions; this view only formats. Passwords are never shown back. --}}
@php($dataGuru = \App\Livewire\Kesiswaan\DataGuru::class)
<div class="flex flex-col gap-[22px]">
    <div class="flex flex-wrap items-end gap-3">
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" id="f-data-guru-cari" label="Cari nama atau NIP" hide-label icon="search"
                     placeholder="Cari nama atau NIP" class="w-full sm:w-80" />
        <x-sth.select wire:model.live="filter" id="f-data-guru-filter" label="Kelengkapan data" hide-label size="sm" :options="$filterOptions" class="w-52" />
        <span class="muted text-sm" wire:loading>Memuat…</span>
        @if ($canCreate)
            <x-sth.button icon="plus" size="sm" class="sm:ml-auto" wire:click="openCreate" aria-haspopup="dialog">Tambah guru</x-sth.button>
        @endif
    </div>

    @if ($incompleteCount > 0)
        <x-sth.alert tone="warning" icon="alert">
            {{ $incompleteCount }} guru belum lengkap NIP, pangkat/golongan, atau jabatannya. Pilih "Data belum lengkap" untuk melihatnya.
        </x-sth.alert>
    @endif

    @if ($rows === [])
        <x-sth.empty-state icon="users" :title="$filtered ? 'Tidak ada guru yang cocok' : 'Belum ada akun guru'">
            {{ $filtered ? 'Ubah pencarian atau pilihan kelengkapan data.' : ($canCreate ? 'Akun guru dibuat dengan tombol Tambah guru atau oleh Admin.' : 'Akun guru dibuat oleh Admin.') }}
        </x-sth.empty-state>
    @else
        <x-sth.table :caption="'Data guru, '.$gurus->total().' guru'" table-class="min-w-[760px]">
            <x-slot:head>
                <th scope="col">Nama</th>
                <th scope="col">NIP</th>
                <th scope="col">Pangkat/golongan</th>
                <th scope="col">Jabatan</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr wire:key="data-guru-{{ $row['id'] }}">
                    <td>
                        <span class="block font-semibold [overflow-wrap:anywhere]">{{ $row['name'] }}</span>
                        @if ($row['missing'] !== [])
                            <x-sth.chip tone="orange" size="sm" class="mt-1">Belum ada: {{ implode(', ', $row['missing']) }}</x-sth.chip>
                        @endif
                    </td>
                    @foreach (['nip' => 'num whitespace-nowrap', 'rank' => '[overflow-wrap:anywhere]', 'position' => '[overflow-wrap:anywhere]'] as $field => $cellClass)
                        <td class="{{ $cellClass }}">
                            @if ($row[$field] !== null)
                                {{ $row[$field] }}
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                    @endforeach
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

        {{ $gurus->links() }}
    @endif

    {{-- "Ubah data": on-close forgets the question, so "Simpan perubahan" without a new one does nothing (D-128). --}}
    <x-sth.modal :id="$dataGuru::DIALOG" size="md" on-close="forget" title="Ubah data guru"
                 description="Dipakai di surat tugas berikutnya. Surat yang sudah terbit tidak berubah. Perubahan dicatat di log audit.">
        @if ($dialog)
            <form id="ubah-data-guru-form" wire:submit="save" class="flex flex-col gap-4" novalidate>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-tile bg-subtle px-3 py-2.5">
                    <x-sth.chip :tone="$dialog['stateTone']" size="sm">{{ $dialog['stateLabel'] }}</x-sth.chip>
                    <span class="text-sm text-muted [overflow-wrap:anywhere]">Akun: {{ $dialog['username'] }}</span>
                </div>

                <x-sth.input wire:model.live.blur="dataName" id="f-ubah-guru-nama" label="Nama lengkap" autocomplete="off"
                             hint="Dengan gelar, persis seperti di surat. Contoh: Drs. Bambang Hartono, M.Pd." />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-sth.input wire:model.live.blur="dataRank" id="f-ubah-guru-pangkat" label="Pangkat/golongan" optional autocomplete="off"
                                 hint="Contoh: Pembina, IV/a." />
                    <x-sth.input wire:model.live.blur="dataNip" id="f-ubah-guru-nip" label="NIP" optional inputmode="numeric" autocomplete="off"
                                 hint="18 angka. Kosongkan untuk guru tanpa NIP: di surat tertulis tanda strip (-)." />

                </div>
                <x-sth.input wire:model.live.blur="dataPosition" id="f-ubah-guru-jabatan" label="Jabatan" optional autocomplete="off"
                             hint="Contoh: Guru Mata Pelajaran Fisika." />
            </form>
        @else
            <p class="m-0 text-md text-muted">Pilih guru dulu, lalu tekan Ubah data.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog)
                <x-sth.button type="submit" form="ubah-data-guru-form" wire:loading.attr="disabled" wire:target="save">Simpan perubahan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    @if ($canCreate)
        <x-sth.modal :id="$dataGuru::DIALOG_NEW" title="Tambah guru" variant="drawer"
                     description="Akun guru baru. Saat login pertama guru menghubungkan email, lalu wajib mengganti kata sandi sementara. Admin diberi tahu.">
            <form id="tambah-guru-form" wire:submit="createGuru" class="flex flex-col gap-4" novalidate>
                <x-sth.input wire:model="new.name" id="f-tambah-guru-nama" label="Nama lengkap" autocomplete="off"
                             hint="Dengan gelar, persis seperti di surat." />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-sth.input wire:model="new.username" id="f-tambah-guru-username" label="Nama pengguna" hint="Huruf kecil, contoh: bambang.hartono" autocomplete="off" />
                    <x-sth.input wire:model="new.email" id="f-tambah-guru-email" type="email" label="Email" optional autocomplete="off" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-sth.input wire:model="new.honorific" id="f-tambah-guru-sapaan" label="Sapaan" placeholder="Bu / Pak" optional autocomplete="off" />
                    <x-sth.input wire:model="new.nip" id="f-tambah-guru-nip" label="NIP" inputmode="numeric" optional autocomplete="off" class="sm:col-span-2"
                                 hint="18 angka, atau kosong." />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-sth.input wire:model="new.rank" id="f-tambah-guru-pangkat" label="Pangkat/golongan" optional autocomplete="off" />
                    <x-sth.input wire:model="new.position" id="f-tambah-guru-jabatan" label="Jabatan" optional autocomplete="off" />
                </div>
                <x-sth.input wire:model="new.password" id="f-tambah-guru-sandi" type="password" label="Kata sandi sementara" revealable autocomplete="new-password"
                             hint="Berikan langsung ke gurunya. Tidak ditampilkan lagi." />
                <x-sth.input wire:model="new.password_confirmation" id="f-tambah-guru-sandi-ulang" type="password" label="Ulangi kata sandi sementara" revealable autocomplete="new-password" />
            </form>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button type="submit" form="tambah-guru-form" wire:loading.attr="disabled" wire:target="createGuru">Buat akun guru</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @endif
</div>
