{{-- Local preview of layouts::public (/_dev/components/public). Fictional data, DESIGN-SPEC-v2 §5. --}}
<x-layouts::public title="Preview publik" current="beranda" robots="noindex">
    <section aria-labelledby="hero-title" class="grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_480px]">
        <div class="flex flex-col items-start gap-3.5">
            <x-sth.chip tone="blue" icon="award" class="h-[30px] px-3 text-[13px]">Prestasi SMA Negeri 3 Semarang</x-sth.chip>
            <h1 id="hero-title" class="m-0 max-w-[600px] text-[36px] leading-[1.06] font-extrabold tracking-[-0.02em] text-balance md:text-[52px]">Prestasi siswa SMAGA, dalam satu tempat.</h1>
            <p class="m-0 text-[17px] leading-normal text-ink-2">Capaian lomba siswa yang sudah diverifikasi sekolah.</p>
            <div class="flex flex-wrap items-center gap-3 pt-2.5">
                <x-sth.button href="#bidang" icon-right="arrow-right">Lihat prestasi</x-sth.button>
                <x-sth.button variant="quiet" :href="route('dev.components.auth')">Masuk</x-sth.button>
            </div>
        </div>
        <div class="card overflow-hidden" aria-hidden="true">
            <div class="cover-sains relative h-28 p-3">
                <x-sth.chip tone="glass">Bidang terbanyak</x-sth.chip>
                <x-sth.icon name="sains" :size="56" class="absolute right-4 bottom-3 opacity-70" />
            </div>
            <div class="flex flex-col gap-0.5 px-4 pt-3 pb-3.5">
                <span class="display text-base font-bold">Bidang Sains</span>
                <span class="text-[12.5px] text-muted">29 prestasi terverifikasi</span>
            </div>
        </div>
    </section>

    <section id="bidang" aria-labelledby="h-bidang" class="flex flex-col gap-3">
        <x-sth.page-header id="h-bidang" title="Prestasi per bidang" subtitle="Kumulatif 93 prestasi" />
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([['sains', 'Sains', 29], ['riset', 'Riset', 24], ['seni', 'Seni Budaya', 21], ['olahraga', 'Olahraga', 19]] as [$key, $name, $total])
                <a href="#bidang" class="cover-{{ $key }} flex items-center gap-3 rounded-2xl px-4 py-3.5 no-underline">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-white/70"><x-sth.icon :name="$key" :size="22" /></span>
                    <span class="flex flex-col">
                        <span class="text-[15px] font-bold text-ink">{{ $name }}</span>
                        <span class="num text-[13px] font-semibold">{{ $total }} prestasi</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="h-peringkat" class="flex flex-col gap-3">
        <x-sth.page-header id="h-peringkat" title="Peringkat kumulatif">
            <x-slot:actions><x-sth.link href="#" icon-right="chevron-right">Lihat semua</x-sth.link></x-slot:actions>
        </x-sth.page-header>
        <x-sth.table caption="Peringkat kumulatif, 5 teratas">
            <x-slot:head>
                <th scope="col" class="w-20">#</th>
                <th scope="col">Nama</th>
                <th scope="col" class="w-28">Kelas</th>
                <th scope="col" class="w-28 text-right">Poin</th>
            </x-slot:head>
            @foreach ([['Raka Pradipta', 'XII-2', 312], ['Salsabila Kirana', 'XI-5', 268], ['Bima Satria Wicaksono', 'XII-7', 240], ['Aurelia Maharani', 'X-3', 196], ['Fajar Ramadhan', 'XII-1', 180]] as $i => [$name, $class, $points])
                <tr>
                    <td><span @class(['inline-flex size-[26px] items-center justify-center rounded-full text-[12.5px] font-bold', 'bg-medal text-ink' => $i === 0, 'bg-gray-bg text-gray' => $i > 0])>{{ $i + 1 }}</span></td>
                    <td><span class="flex items-center gap-2.5"><x-sth.avatar :name="$name" size="sm" /><span class="font-semibold">{{ $name }}</span></span></td>
                    <td>{{ $class }}</td>
                    <td class="num text-right font-bold">{{ $points }}</td>
                </tr>
            @endforeach
        </x-sth.table>
    </section>
</x-layouts::public>
