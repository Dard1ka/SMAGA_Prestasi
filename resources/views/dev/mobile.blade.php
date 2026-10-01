{{-- Local preview of layouts::mobile (/_dev/components/mobile), modelled on Lapor-Hasil-Mobile. Deadline per D-25. --}}
<x-layouts::mobile title="Laporkan hasil" subtitle="WICE 2026 · Riset · Tim 3 orang" :back="route('dev.components')" back-label="Kembali ke komponen" :tabbar="\App\Support\Navigation::tabbar(active: 'lombaku', preview: true)">
    <p class="m-0 flex items-start gap-2.5 rounded-2xl border border-orange-line bg-orange-bg px-3.5 py-3 text-[15px] leading-[22px] font-semibold text-orange">
        <x-sth.icon name="clock" :size="20" class="mt-px" />
        Batas 8 Nov 2026 (45 hari setelah lomba).
    </p>

    <x-sth.card as="section" padding="sm" aria-labelledby="hasil-judul" class="flex flex-col gap-3.5">
        <h2 id="hasil-judul" class="m-0 text-[19px] leading-6 font-bold">Hasil yang diraih</h2>
        <x-sth.radio-pick name="hasil" label="Hasil" hide-label value="medali_emas" :columns="2" :options="[
            'medali_emas' => 'Medali emas', 'medali_perak' => 'Medali perak', 'medali_perunggu' => 'Medali perunggu',
            'finalis' => 'Finalis', 'penghargaan_khusus' => 'Penghargaan khusus', 'peserta' => 'Peserta',
        ]" />
        <div class="flex flex-col gap-2.5 border-t border-line pt-3.5">
            <p class="m-0 text-sm font-bold">Perkiraan poin <span class="text-[13px] font-medium text-muted">(final setelah diverifikasi)</span></p>
            <x-sth.rumus-poin :tingkat="100" capaian="1.00" penyelenggara="0.40" :total="40" result-label="Total" />
        </div>
    </x-sth.card>

    <x-sth.card as="section" padding="sm" aria-labelledby="bukti-judul" class="flex flex-col gap-3.5">
        <h2 id="bukti-judul" class="m-0 text-[19px] leading-6 font-bold">Bukti</h2>
        <x-sth.file-drop name="sertifikat" label="Sertifikat" accept=".pdf,.jpg,.jpeg,.png" />
        <x-sth.file-drop name="foto[]" multiple label="Foto dokumentasi" accept=".jpg,.jpeg,.png,.webp" icon="image" optional />
        <x-sth.checkbox name="asli">Dokumen ini asli dan sesuai hasil lomba.</x-sth.checkbox>
    </x-sth.card>

    <x-slot:action>
        <x-sth.button size="lg" block icon="send">Kirim laporan</x-sth.button>
    </x-slot:action>
</x-layouts::mobile>
