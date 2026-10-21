{{-- Tab "Surat & tanda tangan" (Admin-Pengaturan.dc.html). The signature image is never displayed here. --}}
<section aria-labelledby="h-surat" class="flex flex-col gap-5">
    <x-sth.page-header id="h-surat" title="Surat & tanda tangan" subtitle="Dipakai Tata Usaha saat membuat surat tugas." />

    @if ($canSignature)
        <x-sth.card as="section" aria-labelledby="h-ttd" class="flex flex-col gap-4">
            <h3 id="h-ttd" class="title-card">Tanda tangan + stempel Kepala Sekolah</h3>

            @if ($signatureFile)
                <div class="flex flex-wrap items-center gap-4">
                    <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-tile bg-green-bg text-green"><x-sth.icon name="image" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 font-semibold">
                            <span class="truncate">{{ $signatureFile->original_name }}</span>
                            <x-sth.chip tone="green" size="sm" icon="check">Aktif</x-sth.chip>
                        </p>
                        <p class="text-sm muted">PNG · {{ number_format($signatureFile->size / 1024, 0, ',', '.') }} KB · di-upload {{ $signatureFile->created_at?->translatedFormat('j M Y') }}. Dipakai otomatis di semua surat tugas PDF.</p>
                    </div>
                    <x-sth.button variant="danger" size="sm" icon="trash" data-sth-open="ttd-hapus" aria-haspopup="dialog">Hapus</x-sth.button>
                </div>
            @else
                <x-sth.alert tone="warning" title="Belum ada file">Wajib di-upload sebelum Tata Usaha membuat surat tugas.</x-sth.alert>
            @endif

            <form wire:submit="uploadSignature" class="flex flex-col gap-3" novalidate>
                <x-sth.file-drop wire:model="signature" :file="$signature" label="{{ $signatureFile ? 'Ganti file' : 'Upload file' }}" accept=".png,image/png"
                                 hint="PNG transparan, maks. 1 MB. Versi lama tetap disimpan sebagai bukti." icon="image" />
                <div class="flex justify-end">
                    <x-sth.button type="submit" icon="upload" wire:loading.attr="disabled" wire:target="signature,uploadSignature">Upload tanda tangan</x-sth.button>
                </div>
            </form>
        </x-sth.card>

        <x-sth.modal id="ttd-hapus" title="Hapus tanda tangan?" description="Surat tugas baru tidak bisa dibuat sampai ada gambar baru. File lama tetap disimpan sebagai bukti." size="sm">
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button variant="danger" wire:click="removeSignature" wire:loading.attr="disabled">Hapus</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>

        <x-sth.card as="section" aria-labelledby="h-penandatangan" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="h-penandatangan" class="title-card">Penandatangan</h3>
                    <p class="text-sm muted">
                        @if ($principal)
                            Akun Kepala Sekolah: {{ $principal->name }}.
                        @else
                            Belum ada akun Kepala Sekolah.
                        @endif
                    </p>
                </div>
                <x-sth.button variant="quiet" size="sm" href="{{ route('admin.pengaturan', ['tab' => 'pengguna']) }}">Ganti pejabat</x-sth.button>
            </div>
            <form wire:submit="saveSigner" class="grid grid-cols-1 gap-4 md:grid-cols-3" novalidate>
                <x-sth.input wire:model="signerName" label="Nama" class="md:col-span-3" autocomplete="off" />
                <x-sth.input wire:model="signerRank" label="Pangkat/Golongan" autocomplete="off" />
                <x-sth.input wire:model="signerNip" label="NIP" inputmode="numeric" autocomplete="off" />
                <div class="flex items-end justify-end">
                    <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="saveSigner">Simpan penandatangan</x-sth.button>
                </div>
            </form>
        </x-sth.card>
    @endif

    @if ($canFormats)
        <x-sth.card as="section" aria-labelledby="h-format" class="flex flex-col gap-4">
            <div>
                <h3 id="h-format" class="title-card">Contoh nomor surat</h3>
                <p class="text-sm muted">
                    Tata Usaha mengetik nomor lengkap di tiap surat. Format ini hanya jadi contoh di bawah kolom nomor, tidak mengisi nomor otomatis.
                    Kode: {seq} nomor urut, {roman_month} bulan Romawi, {year} tahun.
                </p>
            </div>
            <form wire:submit="saveFormats" class="grid grid-cols-1 gap-4 md:grid-cols-2" novalidate>
                <div class="flex flex-col gap-1.5">
                    <x-sth.input wire:model.live.debounce.400ms="formatSiswa" label="Surat tugas siswa" autocomplete="off" class="num" />
                    <p class="text-sm muted">Tampil sebagai: <span class="num font-semibold text-ink">{{ $exampleSiswa ?? '–' }}</span></p>
                </div>
                <div class="flex flex-col gap-1.5">
                    <x-sth.input wire:model.live.debounce.400ms="formatGuru" label="Surat tugas guru" autocomplete="off" class="num" />
                    <p class="text-sm muted">Tampil sebagai: <span class="num font-semibold text-ink">{{ $exampleGuru ?? '–' }}</span></p>
                </div>
                <div class="flex justify-end md:col-span-2">
                    <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="saveFormats">Simpan contoh</x-sth.button>
                </div>
            </form>
        </x-sth.card>
    @endif
</section>
