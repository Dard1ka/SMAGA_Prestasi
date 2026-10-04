{{--
    HTML preview of the student letter: same rows and text as resources/pdf/surat-tugas-siswa.blade.php (the
    school's Excel sheet). Empty fields show a [placeholder] so TU sees what is still missing.
    Vars: $content (LetterContent) · $number (issued or typed number, or null) · $tpl / $letterTemplate (optional)
--}}
<div class="relative mx-auto flex w-full max-w-[560px] flex-col overflow-hidden border border-line bg-white px-5 py-8 font-[family-name:Arial,Helvetica,sans-serif] text-[10.5px] leading-[15px] text-ink shadow-card sm:min-h-[792px] sm:px-[37px] sm:py-[50px]" aria-label="Preview surat tugas siswa">
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center select-none" aria-hidden="true">
        <span class="-rotate-[30deg] font-sans text-[64px] font-bold tracking-[0.2em] text-line-soft">PREVIEW</span>
    </div>

    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
    @php([$dateStart, $dateEnd] = $content->dateRange())
    @php([$timeStart, $timeEnd] = $content->timeRange())
    @include('livewire.tata-usaha.partials.kop')

    <div class="relative mt-4 flex flex-col items-center text-[11.5px]">
        <p class="m-0 font-bold underline">S U R A T &nbsp; T U G A S</p>
        <p class="m-0">Nomor : @include('livewire.tata-usaha.partials.nomor')</p>
    </div>

    <div class="relative mt-[15px] grid grid-cols-[90px_9px_1fr]">
        <span>Dasar</span><span class="text-center">:</span><span>{{ $tpl->siswaBasis($content->basisFrom !== '' ? $content->basisFrom : '[surat dari]') }}</span>
        <span>Nomor</span><span class="text-center">:</span><span>{{ $content->basisNumber ?? '-' }}</span>
        <span>Tanggal</span><span class="text-center">:</span><span>{{ $content->basisDateText ?? '-' }}</span>
    </div>

    <p class="relative m-0 mt-[15px]">{{ $tpl->siswaIntro() }}</p>

    <table class="relative mt-[15px] w-full border-collapse">
        <thead>
            <tr>
                <th scope="col" class="w-7 border border-ink px-1 py-0.5 font-bold">NO</th>
                <th scope="col" class="border border-ink px-1 py-0.5 font-bold">NAMA MURID</th>
                <th scope="col" class="w-[70px] border border-ink px-1 py-0.5 font-bold">NIS</th>
                <th scope="col" class="w-[72px] border border-ink px-1 py-0.5 font-bold">KELAS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($content->students as $student)
                <tr>
                    <td class="border border-ink px-1 py-0.5 text-center">{{ $student['no'] }}</td>
                    <td class="border border-ink px-1 py-0.5">{{ $student['name'] }}</td>
                    <td class="num border border-ink px-1 py-0.5 text-center">{{ $student['nis'] }}</td>
                    <td class="border border-ink px-1 py-0.5 text-center">{{ $student['class'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="relative m-0 mt-[15px]">untuk mengikuti {{ $content->activity() }}, pada :</p>

    <div class="relative mt-[15px] ml-[23px] grid grid-cols-[66px_9px_1fr]">
        <span>Hari</span><span class="text-center">:</span><span>{{ $content->scheduleDay !== '' ? $content->scheduleDay : '[hari]' }}</span>
        <span>Tanggal</span><span class="text-center">:</span>
        <span>{{ $dateStart !== '' ? $dateStart : '[tanggal]' }}@if ($dateEnd !== null)<span class="mx-3">s.d</span>{{ $dateEnd }}@endif</span>
        <span>Pukul</span><span class="text-center">:</span>
        <span>{{ $timeStart !== '' ? $timeStart : '[pukul]' }}@if ($timeEnd !== null)<span class="mx-3">s.d</span>{{ $timeEnd }}@endif</span>
        <span>Tempat</span><span class="text-center">:</span><span>{{ $content->place !== '' ? $content->place : '[tempat]' }}</span>
    </div>

    <p class="relative m-0 mt-[15px]">{{ $tpl->siswaClosing() }}</p>

    <div class="relative mt-[30px]">
        @include('livewire.tata-usaha.partials.ttd', ['ttd' => 'siswa'])
    </div>
</div>
