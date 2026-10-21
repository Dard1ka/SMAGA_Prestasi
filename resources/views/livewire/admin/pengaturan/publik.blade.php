{{-- Tab "Halaman publik": indexing + certificate links, both off by default (no consent since D-162, 20 Okt 2026). --}}
<section aria-labelledby="h-publik" class="flex flex-col gap-5">
    <x-sth.page-header id="h-publik" title="Halaman publik" subtitle="Prestasi, peringkat, dan pemenang lomba yang bisa dilihat tanpa login." />

    <x-sth.alert tone="info" title="Data yang tampil">
        Hanya nama, kelas, dan prestasi terverifikasi. Siswa dengan akun nonaktif tampil sebagai "Siswa kelas …" dan tetap dihitung. Foto, NIS, NISN, HP, email, dan tanggal lahir tidak pernah tampil.
    </x-sth.alert>

    <form wire:submit="save" class="flex flex-col gap-4" novalidate>
        <x-sth.card as="section" class="flex flex-col gap-4">
            <x-sth.checkbox wire:model="indexing" hint="Mati: mesin pencari diminta tidak mengindeks halaman publik. Halaman per siswa selalu tidak diindeks.">
                Izinkan mesin pencari mengindeks halaman publik
            </x-sth.checkbox>
            <x-sth.checkbox wire:model="certificates" hint="Tidak untuk tim dengan anggota yang akunnya nonaktif. Mati: hanya tampil lencana Terverifikasi.">
                Tampilkan link sertifikat di halaman siswa
            </x-sth.checkbox>
        </x-sth.card>
        <div class="flex justify-end">
            <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="save">Simpan</x-sth.button>
        </div>
    </form>
</section>
