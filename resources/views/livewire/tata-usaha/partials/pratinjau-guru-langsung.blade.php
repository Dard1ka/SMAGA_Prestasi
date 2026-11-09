{{--
    SV8, D-194: HTML preview of the guru letter (LetterTemplate::GURU_LAYOUT_LANGSUNG), the same blocks and text as
    resources/pdf/partials/guru-langsung.blade.php (the school's PDF of 2026-11-09): one page, no Lampiran, the guru
    right under "Kepada", the kegiatan in bold. From sm up the sheet is the PDF page at 0.94 px per pt (560 px for
    595.28 pt; margins 23.8 / 55.2 pt = 22.4 / 51.9 px) and every block stands at the PDF's offset; a narrower box shrinks
    the whole sheet (the paper classes of the student preview, `.kertas-siswa` / `.lembar-siswa` in resources/css/app.css,
    which are not student-specific). The browser justifies the texts itself, so a line may break elsewhere than on the
    PDF (JustifiedLines sets those). On a phone the blocks flow in a narrow sheet. Empty fields show a [placeholder].
    Vars: $content (LetterContent) · $number (issued or typed number, or null) · $tpl (LetterTemplate)
--}}
<div class="kertas-siswa">
<div class="lembar-siswa relative mx-auto flex w-full max-w-[560px] flex-col overflow-hidden border border-line bg-white px-5 py-6 font-[family-name:Arial,Helvetica,sans-serif] text-[11px] leading-[14px] text-ink shadow-card sm:block sm:min-h-[792px] sm:pt-[22.4px] sm:pr-[16.8px] sm:pb-[14.4px] sm:pl-[51.9px] sm:text-[11.28px] [overflow-wrap:anywhere]" aria-label="Preview surat perintah tugas guru">
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center select-none" aria-hidden="true">
        <span class="-rotate-[30deg] font-sans text-[64px] font-bold tracking-[0.2em] text-line-soft">PREVIEW</span>
    </div>

    @php($basisItems = array_merge($tpl->guruBasisItems($content->schoolYear), [$content->guruBasis()]))
    @php([$taskBefore, $taskKegiatan, $taskRest] = $content->guruTaskParts())
    @include('livewire.tata-usaha.partials.kop-guru')

    <p class="relative m-0 mt-4 text-center sm:mt-[6px] sm:ml-[33.1px] sm:w-[415px] sm:leading-[13px]">SURAT PERINTAH TUGAS</p>
    <p class="relative m-0 text-center sm:ml-[33.1px] sm:w-[410px] sm:leading-[13px]">Nomor @include('livewire.tata-usaha.partials.nomor')</p>

    <div class="relative mt-3 grid grid-cols-[44px_10px_16px_1fr] sm:mt-[13.3px] sm:ml-[33.1px] sm:grid-cols-[54px_14.3px_16.9px_345.4px]">
        @foreach ($basisItems as $item)
            <span>{{ $loop->first ? 'Dasar' : '' }}</span><span>{{ $loop->first ? ':' : '' }}</span><span>{{ $loop->iteration }}.</span><span class="text-justify">{{ $item }}</span>
        @endforeach
    </div>

    <p class="relative m-0 mt-3 text-center sm:mt-[12.8px] sm:ml-[33.1px] sm:w-[444.3px]">MEMERINTAHKAN:</p>

    <div class="relative mt-1.5 grid grid-cols-[50px_10px_14px_70px_8px_1fr] leading-[13px] sm:mt-[6.6px] sm:ml-[28px] sm:grid-cols-[74.8px_13.3px_13.2px_73.3px_6.4px_254.5px]">
        <span>Kepada</span><span>:</span><span>1.</span><span>Nama</span><span>:</span><span>{{ $content->mentor['name'] ?? '-' }}</span>
        <span></span><span></span><span></span><span>Pangkat/Gol</span><span>:</span><span>{{ $content->mentor['rank'] ?? '-' }}</span>
        <span></span><span></span><span></span><span>NIP</span><span>:</span><span class="num">{{ $content->mentor['nip'] ?? '-' }}</span>
        <span></span><span></span><span></span><span>Jabatan</span><span>:</span><span>{{ $content->mentor['position'] ?? 'Guru' }}</span>
    </div>

    <div class="relative mt-3 grid grid-cols-[44px_10px_16px_1fr] sm:mt-[12.2px] sm:ml-[32.9px] sm:grid-cols-[54px_14.3px_16.9px_345.6px]">
        <span>Untuk</span><span>:</span><span>1.</span><span class="text-justify">{{ $taskBefore }}<b>{{ $taskKegiatan }}</b>{{ $taskRest }}</span>
        <span></span><span></span><span>2.</span><span class="text-justify">{{ $tpl->guruReport() }}</span>
        <span></span><span></span><span>3.</span><span class="text-justify">{{ $tpl->guruClosing() }}</span>
    </div>

    <div class="relative mt-[14px]">
        @include('livewire.tata-usaha.partials.ttd', ['ttd' => 'guru'])
    </div>
</div>
</div>
