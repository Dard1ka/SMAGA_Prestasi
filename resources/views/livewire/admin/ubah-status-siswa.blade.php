{{-- "Ubah status" of student accounts (App\Livewire\Admin\UbahStatusSiswa): one dialog, rendered once by Admin › Kelas and by
     Status aktivasi (Admin only). Every sentence comes from the component; this view only formats. on-close: Batal, X, Esc
     and a click outside forget the question, so "Simpan" without a new one does nothing. The six targets are the states of
     Status aktivasi, as tiles (three per row also on a phone). --}}
<div>
    <x-sth.modal :id="\App\Livewire\Admin\UbahStatusSiswa::DIALOG" on-close="forget" title="Ubah status akun siswa"
                 description="Perubahan dicatat di log audit bersama alasannya.">
        @if ($dialog)
            <form id="ubah-status-siswa-form" wire:submit="save" class="flex flex-col gap-4" novalidate>
                <div class="rounded-tile bg-subtle px-3 py-2.5">
                    @if ($dialog['name'] !== null)
                        <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $dialog['name'] }}</p>
                        @if ($dialog['classroom'])
                            <p class="m-0 text-xs text-muted">Kelas {{ $dialog['classroom'] }}</p>
                        @endif
                    @else
                        <p class="m-0 font-semibold">{{ $dialog['count'] }} siswa dipilih</p>
                        <p class="m-0 text-xs text-muted [overflow-wrap:anywhere]">{{ $dialog['names'] }}</p>
                    @endif
                </div>

                @if ($dialog['targets'] === [])
                    <p class="m-0 text-md text-muted">Status siswa ini sudah berubah. Tutup lalu pilih lagi.</p>
                @else
                    <x-sth.radio-pick wire:model.live="statusTarget" label="Ubah menjadi" :columns="3" size="sm" :options="$dialog['targets']" />
                @endif

                @if ($dialog['chosen'])
                    <div class="flex flex-col gap-1" aria-live="polite">
                        <p class="m-0 text-md font-semibold">{{ $dialog['chosen']['summary'] }}</p>
                        @foreach ($dialog['chosen']['effects'] as $line)
                            <p class="m-0 text-sm text-muted" wire:key="ubah-status-efek-{{ $loop->index }}">{{ $line }}</p>
                        @endforeach
                    </div>
                    @if ($dialog['chosen']['needsDate'] > 0)
                        <x-sth.input wire:model="statusArchiveDate" id="f-ubah-status-tanggal" type="date" :min="$minDate"
                                     label="Tanggal arsip otomatis baru" :hint="$dialog['chosen']['dateHint']" />
                    @endif
                    @if ($dialog['chosen']['needsPassword'] > 0)
                        <x-sth.input wire:model="statusTemporaryPassword" id="f-ubah-status-sandi" type="password" label="Kata sandi sementara"
                                     revealable autocomplete="new-password" hint="Minimal 8 karakter. Siswa wajib menggantinya saat login." />
                    @endif
                @endif

                <x-sth.textarea wire:model="statusReason" id="f-ubah-status-alasan" label="Alasan" rows="2"
                                hint="Minimal 10 karakter. Contoh: siswa kembali bersekolah." />
            </form>
        @else
            <p class="m-0 text-md text-muted">Pilih siswa dulu, lalu tekan Ubah status.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog && $dialog['targets'] !== [])
                <x-sth.button type="submit" :variant="$dialog['danger'] ? 'danger' : 'primary'" form="ubah-status-siswa-form"
                              wire:loading.attr="disabled" wire:target="save">{{ $dialog['submit'] }}</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
