{{-- Siswa · Bantuan (UI-MAP sidebar "Bantuan"; static text from docs/PANDUAN-PENGGUNA.md §2, content owner I). Re-opens the menu tour (siswa.tour). --}}
<x-layouts::app title="Bantuan" subtitle="Cara singkat memakai SMAGA Talent Hub.">
    {{-- D-144, D-153: a student without a talent is held on Profil saya › Talenta saya; the tour lives on Beranda. --}}
    @php($talentFirst = auth()->user() !== null && \App\Http\Middleware\EnsureTalentChosen::applies(auth()->user()))
    <div class="grid grid-cols-1 items-start gap-[18px] lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            @foreach ([
                ['icon' => 'award', 'title' => 'Pilih talenta', 'text' => 'Setiap siswa memilih minimal satu talenta dulu di Talenta saya (menu Profil saya), baru menu lain bisa dibuka. Belum punya? Pilih Lainnya lalu tulis minatmu. Talenta bisa ditambah atau dihapus kapan saja.'],
                ['icon' => 'search', 'title' => 'Pendaftaran lomba', 'text' => 'Buka Cari lomba, pilih lomba, lalu isi panel Pendaftaran lomba. Pilih guru pembimbing. Untuk tim, undang anggota; setiap anggota konfirmasi dari akunnya sendiri.'],
                ['icon' => 'check-circle', 'title' => 'Persetujuan dan surat tugas', 'text' => 'Kepala Sekolah atau Tata Usaha menyetujui pendaftaranmu, lalu Tata Usaha mengirim surat tugas. Download suratnya di Lomba saya.'],
                ['icon' => 'upload', 'title' => 'Laporkan hasil', 'text' => 'Ketua tim melapor segera setelah lomba selesai, lengkap dengan sertifikat atau foto. Setelah sekolah memverifikasi, setiap anggota mendapat poin penuh.'],
                ['icon' => 'plus', 'title' => 'Usulkan lomba', 'text' => 'Lomba belum ada di katalog? Klik Usulkan lomba di Cari lomba. Lomba tampil setelah Kesiswaan memeriksanya.'],
                ['icon' => 'user', 'title' => 'Masuk ke akun', 'text' => 'Masuk pakai NISN atau email yang kamu hubungkan saat aktivasi. Lupa kata sandi? Klik Lupa kata sandi di halaman masuk, kodenya dikirim ke emailmu.'],
                ['icon' => 'lock', 'title' => 'Jaga akunmu', 'text' => 'Jangan bagikan kata sandi atau kode email. Masih pakai kata sandi dari sekolah? Ganti di menu avatar, Profil saya, tab Keamanan akun. Dapat email aneh? Klik Bukan saya? lalu hubungi Kesiswaan.'],
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

        <div class="flex flex-col gap-[18px]">
            <x-sth.card as="aside" aria-labelledby="h-tur" class="flex flex-col gap-3">
                <h2 id="h-tur" class="title-card m-0">Tour singkat menu</h2>
                @if ($talentFirst)
                    {{-- "Buka tour" would only bring the student back to Talenta saya (a tab of Profil saya, not repeated here). --}}
                    <p class="m-0 text-sm text-ink-2">Tour bisa dibuka setelah kamu memilih minimal satu talenta di Talenta saya.</p>
                @else
                    <p class="m-0 text-sm text-ink-2">Lihat lagi penjelasan setiap menu, sebentar saja.</p>
                    <x-sth.button icon="arrow-right" :href="route('siswa.tour')" class="self-start">Buka tour</x-sth.button>
                @endif
            </x-sth.card>

            <x-sth.card as="aside" aria-labelledby="h-kontak" class="flex flex-col gap-2">
                <h2 id="h-kontak" class="title-card m-0">Masih bingung?</h2>
                <p class="m-0 text-sm text-ink-2">Tanyakan ke guru pembimbing atau Kesiswaan. Data salah (nama, kelas, NISN) diperbaiki oleh Kesiswaan atau Admin.</p>
            </x-sth.card>
        </div>
    </div>
</x-layouts::app>
