{{-- "Usulkan lomba" sheet (board Katalog-Lomba). Opened by any data-sth-open="usul" button on the page. --}}
<div>
    <x-sth.modal id="usul" title="Usulkan lomba" description="Kesiswaan akan memeriksa lalu memasukkannya ke katalog." variant="sheet">
        @if ($sent)
            <div role="status" class="flex flex-col items-center gap-2 py-4 text-center">
                <span class="inline-flex size-14 items-center justify-center rounded-full bg-green-bg text-green"><x-sth.icon name="check" :size="28" /></span>
                <h3 class="title-card m-0">Usulan terkirim</h3>
                <p class="m-0 font-semibold">{{ $sentName }}</p>
                <p class="m-0 text-sm text-muted">Kamu akan dapat notifikasi setelah Kesiswaan memeriksanya.</p>
            </div>
        @else
            <form id="usul-form" wire:submit="kirim" class="flex flex-col gap-4" novalidate>
                <x-sth.input wire:model="name" id="usul-nama" label="Nama lomba" placeholder="Contoh: ISPO 2027" maxlength="200" required />
                <x-sth.input wire:model="organizer_name" id="usul-penyelenggara" label="Penyelenggara" placeholder="Nama lembaga penyelenggara" maxlength="200" optional />
                <x-sth.input wire:model="website_url" id="usul-tautan" type="url" inputmode="url" label="Tautan resmi" placeholder="https://" icon="link"
                             hint="Diawali https://. Kesiswaan memeriksa tautan ini dulu." maxlength="500" optional />
                <x-sth.file-drop wire:model="brosur" id="usul-file" label="Brosur atau juknis" title="Pilih file PDF atau gambar"
                                 hint="PDF, JPG, PNG, atau WEBP · maks. 5 MB" accept=".pdf,.jpg,.jpeg,.png,.webp" optional />
                <x-sth.textarea wire:model="description" id="usul-catatan" label="Catatan" rows="3" maxlength="1000"
                                placeholder="Misalnya jadwal atau biaya pendaftaran" optional />
            </form>
        @endif

        <x-slot:footer>
            @if ($sent)
                <x-sth.button variant="quiet" wire:click="ulang">Usulkan lagi</x-sth.button>
                <x-sth.button data-sth-close wire:click="ulang">Tutup</x-sth.button>
            @else
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button type="submit" form="usul-form" icon="send" wire:loading.attr="disabled" wire:target="kirim,brosur">Kirim usulan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
