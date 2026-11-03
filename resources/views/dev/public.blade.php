{{-- Local preview of layouts::public (/_dev/components/public). Fictional data, DESIGN-SPEC-v2 §5. --}}
<x-layouts::public title="Preview publik" current="beranda" robots="noindex">
    {{-- Hero: one ink banner (lintasan motif on the right). Nothing floats beside the headline, and "Masuk" is
         only in the header. --}}
    <section aria-labelledby="hero-title" class="spanduk rounded-card px-5 py-8 sm:px-8 sm:py-10 xl:px-10 xl:py-12">
        <div class="flex max-w-[640px] flex-col items-start gap-3.5">
            <p class="m-0 inline-flex items-center gap-2 text-sm font-semibold text-on-primary-soft"><x-sth.icon name="award" :size="16" />Prestasi SMA Negeri 3 Semarang</p>
            <h1 id="hero-title" class="m-0 text-4xl leading-[1.06] font-bold tracking-[-0.02em] text-balance text-white md:text-5xl">Prestasi siswa SMAGA, dalam satu tempat.</h1>
            <p class="m-0 text-lg leading-normal text-on-primary-soft">Capaian lomba siswa yang sudah diverifikasi sekolah.</p>
            <x-sth.button variant="white" :href="'#bidang'" icon-right="chevron-right" class="mt-2.5">Lihat prestasi</x-sth.button>
        </div>
    </section>

    <section id="bidang" aria-labelledby="h-bidang" class="flex flex-col gap-3">
        <x-sth.page-header id="h-bidang" title="Prestasi per bidang" subtitle="Kumulatif 93 prestasi" />
        <div class="grid auto-rows-fr grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([['sains', 'Sains', 29], ['riset', 'Riset', 24], ['seni', 'Seni Budaya', 21], ['olahraga', 'Olahraga', 19]] as [$key, $name, $total])
                <a href="#bidang" class="cover-{{ $key }} flex h-full items-center gap-3 rounded-card px-4 py-3.5 no-underline">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-white"><x-sth.icon :name="$key" :size="22" /></span>
                    <span class="flex flex-col">
                        <span class="text-md font-bold text-ink">{{ $name }}</span>
                        <span class="num text-xs font-semibold">{{ $total }} prestasi</span>
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
            @foreach ([['Raka Pradipta', 'XII-B', 312], ['Salsabila Kirana', 'XI-E', 268], ['Bima Satria Wicaksono', 'XII-G', 240], ['Aurelia Maharani', 'X-C', 196], ['Fajar Ramadhan', 'XII-A', 180]] as $i => [$name, $class, $points])
                <tr>
                    <td><span @class(['inline-flex size-[26px] items-center justify-center rounded-full text-xs font-bold', 'bg-medal text-ink' => $i === 0, 'bg-gray-bg text-gray' => $i > 0])>{{ $i + 1 }}</span></td>
                    <td><span class="flex items-center gap-2.5"><x-sth.avatar :name="$name" size="sm" /><span class="font-semibold">{{ $name }}</span></span></td>
                    <td>{{ $class }}</td>
                    <td class="num text-right font-bold">{{ $points }}</td>
                </tr>
            @endforeach
        </x-sth.table>
    </section>
</x-layouts::public>
