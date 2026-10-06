{{--
    HTML preview of the Surat Perintah Tugas + Lampiran: same blocks and text as
    resources/pdf/surat-perintah-tugas-guru.blade.php (the school's Word file). "Kepada" is the stacked form:
    1. Nama / Pangkat/Gol / NIP / Jabatan, each with its colon, a brace and "Terlampir".
    Vars: $content (LetterContent) · $number (issued or typed number, or null) · $tpl / $letterTemplate (optional)
--}}
<div class="relative mx-auto flex w-full max-w-[560px] flex-col overflow-hidden border border-line bg-white px-5 py-6 font-[family-name:Arial,Helvetica,sans-serif] text-[11px] leading-[14px] text-ink shadow-card sm:min-h-[792px] sm:py-[18px] sm:pr-[40px] sm:pl-[52px]" aria-label="Preview surat perintah tugas guru">
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center select-none" aria-hidden="true">
        <span class="-rotate-[30deg] font-sans text-[64px] font-bold tracking-[0.2em] text-line-soft">PREVIEW</span>
    </div>

    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
    @php($basisItems = array_merge($tpl->guruBasisItems($content->schoolYear), [$content->guruBasis()]))
    @php($attachmentTitle = $content->attachmentTitle())
    @include('livewire.tata-usaha.partials.kop')

    <div class="relative mt-10 flex flex-col items-center">
        <p class="m-0">SURAT PERINTAH TUGAS</p>
        <p class="m-0">Nomor @include('livewire.tata-usaha.partials.nomor')</p>
    </div>

    <div class="relative mt-[14px] grid grid-cols-[52px_14px_1fr] text-[10px] leading-[13px]">
        <span>Dasar</span><span class="text-center">:</span>
        <ol class="m-0 flex list-decimal flex-col pl-4">
            @foreach ($basisItems as $item)
                <li class="text-justify">{{ $item }}</li>
            @endforeach
        </ol>
    </div>

    <p class="relative m-0 mt-3 mb-1.5 text-center">MEMERINTAHKAN:</p>

    <div class="relative grid grid-cols-[52px_14px_16px_70px_10px_22px_1fr]">
        <span>Kepada</span><span class="text-center">:</span>
        <span class="text-[10px]">1.</span><span class="text-[10px]">Nama</span><span>:</span>
        <span class="row-span-4 pt-0.5 text-ink" aria-hidden="true">
            <svg viewBox="0 0 15.75 50" class="h-[50px] w-[15px]" fill="none" stroke="currentColor" stroke-width="0.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <path d="M0.5 0.5 C4.8 0.5 7.4 2.2 7.4 6 L7.4 20 C7.4 23.4 10 25 15.25 25 C10 25 7.4 26.6 7.4 30 L7.4 44 C7.4 47.8 4.8 49.5 0.5 49.5"></path>
            </svg>
        </span>
        <span></span>
        <span></span><span></span><span></span><span class="text-[10px]">Pangkat/Gol</span><span>:</span><span>Terlampir</span>
        <span></span><span></span><span></span><span class="text-[10px]">NIP</span><span>:</span><span></span>
        <span></span><span></span><span></span><span class="text-[10px]">Jabatan</span><span>:</span><span></span>
    </div>

    <div class="relative mt-1.5 grid grid-cols-[52px_14px_1fr]">
        <span>Untuk</span><span class="text-center">:</span>
        <ol class="m-0 flex list-decimal flex-col pl-4">
            <li class="text-justify">{{ $content->guruTask() }}</li>
            <li>{{ $tpl->guruReport() }}</li>
            <li>{{ $tpl->guruClosing() }}</li>
        </ol>
    </div>

    <div class="relative mt-[14px]">
        @include('livewire.tata-usaha.partials.ttd', ['ttd' => 'guru'])
    </div>

    <div class="relative my-4 flex items-center gap-2 font-sans text-[11px] font-semibold text-muted" aria-hidden="true">
        <span class="h-px flex-1 bg-line-dashed"></span>Halaman 2<span class="h-px flex-1 bg-line-dashed"></span>
    </div>

    <p class="relative m-0">Lampiran Surat Tugas Kepala {{ $tpl->school() }}</p>
    <div class="relative grid grid-cols-[66px_1fr]">
        <span>Nomor</span><span>: @include('livewire.tata-usaha.partials.nomor')</span>
        <span>Tanggal</span><span>: {{ $content->issuedOnText }}</span>
    </div>

    <p class="relative m-0 mt-6 text-center">{{ $attachmentTitle[0] }}</p>
    <p class="relative m-0 text-center">{{ $attachmentTitle[1] }}</p>

    <div class="relative mt-[14px] ml-5 grid grid-cols-[40px_96px_1fr]">
        <span>1</span><span>Nama</span><span>: {{ $content->mentor['name'] ?? '-' }}</span>
        <span></span><span>Pangkat/Gol</span><span>: {{ $content->mentor['rank'] ?? '-' }}</span>
        <span></span><span>NIP</span><span class="num">: {{ $content->mentor['nip'] ?? '-' }}</span>
        <span></span><span>Jabatan</span><span>: {{ $content->mentor['position'] ?? 'Guru' }}</span>
    </div>

    <div class="relative mt-8">
        @include('livewire.tata-usaha.partials.ttd', ['ttd' => 'guru', 'ttdLines' => ['Kepala '.$tpl->school()]])
    </div>
</div>
