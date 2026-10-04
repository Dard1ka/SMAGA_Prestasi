{{--
    Letterhead of the HTML preview (mirrors resources/pdf/partials/kop.blade.php): Pemprov emblem, the lines of the
    TU letter template (plain text, escaped), school emblem, thick + thin rule. The emblems come from the
    `tu.surat.kop` route (same files the PDF uses). Sizes are scaled to the preview sheet (max 560 px wide).
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<div class="relative flex items-start gap-1">
    <img src="{{ route('tu.surat.kop', ['logo' => 'pemprov']) }}" alt="" width="56" height="60" class="h-[52px] w-auto shrink-0 sm:h-[60px]">
    <div class="flex min-w-0 flex-1 flex-col items-center pt-1 text-center leading-tight">
        @if ($tpl->get('kop_1') !== '')<span class="text-[9px] sm:text-[10px]">{{ $tpl->get('kop_1') }}</span>@endif
        @if ($tpl->get('kop_2') !== '')<span class="text-[10px] font-bold sm:text-[11.5px]">{{ $tpl->get('kop_2') }}</span>@endif
        @if ($tpl->get('kop_3') !== '')<span class="text-[10px] font-bold sm:text-[11.5px]">{{ $tpl->get('kop_3') }}</span>@endif
        @foreach ($tpl->kopDetailLines() as $line)<span class="text-[6.5px] sm:text-[7px]">{{ $line }}</span>@endforeach
    </div>
    <img src="{{ route('tu.surat.kop', ['logo' => 'sekolah']) }}" alt="" width="45" height="60" class="h-[52px] w-auto shrink-0 sm:h-[60px]">
</div>
<div class="relative mt-1.5 flex flex-col gap-px" aria-hidden="true">
    <span class="h-[2.5px] bg-ink"></span>
    <span class="h-px bg-ink"></span>
</div>
