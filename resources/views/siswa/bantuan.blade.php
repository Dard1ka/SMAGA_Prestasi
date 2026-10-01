{{-- Siswa · Bantuan (UI-MAP sidebar "Bantuan"; static text, content owner I). Re-opens the menu tour (siswa.tour). --}}
@php($days = (int) \App\Models\Setting::get(\App\Enums\SettingKey::ReportDeadlineDays))
<x-layouts::app title="Bantuan" subtitle="Panduan singkat memakai SMAGA Talent Hub.">
    <div class="grid grid-cols-1 items-start gap-[18px] lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            @foreach ([
                ['icon' => 'search', 'title' => 'Mendaftar lomba', 'text' => 'Buka Cari lomba, pilih lomba, lalu isi panel Daftar lomba. Untuk tim, tambahkan anggota dari akun mereka. Setiap anggota perlu mengonfirmasi lewat akunnya sendiri.'],
                ['icon' => 'check-circle', 'title' => 'Persetujuan dan surat tugas', 'text' => 'Setelah semua anggota konfirmasi, Kepala Sekolah menyetujui pendaftaran. Lalu Tata Usaha membuat surat tugas yang bisa kamu unduh di Lomba saya.'],
                ['icon' => 'upload', 'title' => 'Melaporkan hasil', 'text' => "Ketua tim melaporkan hasil paling lambat {$days} hari setelah tanggal final. Kesiswaan memeriksa laporan, lalu poin masuk ke portofolio setiap anggota."],
                ['icon' => 'plus', 'title' => 'Mengusulkan lomba', 'text' => 'Belum ada di katalog? Pakai tombol Usulkan lomba. Kesiswaan memeriksa usulan dulu sebelum lomba tampil di katalog.'],
                ['icon' => 'lock', 'title' => 'Keamanan akun', 'text' => 'Jangan bagikan kata sandi atau kode email. Jika ada yang aneh di akunmu, segera hubungi Kesiswaan atau Admin.'],
            ] as $item)
                <x-sth.card as="section" class="flex items-start gap-3.5">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-primary-tint text-primary" aria-hidden="true"><x-sth.icon :name="$item['icon']" /></span>
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 class="title-card m-0">{{ $item['title'] }}</h2>
                        <p class="m-0 text-ink-2">{{ $item['text'] }}</p>
                    </div>
                </x-sth.card>
            @endforeach
        </div>

        <x-sth.card as="aside" aria-labelledby="h-tur" class="flex flex-col gap-3">
            <h2 id="h-tur" class="title-card m-0">Tur singkat menu</h2>
            <p class="m-0 text-sm text-ink-2">Lihat lagi penjelasan setiap menu, sebentar saja.</p>
            <x-sth.button icon="arrow-right" :href="route('siswa.tour')" class="self-start">Buka tur</x-sth.button>
        </x-sth.card>
    </div>
</x-layouts::app>
