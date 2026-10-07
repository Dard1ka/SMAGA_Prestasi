{{--
    Local design-system showcase: /_dev/components (routes/dev.php, APP_ENV=local only).
    Fictional data from DESIGN-SPEC-v2 §5 only. ?role=<role> switches the sidebar menu.
    Report deadline shown per D-25 (45 days after the final: WICE final 24 Sep → 8 Nov 2026).
--}}
@php
    $people = [
        'siswa' => ['name' => 'Nadia Putri Anindya', 'meta' => 'Siswa · XI-4', 'badges' => ['siswa.lomba-saya' => 2]],
        'guru' => ['name' => 'Pak Hendra Kusuma, S.Pd., M.Si.', 'meta' => 'Guru Pembimbing', 'badges' => []],
        'kesiswaan' => ['name' => 'Ibu Dwi Rahayu', 'meta' => 'Kesiswaan', 'badges' => ['kesiswaan.usulan' => 3, 'kesiswaan.verifikasi' => 4]],
        'kepala_sekolah' => ['name' => 'Drs. Bambang Hartono, M.Pd.', 'meta' => 'Kepala Sekolah', 'badges' => ['kepsek.persetujuan' => 5]],
        'tata_usaha' => ['name' => 'Ibu Sri Handayani', 'meta' => 'Tata Usaha', 'badges' => ['tu.surat' => 3]],
        'humas' => ['name' => 'Mas Galih Prasetyo', 'meta' => 'Humas', 'badges' => ['humas.publikasi' => 3]],
        'admin' => ['name' => 'Operator STH', 'meta' => 'Admin', 'badges' => []],
    ];
    $role = request()->query('role');
    $role = is_string($role) && array_key_exists($role, $people) ? $role : 'siswa';
    $person = $people[$role];
    $only = request()->query('only');
    $show = fn (string $section): bool => ! is_string($only) || $only === $section;
    $activeKey = ['siswa' => 'beranda', 'guru' => 'binaan', 'kesiswaan' => 'kelola-lomba', 'kepala_sekolah' => 'persetujuan', 'tata_usaha' => 'surat', 'humas' => 'publikasi', 'admin' => 'dashboard'][$role];

    $icons = ['home', 'search', 'clipboard', 'award', 'bars', 'user', 'users', 'help', 'trophy', 'inbox', 'shield', 'check-circle',
        'chart-pie', 'database', 'mail', 'folder', 'file', 'megaphone', 'calendar', 'pin', 'upload', 'download', 'archive', 'sliders', 'recycle',
        'bell', 'plus', 'check', 'x', 'chevron-right', 'chevron-left', 'chevron-down', 'clock', 'info', 'link', 'image', 'eye', 'edit',
        'trash', 'sains', 'riset', 'seni', 'olahraga', 'eye-off', 'menu', 'logout', 'arrow-right', 'arrow-left', 'alert', 'external',
        'send', 'lock', 'filter', 'dots'];

    $swatches = [
        'Dasar' => [['bg-page', 'page', '#F3F5F8'], ['bg-surface border border-line', 'surface', '#FFFFFF'], ['bg-line', 'line', '#DDE2E9'], ['bg-line-strong', 'line-strong', '#C3C9D3'], ['bg-line-input', 'line-input', '#7F8794'], ['bg-ink', 'ink', '#141A2B'], ['bg-ink-2', 'ink-2', '#3A4254'], ['bg-muted', 'muted', '#566072'], ['bg-placeholder', 'placeholder', '#687184']],
        'Primer' => [['bg-primary', 'primary', '#1B2A4E'], ['bg-primary-hover', 'primary-hover', '#111C38'], ['bg-primary-tint', 'primary-tint', '#E9EDF4'], ['bg-link', 'link', '#1C4A9C'], ['bg-focus', 'focus', '#2F6FDE']],
        'Bidang' => [['bg-sains', 'sains', '#1D56AD'], ['bg-sains-bg', 'sains-bg', '#E6F0FF'], ['bg-riset', 'riset', '#5534B8'], ['bg-riset-bg', 'riset-bg', '#F0EBFF'], ['bg-seni', 'seni', '#A61F54'], ['bg-seni-bg', 'seni-bg', '#FDE8F0'], ['bg-olahraga', 'olahraga', '#0B6577'], ['bg-olahraga-bg', 'olahraga-bg', '#E0F4F8']],
        'Grafik' => [['bg-sains-chart', 'sains-chart', '#3D7BE0'], ['bg-riset-chart', 'riset-chart', '#7B5CE0'], ['bg-seni-chart', 'seni-chart', '#D6457E'], ['bg-olahraga-chart', 'olahraga-chart', '#1690A6']],
        'Status & emas' => [['bg-green', 'green', '#1B6B45'], ['bg-green-bg', 'green-bg', '#E4F3EA'], ['bg-orange', 'orange', '#9A3F00'], ['bg-orange-bg', 'orange-bg', '#FDEBD8'], ['bg-red', 'red', '#B42318'], ['bg-red-bg', 'red-bg', '#FCEAE7'], ['bg-gray', 'gray', '#3A4254'], ['bg-gray-bg', 'gray-bg', '#E8EBF0'], ['bg-blue', 'blue', '#1C4A9C'], ['bg-blue-bg', 'blue-bg', '#E8EEF9'], ['bg-gold', 'gold', '#6B4E00'], ['bg-gold-bg', 'gold-bg', '#FBF0CC'], ['bg-medal', 'medal', '#E0A82A']],
    ];

    $catalog = [
        ['title' => 'OSN Tingkat Kota Semarang 2026', 'bidang' => 'sains', 'tingkat' => 'Kota/Kab', 'status' => 'Dibuka · 8 hari lagi', 'tone' => 'green', 'peserta' => 'Individu', 'org' => 'Puspresnas · Kategori A', 'date' => '14 Okt 2026', 'place' => 'Kota Semarang', 'names' => ['Hana Safira', 'Aurelia Maharani', 'Rizky Maulana'], 'count' => 12],
        ['title' => 'Lomba Peneliti Belia (LPB) 2026', 'bidang' => 'riset', 'tingkat' => 'Nasional', 'status' => 'Dibuka · 10 hari lagi', 'tone' => 'green', 'peserta' => 'Tim maks 3', 'org' => 'Center for Young Scientists · Kategori C', 'date' => '17 Okt 2026', 'place' => 'Daring', 'names' => ['Nadia Putri Anindya', 'Dimas Arya Nugroho', 'Keisha Larasati'], 'count' => 5],
        ['title' => 'FLS3N Kota Semarang 2026', 'bidang' => 'seni_budaya', 'tingkat' => 'Kota/Kab', 'status' => 'Ditutup 2 hari lagi', 'tone' => 'orange', 'peserta' => 'Individu', 'org' => 'Puspresnas · Kategori A', 'date' => '27 Okt 2026', 'place' => 'Kota Semarang', 'names' => ['Citra Ayu Lestari', 'Salsabila Kirana', 'Aurelia Maharani'], 'count' => 7],
        ['title' => 'O2SN Kota Semarang 2026 · Bulu tangkis', 'bidang' => 'olahraga', 'tingkat' => 'Kota/Kab', 'status' => 'Dibuka · 9 hari lagi', 'tone' => 'green', 'peserta' => 'Individu', 'org' => 'Puspresnas · Kategori A', 'date' => '20 Okt 2026', 'place' => 'Kota Semarang', 'names' => ['Fajar Ramadhan', 'Yoga Pratama', 'Rizky Maulana'], 'count' => 4],
        ['title' => 'National Science and Invention Fair (NSIF) 2026', 'bidang' => 'riset', 'tingkat' => 'Nasional', 'status' => 'Dibuka · 20 hari lagi', 'tone' => 'green', 'peserta' => 'Tim maks 3', 'org' => 'Penyelenggara swasta · Kategori D', 'date' => '7 Nov 2026', 'place' => 'Daring', 'names' => ['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama'], 'count' => 3],
        ['title' => 'KRENOVA Kota Semarang 2027', 'bidang' => 'riset', 'tingkat' => 'Kota/Kab', 'status' => 'Segera dibuka', 'tone' => 'gray', 'peserta' => 'Individu/Tim', 'org' => 'Pemerintah Kota Semarang · Kategori B', 'date' => 'Feb 2027', 'place' => 'Kota Semarang', 'names' => [], 'count' => 0, 'note' => 'Daftar dibuka 1 Des 2026'],
    ];

    $rows = [
        ['title' => 'FLS3N Kota Semarang 2026', 'sub' => 'Puspresnas · Kategori A · Individu', 'bidang' => 'seni_budaya', 'tingkat' => 'Kota/Kab', 'batas' => '2 Okt 2026', 'soon' => '2 hari lagi', 'count' => 7, 'status' => ['green', 'check', 'Dipublikasikan']],
        ['title' => 'OSN Tingkat Kota Semarang 2026', 'sub' => 'Puspresnas · Kategori A · Individu', 'bidang' => 'sains', 'tingkat' => 'Kota/Kab', 'batas' => '8 Okt 2026', 'soon' => null, 'count' => 12, 'status' => ['green', 'check', 'Dipublikasikan']],
        ['title' => 'O2SN Kota Semarang 2026 · Bulu tangkis', 'sub' => 'Puspresnas · Kategori A · Individu', 'bidang' => 'olahraga', 'tingkat' => 'Kota/Kab', 'batas' => '9 Okt 2026', 'soon' => null, 'count' => 4, 'status' => ['green', 'check', 'Dipublikasikan']],
        ['title' => 'Lomba Peneliti Belia (LPB) 2026', 'sub' => 'Center for Young Scientists · Kategori C · Tim maks 3', 'bidang' => 'riset', 'tingkat' => 'Nasional', 'batas' => '10 Okt 2026', 'soon' => null, 'count' => 5, 'status' => ['green', 'check', 'Dipublikasikan']],
        ['title' => 'Kompetisi Robotik Pelajar Jateng 2026', 'sub' => 'Usulan Bima Satria Wicaksono · XII-7', 'bidang' => 'riset', 'tingkat' => 'Provinsi', 'batas' => '–', 'soon' => null, 'count' => 0, 'status' => ['gray', 'clock', 'Menunggu verifikasi']],
    ];

    $sections = ['warna' => 'Warna', 'tombol' => 'Tombol', 'ikon' => 'Ikon', 'chip' => 'Chip', 'kartu' => 'Kartu', 'form' => 'Formulir',
        'tabel' => 'Tabel', 'avatar' => 'Avatar', 'tab' => 'Tab', 'tracker' => 'Jejak prestasi', 'poin' => 'Rumus poin',
        'lomba' => 'Kartu lomba', 'header' => 'Header & info', 'dialog' => 'Dialog & toast', 'layout' => 'Layout'];
@endphp
<x-layouts::app title="Komponen" subtitle="Design system STH · data contoh" :role="$role" :user="$person" :badges="$person['badges']" :notifications="2" :active="$activeKey" preview>
    <x-slot:actions>
        <x-sth.button variant="quiet" size="sm" icon="eye" :href="route('dev.components.public')">Layout publik</x-sth.button>
    </x-slot:actions>

    <x-sth.card padding="sm" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="label mr-1">Menu peran</span>
            @foreach (\App\Support\Navigation::ROLES as $r)
                <a href="{{ route('dev.components', ['role' => $r]) }}" class="fchip h-9 px-3.5 text-sm" @if ($r === $role) aria-current="page" aria-pressed="true" @endif>{{ \App\Support\Navigation::roleLabel($r) }}</a>
            @endforeach
        </div>
        <nav aria-label="Bagian" class="flex flex-wrap gap-x-4 gap-y-0">
            @foreach ($sections as $anchor => $name)
                <a class="lnk text-sm font-semibold" href="#{{ $anchor }}">{{ $name }}</a>
            @endforeach
        </nav>
    </x-sth.card>

    {{-- Warna & tipografi --}}
    <section id="warna" @unless ($show('warna')) hidden @endunless aria-labelledby="h-warna" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-warna" title="Warna & tipografi" subtitle="Satu warna = satu makna. Utility: bg-*, text-*, border-*." />
        <x-sth.card class="flex flex-col gap-5">
            @foreach ($swatches as $group => $list)
                <div class="flex flex-col gap-2">
                    <span class="label">{{ $group }}</span>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
                        @foreach ($list as [$class, $token, $hex])
                            <div class="flex flex-col gap-1.5">
                                <span class="h-12 rounded-tile {{ $class }}"></span>
                                <span class="text-xs font-bold">{{ $token }}</span>
                                <span class="text-xs text-muted uppercase">{{ $hex }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
            <div class="grid gap-3 border-t border-line pt-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <p class="title-page">Judul halaman 28/700 (24 di ponsel)</p>
                    <p class="title-section">Judul bagian 20/700</p>
                    <p class="title-card">Judul kartu 18/600</p>
                    <p class="display num m-0 text-3xl font-bold">104 poin · #14</p>
                </div>
                <div class="flex flex-col gap-1">
                    <p class="m-0">Body 16/400. Public Sans, satu keluarga huruf.</p>
                    <p class="m-0 text-md">Kontrol dan sel tabel 15 (text-md)</p>
                    <p class="label m-0">Label 14/600</p>
                    <p class="m-0 text-xs text-muted">Terkecil 13 (text-xs) · muted #566072</p>
                </div>
            </div>
        </x-sth.card>
    </section>

    {{-- Tombol --}}
    <section id="tombol" @unless ($show('tombol')) hidden @endunless aria-labelledby="h-tombol" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-tombol" title="Tombol" subtitle="button · icon-button · link" />
        <x-sth.card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <x-sth.button icon="search">Cari lomba</x-sth.button>
                <x-sth.button variant="quiet">Batal</x-sth.button>
                <x-sth.button variant="soft" icon="plus">Usulkan lomba</x-sth.button>
                <x-sth.button variant="danger" icon="x">Tolak</x-sth.button>
                <x-sth.button disabled>Kirim</x-sth.button>
                <x-sth.button href="#tombol" icon-right="arrow-right">Lihat prestasi</x-sth.button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-sth.button size="sm" icon="upload">Laporkan hasil</x-sth.button>
                <x-sth.button size="sm" variant="quiet">Hapus filter</x-sth.button>
                <x-sth.button size="sm" variant="soft">Konfirmasi ikut</x-sth.button>
                <x-sth.button size="lg">Masuk</x-sth.button>
                <span class="inline-flex rounded-tile bg-primary p-2"><x-sth.button variant="white" size="sm" icon="search">Cari lomba</x-sth.button></span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-sth.icon-button icon="bell" label="Notifikasi, 2 baru" dot />
                <x-sth.icon-button icon="edit" label="Ubah FLS3N Kota Semarang 2026" size="sm" />
                <x-sth.icon-button icon="trash" label="Hapus berkas" size="sm" />
                <x-sth.icon-button icon="x" label="Tutup" ghost />
                <x-sth.link href="#tombol" icon-right="chevron-right">Lihat semua</x-sth.link>
                <x-sth.link href="https://example.org" external>Website resmi</x-sth.link>
            </div>
        </x-sth.card>
    </section>

    {{-- Ikon --}}
    <section id="ikon" @unless ($show('ikon')) hidden @endunless aria-labelledby="h-ikon" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-ikon" title="Ikon" subtitle="Nama sesuai spec §6 · 20px desktop, 22px mobile" />
        <x-sth.card>
            <ul class="m-0 grid list-none grid-cols-3 gap-2 p-0 sm:grid-cols-6 xl:grid-cols-9">
                @foreach ($icons as $icon)
                    <li class="flex flex-col items-center gap-1.5 rounded-control bg-subtle px-2 py-3 text-ink-2">
                        <x-sth.icon :name="$icon" :size="22" />
                        <span class="text-xs font-semibold">{{ $icon }}</span>
                    </li>
                @endforeach
            </ul>
        </x-sth.card>
    </section>

    {{-- Chip & badge --}}
    <section id="chip" @unless ($show('chip')) hidden @endunless aria-labelledby="h-chip" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-chip" title="Chip & badge" subtitle="Status selalu punya teks" />
        <x-sth.card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2">
                <x-sth.chip tone="green" icon="check">Disetujui</x-sth.chip>
                <x-sth.chip tone="orange" icon="clock">Menunggu laporan · batas 8 Nov</x-sth.chip>
                <x-sth.chip tone="red" icon="x">Ditolak</x-sth.chip>
                <x-sth.chip tone="gray" icon="clock">Menunggu Kepala Sekolah</x-sth.chip>
                <x-sth.chip tone="blue" icon="info">Nomor surat diproses</x-sth.chip>
                <x-sth.chip tone="gold" class="num">+68 poin</x-sth.chip>
                <x-sth.chip tone="gray" size="sm">Alumni</x-sth.chip>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-sth.chip bidang="sains" />
                <x-sth.chip bidang="riset" />
                <x-sth.chip bidang="seni_budaya" />
                <x-sth.chip bidang="olahraga" />
                <x-sth.chip bidang="sains" icon />
                <x-sth.chip bidang="riset" icon />
                <x-sth.chip bidang="seni_budaya" icon />
                <x-sth.chip bidang="olahraga" icon />
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-sth.sampul bidang="riset" seed="contoh" class="h-16 w-40 items-start rounded-tile p-3"><x-sth.chip tone="glass">Nasional</x-sth.chip></x-sth.sampul>
                <x-sth.badge>2</x-sth.badge>
                <x-sth.badge tone="gray">8</x-sth.badge>
                @foreach (['sains', 'riset', 'seni', 'olahraga'] as $b)
                    <x-sth.bidang-tile :bidang="$b" />
                @endforeach
                <x-sth.bidang-tile bidang="riset" size="sm" />
            </div>
        </x-sth.card>

        {{-- Sampul lomba: the 12 template drawings (class names written out here; <x-sth.sampul> picks one from the lomba id). --}}
        <x-sth.card class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <h3 class="title-card">Sampul lomba</h3>
                <p class="m-0 text-sm text-muted">Poster asli kalau ada, kalau tidak gambar bidang. Tiga variasi per bidang, dipilih dari ID lomba. Versi pekat untuk sampul besar halaman detail.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                @foreach (['sains', 'riset', 'seni', 'olahraga'] as $b)
                    @foreach ([1, 2, 3] as $n)
                        <div class="sampul sampul-{{ $b }}-{{ $n }} h-24 items-start rounded-tile p-2.5"><x-sth.chip tone="glass" size="sm">{{ $b }} {{ $n }}</x-sth.chip></div>
                    @endforeach
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                @foreach (['sains-2', 'riset-3', 'seni-1', 'olahraga-2'] as $k)
                    <div class="sampul sampul-pekat sampul-{{ $k }} h-24 items-start rounded-tile p-2.5"><x-sth.chip tone="glass" size="sm">{{ $k }} pekat</x-sth.chip></div>
                @endforeach
            </div>
        </x-sth.card>
    </section>

    {{-- Kartu & stat tile --}}
    <section id="kartu" @unless ($show('kartu')) hidden @endunless aria-labelledby="h-kartu" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-kartu" title="Kartu & stat tile" />
        <div class="grid grid-cols-2 gap-3 sm:gap-[18px] xl:grid-cols-4">
            <x-sth.stat-tile icon="trophy" tone="blue" label="Lomba aktif" value="6" note="pendaftaran dibuka" />
            <x-sth.stat-tile icon="users" tone="green" label="Pendaftar bulan ini" value="39" note="siswa di 7 lomba" />
            <x-sth.stat-tile icon="inbox" tone="orange" label="Usulan lomba" value="3" note="menunggu ditinjau" href="#kartu" />
            <x-sth.stat-tile icon="clock" tone="gray" label="Ditutup minggu ini" value="1" note="FLS3N Kota · 2 Okt" />
        </div>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-sth.card as="article" class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <x-sth.bidang-tile bidang="riset" />
                    <div class="flex min-w-0 flex-col items-start gap-1">
                        <h3 class="title-card"><a class="ttl-link" href="#kartu">WICE 2026</a> <span class="font-sans text-xs font-medium text-muted">· Internasional · Tim 3</span></h3>
                        <x-sth.chip tone="orange" icon="clock" size="sm">Menunggu laporan · batas 8 Nov</x-sth.chip>
                    </div>
                </div>
                <x-sth.tracker :phase="5" state="action" compact />
            </x-sth.card>
            <x-sth.card tone="gold" class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-medal text-white" aria-hidden="true"><x-sth.icon name="award" /></span>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <h3 class="m-0 font-sans text-xs font-bold tracking-normal text-gold">Poin prestasi</h3>
                        <p class="m-0 flex items-baseline gap-1.5"><span class="display num text-4xl leading-[1.1] font-bold">104</span><span class="text-sm font-semibold text-gold">poin</span></p>
                    </div>
                    <x-sth.link href="#kartu" icon-right="chevron-right" class="self-start text-sm">Peringkat</x-sth.link>
                </div>
            </x-sth.card>
            <x-sth.card tone="warn" padding="sm" class="flex items-center gap-3.5">
                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-orange-bg text-orange"><x-sth.icon name="upload" /></span>
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <x-sth.chip tone="orange" size="sm" class="self-start">Perlu tindakan</x-sth.chip>
                    <p class="m-0 text-base leading-tight font-bold">WICE 2026 · laporan hasil</p>
                    <p class="num m-0 text-sm font-semibold text-orange">Batas 8 Nov 2026 · 39 hari lagi</p>
                </div>
            </x-sth.card>
        </div>
    </section>

    {{-- Formulir --}}
    <section id="form" @unless ($show('form')) hidden @endunless aria-labelledby="h-form" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-form" title="Formulir" subtitle="Label selalu ada · pesan error otomatis" />
        <x-sth.card class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <x-sth.input name="demo_nama" label="Nama lomba" placeholder="Contoh: OSN Tingkat Kota Semarang 2026" hint="Tulis tanpa singkatan." />
            <x-sth.input name="demo_web" type="url" label="Website resmi" placeholder="https://" optional icon="link" />
            <x-sth.input name="demo_nis" label="NIS / NISN" inputmode="numeric" autocomplete="off" size="lg" placeholder="Ketik NIS atau NISN" />
            <x-sth.input name="demo_sandi" type="password" label="Kata sandi" revealable autocomplete="off" size="lg" placeholder="Ketik kata sandi" />
            <x-sth.input name="demo_email" type="email" label="Email" value="n•••@gmail" error="Format email belum benar." />
            <x-sth.select name="demo_guru" label="Guru pembimbing" value="hendra" :options="[
                'hendra' => 'Pak Hendra Kusuma, S.Pd., M.Si.',
                'retno' => 'Bu Retno Wulandari, S.Pd.',
                'maya' => 'Bu Maya Anggraini, S.Sn.',
                'agus' => 'Pak Agus Setiawan, S.Or.',
            ]" />
            <x-sth.textarea name="demo_catatan" label="Catatan untuk siswa" rows="3" placeholder="Tulis alasan singkat" optional class="lg:col-span-2" />
            <x-sth.radio-pick name="demo_tingkat" label="Tingkat" value="nasional" :options="['sekolah' => 'Sekolah', 'kota' => 'Kota/Kab', 'provinsi' => 'Provinsi', 'nasional' => 'Nasional', 'internasional' => 'Internasional']" />
            <x-sth.radio-pick name="demo_kategori" label="Kategori penyelenggara" value="A" :columns="5" :options="[
                'A' => ['label' => 'A', 'sub' => '1,00'], 'B' => ['label' => 'B', 'sub' => '0,80'], 'C' => ['label' => 'C', 'sub' => '0,60'],
                'D' => ['label' => 'D', 'sub' => '0,40'], 'E' => ['label' => 'E', 'sub' => '0,20'],
            ]" />
            <x-sth.radio-pick name="demo_minat[]" multiple label="Minat bidang" :value="['riset', 'sains']" :columns="4" :options="[
                'sains' => ['label' => 'Sains', 'icon' => 'sains'], 'riset' => ['label' => 'Riset', 'icon' => 'riset'],
                'seni_budaya' => ['label' => 'Seni Budaya', 'icon' => 'seni'], 'olahraga' => ['label' => 'Olahraga', 'icon' => 'olahraga'],
            ]" />
            <div class="flex flex-col gap-4">
                <x-sth.segmented name="demo_peserta" label="Daftar sebagai" value="tim" block :options="['individu' => ['label' => 'Individu', 'icon' => 'user'], 'tim' => ['label' => 'Tim', 'icon' => 'users']]" />
                <x-sth.segmented name="demo_mode" label="Tampilkan" value="siswa" variant="solid" :options="['siswa' => 'Siswa', 'kelas' => 'Kelas']" />
            </div>
            <x-sth.file-drop name="demo_sertifikat" label="Sertifikat" accept=".pdf,.jpg,.jpeg,.png" />
            <x-sth.file-drop name="demo_foto[]" multiple label="Foto dokumentasi" accept=".jpg,.jpeg,.png,.webp" icon="image" hint="JPG atau PNG · maks. 10 MB · boleh lebih dari satu" optional />
            <div class="flex flex-col gap-2 lg:col-span-2">
                <x-sth.checkbox name="demo_setuju" label="Data tim sudah benar dan semua anggota setuju ikut." />
                <x-sth.checkbox name="demo_asli" checked>Dokumen ini asli dan sesuai hasil lomba.</x-sth.checkbox>
            </div>
        </x-sth.card>
    </section>

    {{-- Tabel & empty state --}}
    <section id="tabel" @unless ($show('tabel')) hidden @endunless aria-labelledby="h-tabel" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-tabel" title="Tabel & kosong" />
        <x-sth.table caption="Katalog lomba SMAGA, 5 lomba" fixed class="min-w-0" table-class="min-w-[880px]">
            <x-slot:head>
                <th scope="col" class="w-[38%] min-w-[280px]">Lomba</th>
                <th scope="col" class="w-32">Bidang</th>
                <th scope="col" class="w-24">Tingkat</th>
                <th scope="col" class="w-28">Batas daftar</th>
                <th scope="col" class="w-24">Pendaftar</th>
                <th scope="col" class="w-48">Status</th>
                <th scope="col" class="w-20"><span class="sr-only">Aksi</span></th>
            </x-slot:head>
            @foreach ($rows as $row)
                <tr>
                    <td>
                        <div class="flex min-w-0 items-center gap-3">
                            <x-sth.bidang-tile :bidang="$row['bidang']" size="sm" />
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="leading-[1.35] font-bold">{{ $row['title'] }}</span>
                                <span class="ellip text-xs text-muted">{{ $row['sub'] }}</span>
                            </div>
                        </div>
                    </td>
                    <td><x-sth.chip :bidang="$row['bidang']" /></td>
                    <td>{{ $row['tingkat'] }}</td>
                    <td>
                        <div class="flex flex-col gap-0.5">
                            <span class="num">{{ $row['batas'] }}</span>
                            @if ($row['soon'])<span class="text-xs font-bold text-orange">{{ $row['soon'] }}</span>@endif
                        </div>
                    </td>
                    <td class="num">{{ $row['count'] }} siswa</td>
                    <td><x-sth.chip :tone="$row['status'][0]" :icon="$row['status'][1]">{{ $row['status'][2] }}</x-sth.chip></td>
                    <td><x-sth.icon-button icon="edit" size="sm" :label="'Ubah '.$row['title']" /></td>
                </tr>
            @endforeach
        </x-sth.table>
        <x-sth.empty-state icon="search" title="Belum ada lomba yang cocok">
            Coba ubah filter, atau usulkan lomba baru.
            <x-slot:actions>
                <x-sth.button variant="quiet" size="sm">Hapus filter</x-sth.button>
                <x-sth.button variant="soft" size="sm" data-sth-open="demo-usul" aria-haspopup="dialog">Usulkan lomba</x-sth.button>
            </x-slot:actions>
        </x-sth.empty-state>
    </section>

    {{-- Avatar --}}
    <section id="avatar" @unless ($show('avatar')) hidden @endunless aria-labelledby="h-avatar" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-avatar" title="Avatar" subtitle="Inisial nama depan + belakang; foto hanya dengan persetujuan" />
        <x-sth.card class="flex flex-wrap items-center gap-5">
            <x-sth.avatar name="Nadia Putri Anindya" size="xs" />
            <x-sth.avatar name="Nadia Putri Anindya" size="sm" />
            <x-sth.avatar name="Nadia Putri Anindya" />
            <x-sth.avatar name="Nadia Putri Anindya" size="lg" labelled />
            <x-sth.avatar name="Nadia Putri Anindya" size="xl" />
            <x-sth.avatar name="Ibu Dwi Rahayu" />
            <x-sth.avatar name="Drs. Bambang Hartono, M.Pd." />
            <div class="flex items-center gap-2">
                <x-sth.avatar-stack :names="['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama']" />
                <span class="text-xs text-muted">3 siswa SMAGA mendaftar</span>
            </div>
            <div class="flex items-center gap-2">
                <x-sth.avatar-stack size="sm" :names="['Hana Safira', 'Aurelia Maharani', 'Rizky Maulana', 'Citra Ayu Lestari', 'Salsabila Kirana']" />
                <span class="text-xs text-muted">5 siswa</span>
            </div>
        </x-sth.card>
    </section>

    {{-- Tab --}}
    <section id="tab" @unless ($show('tab')) hidden @endunless aria-labelledby="h-tab" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-tab" title="Tab" subtitle="Panel klien (←/→) · pill · link" />
        <x-sth.card padding="none" class="overflow-hidden">
            <div class="px-6">
                <x-sth.tabs panels id="demo-detail" label="Detail lomba" active="tentang" :items="[
                    ['key' => 'tentang', 'label' => 'Tentang'],
                    ['key' => 'persyaratan', 'label' => 'Persyaratan'],
                    ['key' => 'jadwal', 'label' => 'Jadwal'],
                    ['key' => 'dokumen', 'label' => 'Dokumen', 'count' => 1],
                ]" />
            </div>
            <div class="px-6 py-5">
                <x-sth.tab-panel tabs="demo-detail" name="tentang" active><p class="m-0">Pameran inovasi sains dan penemuan tingkat nasional, presentasi daring.</p></x-sth.tab-panel>
                <x-sth.tab-panel tabs="demo-detail" name="persyaratan"><p class="m-0">Tim berisi paling banyak 3 siswa dari sekolah yang sama.</p></x-sth.tab-panel>
                <x-sth.tab-panel tabs="demo-detail" name="jadwal"><p class="num m-0">20 Okt 2026 · Batas pendaftaran</p></x-sth.tab-panel>
                <x-sth.tab-panel tabs="demo-detail" name="dokumen"><p class="m-0">juknis-nsif-2026.pdf · PDF · 1,2 MB</p></x-sth.tab-panel>
            </div>
        </x-sth.card>
        <x-sth.card padding="sm" class="flex flex-wrap items-center justify-between gap-3">
            <x-sth.tabs panels id="demo-kelola" variant="pill" label="Daftar lomba" active="katalog" :items="[
                ['key' => 'katalog', 'label' => 'Katalog', 'count' => 8],
                ['key' => 'usulan', 'label' => 'Usulan lomba', 'count' => 3],
            ]" />
            <x-sth.tab-panel tabs="demo-kelola" name="katalog" active class="text-xs text-muted">Urut dari batas daftar terdekat</x-sth.tab-panel>
            <x-sth.tab-panel tabs="demo-kelola" name="usulan" class="text-xs text-muted">Semua upload menunggu verifikasi</x-sth.tab-panel>
        </x-sth.card>
    </section>

    {{-- Tracker --}}
    <section id="tracker" @unless ($show('tracker')) hidden @endunless aria-labelledby="h-tracker" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-tracker" title="Jejak prestasi" subtitle="phase 1–6 · state current|action|done|upcoming" />
        <x-sth.card class="flex flex-col gap-6">
            <div class="flex flex-col gap-3">
                <span class="label">WICE 2026 · tahap 5, perlu tindakan</span>
                <x-sth.tracker :phase="5" state="action" :dates="['2 Sep', '5 Sep', '8 Sep', '21–24 Sep', 'Batas 8 Nov', '']" />
            </div>
            <div class="flex flex-col gap-3">
                <span class="label">LPB 2026 · tahap 2</span>
                <x-sth.tracker :phase="2" :dates="[1 => '29 Sep', 2 => 'Menunggu Kepala Sekolah', 4 => '17 Okt']" />
            </div>
            <div class="flex flex-col gap-3">
                <span class="label">OPSI 2026 · selesai</span>
                <x-sth.tracker :phase="6" state="done" :dates="['6 Jul', '8 Jul', '10 Jul', '4–7 Agu', '12 Agu', '15 Agu']" />
            </div>
            <div class="grid gap-6 border-t border-line pt-5 sm:grid-cols-3">
                <x-sth.tracker :phase="5" state="action" compact />
                <x-sth.tracker :phase="2" compact />
                <x-sth.tracker :phase="6" state="done" compact />
            </div>
        </x-sth.card>
    </section>

    {{-- Rumus poin --}}
    <section id="poin" @unless ($show('poin')) hidden @endunless aria-labelledby="h-poin" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-poin" title="Rumus poin" subtitle="Tingkat × Capaian × Penyelenggara · koma desimal" />
        <x-sth.card class="flex flex-col gap-5">
            <div class="flex flex-col gap-2">
                <span class="label">OPSI 2026 · Nasional · Juara II · Kategori A</span>
                <x-sth.rumus-poin :tingkat="80" :capaian="0.85" :penyelenggara="1" :total="68" />
            </div>
            <div class="flex flex-col gap-2">
                <span class="label">Perkiraan WICE 2026 · Medali emas <span class="font-medium text-muted">(final setelah diverifikasi)</span></span>
                <x-sth.rumus-poin :tingkat="100" capaian="1.00" penyelenggara="0.40" :total="40" result-label="Total" />
            </div>
        </x-sth.card>
    </section>

    {{-- Kartu lomba --}}
    <section id="lomba" @unless ($show('lomba')) hidden @endunless aria-labelledby="h-lomba" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-lomba" title="Rekomendasi untukmu" subtitle="Sesuai bidang minatmu: Riset & Sains">
            <x-slot:actions><x-sth.link href="#lomba" icon-right="chevron-right">Lihat semua lomba</x-sth.link></x-slot:actions>
        </x-sth.page-header>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($catalog as $c)
                <x-sth.lomba-card
                    :title="$c['title']" href="#lomba" :bidang="$c['bidang']" :tingkat="$c['tingkat']"
                    :status="$c['status']" :status-tone="$c['tone']" :peserta="$c['peserta']" :penyelenggara="$c['org']"
                    :date="$c['date']" :place="$c['place']" :names="$c['names']" :count="$c['count']" :note="$c['note'] ?? null"
                />
            @endforeach
        </div>
    </section>

    {{-- Header, facts, alert --}}
    <section id="header" @unless ($show('header')) hidden @endunless aria-labelledby="h-header" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-header" title="Header, fakta, catatan" />
        <x-sth.card class="flex flex-col gap-5">
            <x-sth.page-header :level="1" title="National Science and Invention Fair (NSIF) 2026" subtitle="Penyelenggara swasta · Kategori D"
                :breadcrumbs="[['label' => 'Cari lomba', 'href' => '#header'], ['label' => 'NSIF 2026']]">
                <x-slot:actions><x-sth.button icon="users">Daftar lomba</x-sth.button></x-slot:actions>
            </x-sth.page-header>
            <x-sth.facts :items="[
                ['icon' => 'clock', 'label' => 'Batas daftar', 'value' => '20 Okt 2026'],
                ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => '7 Nov 2026'],
                ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => 'Nasional'],
                ['icon' => 'pin', 'label' => 'Lokasi', 'value' => 'Daring'],
                ['icon' => 'users', 'label' => 'Peserta', 'value' => 'Tim maks 3'],
            ]" />
            <div class="grid gap-3 lg:grid-cols-2">
                <x-sth.alert>Baru pertama masuk? Kamu akan diminta mengganti kata sandi dan menghubungkan email.</x-sth.alert>
                <x-sth.alert tone="success">Pendaftaran terkirim. Menunggu persetujuan Kepala Sekolah.</x-sth.alert>
                <x-sth.alert tone="warning" title="Perlu tindakan">Laporkan hasil WICE 2026 sebelum 8 Nov 2026.</x-sth.alert>
                <x-sth.alert tone="error">NIS/NISN atau kata sandi salah.</x-sth.alert>
            </div>
            <p class="note"><x-sth.icon name="users" :size="18" />Setiap anggota tim mendapat poin penuh.</p>
        </x-sth.card>
    </section>

    {{-- Dialog & toast --}}
    <section id="dialog" @unless ($show('dialog')) hidden @endunless aria-labelledby="h-dialog" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-dialog" title="Dialog & toast" subtitle="Dialog asli browser · data-sth-open · event Livewire" />
        <x-sth.card class="flex flex-col gap-4">
            <div class="flex flex-wrap gap-3">
                <x-sth.button variant="quiet" data-sth-open="demo-dialog" aria-haspopup="dialog">Buka dialog</x-sth.button>
                <x-sth.button variant="quiet" data-sth-open="demo-sheet" aria-haspopup="dialog">Buka sheet</x-sth.button>
                <x-sth.button variant="soft" data-sth-open="demo-usul" aria-haspopup="dialog">Buka drawer</x-sth.button>
            </div>
            <div class="flex flex-col items-start gap-2.5">
                <x-sth.toast>Lomba dipublikasikan.</x-sth.toast>
                <x-sth.toast tone="info">Nomor surat 400.3.8/312/MRD/IX/2026 tersimpan.</x-sth.toast>
                <x-sth.toast tone="warning">Batas laporan WICE 2026 tinggal 7 hari.</x-sth.toast>
                <x-sth.toast tone="error">Nomor surat sudah dipakai.</x-sth.toast>
            </div>
        </x-sth.card>

        <x-sth.modal id="demo-dialog" title="Setujui pendaftaran?" description="LPB 2026 · Nadia Putri Anindya, Dimas Arya Nugroho" size="sm">
            <p class="m-0">Siswa dan TU akan mendapat pemberitahuan.</p>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button icon="check" data-sth-close>Setujui</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>

        <x-sth.modal id="demo-sheet" title="Konfirmasi ikut" variant="sheet">
            <p class="m-0">Nadia Putri Anindya mengajakmu ikut NSIF 2026 sebagai anggota tim.</p>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Tolak</x-sth.button>
                <x-sth.button data-sth-close>Konfirmasi ikut</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>

        <x-sth.modal id="demo-usul" title="Usulkan lomba" description="Kesiswaan meninjau usulanmu sebelum masuk katalog." variant="drawer">
            <form id="demo-usul-form" class="flex flex-col gap-4" action="#" method="GET">
                <x-sth.input name="usul_nama" label="Nama lomba" placeholder="Contoh: International Science Project Olympiad (ISPO) 2027" />
                <x-sth.input name="usul_penyelenggara" label="Penyelenggara" />
                <x-sth.input name="usul_tautan" type="url" label="Link info" placeholder="https://" icon="link" optional />
                <x-sth.file-drop name="usul_brosur" label="Brosur atau juknis" accept=".pdf,.jpg,.jpeg,.png" optional />
            </form>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button icon="send" data-sth-close>Kirim usulan</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    </section>

    {{-- Layout --}}
    <section id="layout" @unless ($show('layout')) hidden @endunless aria-labelledby="h-layout" class="flex flex-col gap-3 scroll-mt-6">
        <x-sth.page-header id="h-layout" title="Layout" subtitle="layouts::app (halaman ini) · public · auth · mobile" />
        <x-sth.card class="flex flex-wrap gap-3">
            <x-sth.button variant="quiet" :href="route('dev.components.public')">Publik</x-sth.button>
            <x-sth.button variant="quiet" :href="route('dev.components.auth')">Masuk (split)</x-sth.button>
            <x-sth.button variant="quiet" :href="route('dev.components.auth', ['variant' => 'simple'])">Akun (simple)</x-sth.button>
            <x-sth.button variant="quiet" :href="route('dev.components.mobile')">Mobile</x-sth.button>
        </x-sth.card>
    </section>
</x-layouts::app>
