{{-- Tab "Halaman publik": indexing + certificate links, both off until the school confirms parental consent (U-04). --}}
<section aria-labelledby="h-publik" class="flex flex-col gap-5">
    <x-sth.page-header id="h-publik" title="Halaman publik" subtitle="Prestasi, peringkat, dan pemenang lomba yang bisa dilihat tanpa login." />

    <x-sth.alert tone="info" title="Data yang tampil">
        Hanya nama, kelas, dan prestasi terverifikasi. Nama tampil bila siswa punya izin publikasi; tanpa izin, tampil "Siswa kelas …" dan tetap dihitung. NIS, NISN, HP, email, dan tanggal lahir tidak pernah tampil.
    </x-sth.alert>

    <form wire:submit="save" class="flex flex-col gap-4" novalidate>
        <x-sth.card as="section" class="flex flex-col gap-4">
            <x-sth.checkbox wire:model="indexing" hint="Mati: mesin pencari diminta tidak mengindeks halaman publik. Halaman per siswa selalu tidak diindeks.">
                Izinkan mesin pencari mengindeks halaman publik
            </x-sth.checkbox>
            <x-sth.checkbox wire:model="certificates" hint="Hanya untuk siswa dengan izin publikasi. Mati: hanya tampil lencana Terverifikasi.">
                Tampilkan link sertifikat di halaman siswa
            </x-sth.checkbox>
        </x-sth.card>
        <p class="text-sm muted">Nyalakan setelah sekolah memastikan persetujuan orang tua (U-04).</p>
        <div class="flex justify-end">
            <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="save">Simpan</x-sth.button>
        </div>
    </form>
</section>
