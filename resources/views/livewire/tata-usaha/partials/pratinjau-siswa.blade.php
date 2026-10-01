{{--
    HTML preview of the student letter (same text as resources/pdf/surat-tugas-siswa.blade.php).
    Vars: $content (LetterContent) · $number (issued number or null) · $parts [prefix, suffix] · $seq (typed digits)
--}}
<div class="relative mx-auto flex w-full max-w-[560px] flex-col gap-3 overflow-hidden border border-line bg-white p-6 font-serif text-[12.5px] leading-snug text-ink shadow-card sm:min-h-[792px] sm:p-9" aria-label="Pratinjau surat tugas siswa">
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center select-none" aria-hidden="true">
        <span class="-rotate-[30deg] text-[64px] font-bold tracking-[0.2em] text-line-soft">PRATINJAU</span>
    </div>

    @include('livewire.tata-usaha.partials.kop')

    <div class="relative flex flex-col items-center gap-0.5">
        <p class="m-0 text-[14px] font-bold tracking-[0.3em] underline">SURAT TUGAS</p>
        <p class="m-0">Nomor : @include('livewire.tata-usaha.partials.nomor')</p>
    </div>

    <div class="relative grid grid-cols-[88px_10px_1fr] gap-y-0.5">
        <span>Dasar</span><span>:</span><span>Surat dari {{ $content->organizer }}</span>
        <span>Nomor</span><span>:</span><span>{{ $content->basisNumber ?? '[nomor surat undangan]' }}</span>
        <span>Tanggal</span><span>:</span><span>{{ $content->basisDateText ?? '[tanggal surat undangan]' }}</span>
    </div>

    <p class="relative m-0">Kepala {{ \App\Actions\Letters\LetterContent::SCHOOL }} memberikan tugas kepada :</p>

    <table class="relative w-full border-collapse text-[12px]">
        <thead>
            <tr>
                <th scope="col" class="w-8 border border-ink px-1.5 py-1">NO</th>
                <th scope="col" class="border border-ink px-1.5 py-1">NAMA MURID</th>
                <th scope="col" class="w-24 border border-ink px-1.5 py-1">NIS</th>
                <th scope="col" class="w-16 border border-ink px-1.5 py-1">KELAS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($content->students as $student)
                <tr>
                    <td class="border border-ink px-1.5 py-1 text-center">{{ $student['no'] }}</td>
                    <td class="border border-ink px-1.5 py-1">{{ $student['name'] }}</td>
                    <td class="num border border-ink px-1.5 py-1 text-center">{{ $student['nis'] }}</td>
                    <td class="border border-ink px-1.5 py-1 text-center">{{ $student['class'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="relative m-0">untuk mengikuti Lomba {{ $content->competitionName }}, pada :</p>

    <div class="relative grid grid-cols-[88px_10px_1fr] gap-y-0.5">
        <span>Hari / Tanggal</span><span>:</span><span>{{ $content->scheduleDay !== '' ? $content->dayAndDate() : '[hari / tanggal]' }}</span>
        <span>Pukul</span><span>:</span><span>{{ $content->scheduleTime !== '' ? $content->scheduleTime : '[pukul]' }}</span>
        <span>Tempat</span><span>:</span><span>{{ $content->place !== '' ? $content->place : '[tempat]' }}</span>
    </div>

    <p class="relative m-0 text-justify">Demikian surat tugas ini dibuat dan diberikan kepada yang bersangkutan untuk dapat dilaksanakan sebagaimana mestinya dengan penuh tanggung jawab.</p>

    @include('livewire.tata-usaha.partials.ttd')
</div>
