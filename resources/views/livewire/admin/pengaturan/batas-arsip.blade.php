{{-- Tab "Batas & arsip" (Admin-Pengaturan.dc.html; 45 days after the final per D-25, not the mockup's 30). --}}
<section aria-labelledby="h-batas" class="flex flex-col gap-5">
    <x-sth.page-header id="h-batas" title="Batas & arsip" subtitle="Batas laporan hasil dan kapan akun siswa diarsipkan." />

    <form wire:submit="save" class="grid gap-4 md:grid-cols-2" novalidate>
        <x-sth.card as="section" aria-labelledby="h-batas-laporan" class="flex flex-col gap-3">
            <h3 id="h-batas-laporan" class="title-card">Batas laporan hasil</h3>
            <x-sth.input wire:model.live.debounce.400ms="reportDeadlineDays" type="number" min="1" max="365" inputmode="numeric"
                         label="Hari setelah tanggal final" hint="Default 45 hari. Laporan lewat batas tampil di dashboard sekolah." />
            @if ($deadlineExample)
                <p class="text-sm muted">Contoh: {{ $deadlineExample }}</p>
            @endif
        </x-sth.card>

        <x-sth.card as="section" aria-labelledby="h-batas-arsip" class="flex flex-col gap-3">
            <h3 id="h-batas-arsip" class="title-card">Arsip otomatis</h3>
            <x-sth.input wire:model.live.debounce.400ms="archiveMonths" type="number" min="1" max="120" inputmode="numeric"
                         label="Bulan setelah awal kelas XII" hint="Default 18 bulan. Mengubahnya menghitung ulang tanggal arsip siswa yang belum diarsipkan." />
            @if ($archiveExample)
                <p class="text-sm muted">Contoh: {{ $archiveExample }}</p>
            @endif
        </x-sth.card>

        <div class="flex justify-end md:col-span-2">
            <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="save">Simpan batas</x-sth.button>
        </div>
    </form>

    <div class="grid grid-cols-2 gap-3 sm:gap-4">
        <x-sth.stat-tile icon="archive" tone="orange" label="Akan diarsipkan 30 hari ke depan" :value="(string) $dueSoon" note="akun siswa" />
        <x-sth.stat-tile icon="clock" tone="blue" label="Jadwal arsip otomatis" value="01.00 WIB" note="setiap hari, akun alumni tidak bisa login" />
    </div>

    @if ($recentlyArchived->isEmpty())
        <x-sth.empty-state icon="archive" title="Belum ada akun yang diarsipkan">
            Akun siswa diarsipkan otomatis saat tanggal arsipnya tiba.
        </x-sth.empty-state>
    @else
        <x-sth.table caption="Akun siswa yang baru diarsipkan" caption-visible>
            <x-slot:head>
                <th scope="col">Nama</th>
                <th scope="col">Kelas terakhir</th>
                <th scope="col">Kelas XII mulai</th>
                <th scope="col">Diarsipkan</th>
                <th scope="col" class="text-right">Prestasi</th>
                <th scope="col">Status</th>
            </x-slot:head>
            @foreach ($recentlyArchived as $student)
                <tr wire:key="arsip-{{ $student->id }}">
                    <td class="font-semibold">{{ $student->user?->name }}</td>
                    <td>{{ $student->classroom?->name ?? '–' }}</td>
                    <td class="num">{{ $student->grade12_start_date?->translatedFormat('M Y') ?? '–' }}</td>
                    <td class="num">{{ $student->archive_at?->translatedFormat('j M Y') ?? '–' }}</td>
                    <td class="num text-right">{{ $student->achievements_count }}</td>
                    <td><x-sth.chip tone="gray" size="sm">Alumni · tidak bisa login</x-sth.chip></td>
                </tr>
            @endforeach
        </x-sth.table>
        <p class="text-sm muted">Prestasi alumni tetap tampil di peringkat dan halaman publik.</p>
    @endif
</section>
