{{--
    SV6, D-185/D-187: the student actions of one row, the same on Admin › Kelas › Lihat siswa and both lists of Status
    aktivasi: "Ubah data" (student.update, shared dialog UbahDataSiswa), "Ubah status" / "Aktifkan lagi" (user.archive,
    shared dialog UbahStatusSiswa) and "Hapus" (student.trash, shared dialog HapusSiswa). The component has askData(),
    askStatus() and askTrashStudent(); each checks the id against the rows it listed.
    @include('livewire.partials.siswa-aksi', ['userId' => 12, 'name' => 'Nadia Putri Anindya', 'change' => 'Ubah status', 'canData' => true, 'canTrash' => true])
--}}
@if ($canData)
    <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="askData({{ $userId }})">
        Ubah data<span class="sr-only"> {{ $name }}</span>
    </x-sth.button>
@endif
@if ($change)
    <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="askStatus({{ $userId }})">
        {{ $change }}<span class="sr-only"> akun {{ $name }}</span>
    </x-sth.button>
@endif
@if ($canTrash)
    <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="askTrashStudent({{ $userId }})">
        Hapus<span class="sr-only"> {{ $name }}</span>
    </x-sth.button>
@endif
