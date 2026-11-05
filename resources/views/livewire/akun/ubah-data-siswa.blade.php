{{-- "Ubah data siswa" (App\Livewire\Akun\UbahDataSiswa, SV6, D-187): one dialog, rendered once by each host page for an account
     with student.update. Every sentence comes from the component; this view only formats. on-close: Batal, X, Esc and a click
     outside forget the question, so "Simpan perubahan" without a new one does nothing. Explicit ids: the dialog is always on
     the page. --}}
<div>
    <x-sth.modal :id="\App\Livewire\Akun\UbahDataSiswa::DIALOG" size="md" on-close="forget" title="Ubah data siswa"
                 description="Perubahan dicatat di log audit. Siswa tidak diberi tahu otomatis.">
        @if ($dialog)
            <form id="ubah-data-siswa-form" wire:submit="save" class="flex flex-col gap-4" novalidate>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-tile bg-subtle px-3 py-2.5">
                    <x-sth.chip :tone="$dialog['stateTone']" size="sm">{{ $dialog['stateLabel'] }}</x-sth.chip>
                    <span class="text-sm text-muted [overflow-wrap:anywhere]">{{ $dialog['email'] !== null ? 'Email '.$dialog['email'] : 'Email belum terhubung' }}</span>
                    <span class="text-sm text-muted">{{ $dialog['cohort'] }}</span>
                </div>

                <x-sth.input wire:model.live.blur="dataName" id="f-ubah-data-nama" label="Nama lengkap" autocomplete="off" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-sth.input wire:model.live.blur="dataNis" id="f-ubah-data-nis" label="NIS" inputmode="numeric" autocomplete="off" hint="4–20 angka." />
                    <x-sth.input wire:model.live.blur="dataNisn" id="f-ubah-data-nisn" label="NISN" inputmode="numeric" autocomplete="off" hint="10 angka." />
                </div>
                <x-sth.input wire:model.live.blur="dataBirthDate" id="f-ubah-data-lahir" label="Tanggal lahir" placeholder="DD-MM-YYYY" autocomplete="off"
                             :hint="$dialog['profileConfirmed'] ? 'Format DD-MM-YYYY. Tanggal lahir sudah dipastikan siswa sendiri.' : 'Format DD-MM-YYYY, contoh 01-02-2010.'" />
                <x-sth.select wire:model.live="dataClassroomId" id="f-ubah-data-kelas" label="Kelas" :options="$dialog['classes']"
                              :disabled="$dialog['archived']" :hint="$dialog['archived'] ? 'Kelas siswa alumni tidak diubah.' : null" />

                @if ($dialog['effects'] !== [])
                    <div class="flex flex-col gap-1 rounded-tile border border-line px-3 py-2.5" aria-live="polite">
                        <p class="m-0 text-sm font-semibold">Yang ikut berubah</p>
                        <ul class="m-0 flex list-disc flex-col gap-1 pl-5">
                            @foreach ($dialog['effects'] as $line)
                                <li class="text-sm text-muted" wire:key="ubah-data-efek-{{ $loop->index }}">{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </form>
        @else
            <p class="m-0 text-md text-muted">Pilih siswa dulu, lalu tekan Ubah data.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog)
                <x-sth.button type="submit" form="ubah-data-siswa-form" wire:loading.attr="disabled" wire:target="save">Simpan perubahan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
