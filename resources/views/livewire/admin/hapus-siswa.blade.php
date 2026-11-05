{{-- "Hapus siswa?" (App\Livewire\Admin\HapusSiswa, SV6): one dialog, rendered once by Admin › Kelas and by Status aktivasi
     for an account that may put students into the Sampah. Every sentence comes from the component; this view only
     formats. on-close: Batal, X, Esc and a click outside forget the question, so "Hapus siswa" without a new one does
     nothing. --}}
<div>
    <x-sth.modal :id="\App\Livewire\Admin\HapusSiswa::DIALOG" size="sm" on-close="forget"
                 :title="$dialog['title'] ?? 'Hapus siswa?'"
                 description="Penghapusan dicatat di log audit bersama alasannya.">
        @if ($dialog)
            <form id="hapus-siswa-form" wire:submit="trash" class="flex flex-col gap-4" novalidate>
                <div class="rounded-tile bg-subtle px-3 py-2.5">
                    <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $dialog['names'] }}</p>
                    @if ($dialog['where'])
                        <p class="m-0 text-xs text-muted">{{ $dialog['where'] }}</p>
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    @foreach ($dialog['lines'] as $line)
                        <p class="m-0 text-md" wire:key="hapus-siswa-baris-{{ $loop->index }}">{{ $line }}</p>
                    @endforeach
                </div>
                <x-sth.textarea wire:model="reason" id="f-hapus-siswa-alasan" label="Alasan" rows="2"
                                :hint="'Minimal '.$reasonMin.' karakter, maksimal '.$reasonMax.'. Contoh: data ganda dari import.'" />
            </form>
        @else
            <p class="m-0 text-md text-muted">Pilih siswa dulu, lalu tekan Hapus.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog)
                <x-sth.button type="submit" variant="danger" form="hapus-siswa-form" wire:loading.attr="disabled" wire:target="trash">Hapus siswa</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
