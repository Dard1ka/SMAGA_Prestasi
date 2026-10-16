{{--
    Letterhead of the HTML preview (mirrors resources/pdf/partials/kop.blade.php): Pemprov emblem, the lines of the
    TU letter template (plain text, escaped), school emblem, thick + thin rule. The emblems come from the
    `tu.surat.kop` route (same files the PDF uses). Sizes are scaled to the preview sheet (max 560 px wide).
    $kop = 'siswa' (D-154): from sm up the student kop is the PDF's at the sheet's scale (560 px for 595.28 pt =
    0.94 px per pt): emblems 75 pt, lines 11 / 13 / 8.2 pt. Without it (guru letter) the older, smaller kop.
    Vars: $kop ('siswa' | 'guru', optional) · $tpl / $letterTemplate (optional)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
@php($siswa = ($kop ?? 'guru') === 'siswa')
<div class="relative flex items-start gap-1 {{ $siswa ? 'sm:gap-0' : '' }}">
    <span class="shrink-0 {{ $siswa ? 'sm:w-[66px] sm:pt-[3px]' : '' }}">
        <img src="{{ route('tu.surat.kop', ['logo' => 'pemprov']) }}" alt="" width="56" height="60" class="block h-[52px] w-auto {{ $siswa ? 'sm:h-[70.5px]' : 'sm:h-[60px]' }}">
    </span>
    <div class="flex min-w-0 flex-1 flex-col items-center pt-1 text-center leading-tight {{ $siswa ? 'sm:pt-[10px]' : '' }}">
        @if ($tpl->get('kop_1') !== '')<span class="text-[9px] {{ $siswa ? 'sm:text-[10.4px] sm:leading-[11.9px]' : 'sm:text-[10px]' }}">{{ $tpl->get('kop_1') }}</span>@endif
        @if ($tpl->get('kop_2') !== '')<span class="text-[10px] font-bold {{ $siswa ? 'sm:text-[12.2px] sm:leading-[14.1px]' : 'sm:text-[11.5px]' }}">{{ $tpl->get('kop_2') }}</span>@endif
        @if ($tpl->get('kop_3') !== '')<span class="text-[10px] font-bold {{ $siswa ? 'sm:text-[12.2px] sm:leading-[14.1px]' : 'sm:text-[11.5px]' }}">{{ $tpl->get('kop_3') }}</span>@endif
        @foreach ($tpl->kopDetailLines() as $line)<span class="text-[6.5px] {{ $siswa ? 'sm:text-[7.7px] sm:leading-[8.85px]' : 'sm:text-[7px]' }}">{{ $line }}</span>@endforeach
    </div>
    <span class="flex shrink-0 justify-end {{ $siswa ? 'sm:w-[53px] sm:pt-[3px]' : '' }}">
        <img src="{{ route('tu.surat.kop', ['logo' => 'sekolah']) }}" alt="" width="45" height="60" class="block h-[52px] w-auto {{ $siswa ? 'sm:h-[70.5px]' : 'sm:h-[60px]' }}">
    </span>
</div>
<div class="relative flex flex-col gap-px {{ $siswa ? 'mt-1.5 sm:mt-[7.5px]' : 'mt-1.5' }}" aria-hidden="true">
    <span class="h-[2.5px] bg-ink"></span>
    <span class="h-px bg-ink"></span>
</div>
