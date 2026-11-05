{{--
    SV6, D-185: the bar above a student list with ticks, the same on Admin › Kelas › Lihat siswa and both lists of Status
    aktivasi. The component uses App\Livewire\Admin\Concerns\SelectsListedStudents and has toggleAllStudents(),
    askStatusSelected() and askTrashSelected().
    @include('livewire.partials.siswa-pilih', ['pilihId' => 'kelas-siswa-pilih-semua', 'ticks' => ['selected' => 0, 'allSelected' => false], 'canStatus' => true, 'canTrash' => true])
--}}
<div class="flex flex-wrap items-center gap-3">
    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-sm font-semibold">
        <input type="checkbox" class="cek" id="{{ $pilihId }}" wire:click="toggleAllStudents" @checked($ticks['allSelected'])>
        Pilih semua yang tampil
    </label>
    <span class="ml-auto inline-flex flex-wrap justify-end gap-2">
        @if ($canStatus)
            <x-sth.button variant="soft" size="sm" icon="users" aria-haspopup="dialog" wire:click="askStatusSelected"
                          wire:loading.attr="disabled" wire:target="askStatusSelected" :disabled="$ticks['selected'] === 0">
                <span>Ubah status terpilih (<span class="num">{{ $ticks['selected'] }}</span>)</span>
            </x-sth.button>
        @endif
        @if ($canTrash)
            <x-sth.button variant="quiet" size="sm" icon="trash" aria-haspopup="dialog" wire:click="askTrashSelected"
                          wire:loading.attr="disabled" wire:target="askTrashSelected" :disabled="$ticks['selected'] === 0">
                <span>Hapus terpilih (<span class="num">{{ $ticks['selected'] }}</span>)</span>
            </x-sth.button>
        @endif
    </span>
</div>
