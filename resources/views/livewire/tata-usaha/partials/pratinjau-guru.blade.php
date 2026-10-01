{{--
    HTML preview of the Surat Perintah Tugas + Lampiran (same text as resources/pdf/surat-perintah-tugas-guru.blade.php).
    Vars: $content (LetterContent) · $number (issued number or null) · $parts [prefix, suffix] · $seq (typed digits)
--}}
<div class="relative mx-auto flex w-full max-w-[560px] flex-col gap-3 overflow-hidden border border-line bg-white p-6 font-serif text-[12.5px] leading-snug text-ink shadow-card sm:min-h-[792px] sm:p-9" aria-label="Pratinjau surat perintah tugas guru">
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center select-none" aria-hidden="true">
        <span class="-rotate-[30deg] text-[64px] font-bold tracking-[0.2em] text-line-soft">PRATINJAU</span>
    </div>

    @include('livewire.tata-usaha.partials.kop')

    <div class="relative flex flex-col items-center gap-0.5">
        <p class="m-0 text-[14px] font-bold underline">SURAT PERINTAH TUGAS</p>
        <p class="m-0">Nomor @include('livewire.tata-usaha.partials.nomor')</p>
    </div>

    <div class="relative grid grid-cols-[60px_10px_1fr] gap-y-0.5">
        <span>Dasar</span><span>:</span>
        <ol class="m-0 flex list-decimal flex-col gap-0.5 pl-4">
            <li>Program kerja {{ \App\Actions\Letters\LetterContent::SCHOOL }} tahun pelajaran {{ $content->schoolYear }}.</li>
            <li>{{ $content->guruBasis() }}</li>
        </ol>
    </div>

    <p class="relative m-0 text-center font-bold">MEMERINTAHKAN:</p>

    <div class="relative grid grid-cols-[60px_10px_1fr] gap-y-1">
        <span>Kepada</span><span>:</span><span>Nama/Pangkat/Gol/NIP/Jabatan : Terlampir</span>
        <span>Untuk</span><span>:</span>
        <ol class="m-0 flex list-decimal flex-col gap-0.5 pl-4">
            <li class="text-justify">{{ $content->guruTask() }}</li>
            <li>Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.</li>
            <li>Perintah ini dilaksanakan dengan penuh tanggung jawab.</li>
        </ol>
    </div>

    @include('livewire.tata-usaha.partials.ttd')

    <div class="relative my-2 flex items-center gap-2 font-sans text-[11px] font-semibold text-muted" aria-hidden="true">
        <span class="h-px flex-1 bg-line-dashed"></span>Halaman 2<span class="h-px flex-1 bg-line-dashed"></span>
    </div>

    <p class="relative m-0 font-bold">Lampiran</p>
    <p class="relative m-0">Surat Perintah Tugas Nomor @include('livewire.tata-usaha.partials.nomor')</p>
    <table class="relative w-full border-collapse text-[11.5px]">
        <thead>
            <tr>
                <th scope="col" class="w-7 border border-ink px-1 py-1">No</th>
                <th scope="col" class="border border-ink px-1 py-1">Nama</th>
                <th scope="col" class="border border-ink px-1 py-1">Pangkat/Gol</th>
                <th scope="col" class="border border-ink px-1 py-1">NIP</th>
                <th scope="col" class="border border-ink px-1 py-1">Jabatan</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="border border-ink px-1 py-1 text-center">1</td>
                <td class="border border-ink px-1 py-1">{{ $content->mentor['name'] ?? '-' }}</td>
                <td class="border border-ink px-1 py-1 text-center">{{ $content->mentor['rank'] ?? '-' }}</td>
                <td class="num border border-ink px-1 py-1 text-center">{{ $content->mentor['nip'] ?? '-' }}</td>
                <td class="border border-ink px-1 py-1 text-center">{{ $content->mentor['position'] ?? 'Guru' }}</td>
            </tr>
        </tbody>
    </table>
</div>
