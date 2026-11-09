{{--
    SV8, D-194: letterhead of the guru letter in the HTML preview, mirroring resources/pdf/partials/kop-guru.blade.php.
    From sm up it is the PDF's kop at the sheet's scale (0.94 px per pt): the emblems at their own places (the Pemprov
    emblem of the school's PDF, `tu.surat.kop` logo `pemprov-guru`), the lines centred in a box wider than the gap
    between them and drawn over the emblems, the web address host blue and underlined (LetterTemplate::kopLineParts()),
    the rule from the left emblem's edge to the right one's (SV9, D-200: 522.25 pt = 490.9 px). On a phone the emblems
    stand beside the lines.
    Vars: $tpl / $letterTemplate (optional)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<div class="relative flex items-start gap-1 sm:block sm:h-[85.7px]">
    <img src="{{ route('tu.surat.kop', ['logo' => 'pemprov-guru']) }}" alt="" width="77" height="80" class="block h-[52px] w-auto shrink-0 sm:absolute sm:top-0 sm:left-0 sm:h-[80px] sm:w-[76.8px]">
    <div class="relative flex min-w-0 flex-1 flex-col items-center pt-1 text-center leading-tight sm:ml-[58.6px] sm:w-[385.4px] sm:pt-[5.4px]">
        @if ($tpl->get('kop_1') !== '')<span class="text-[9px] sm:text-[11.28px] sm:leading-[13.2px]">{{ $tpl->get('kop_1') }}</span>@endif
        @if ($tpl->get('kop_2') !== '')<span class="text-[10px] font-bold sm:text-[13.16px] sm:leading-[15.2px]">{{ $tpl->get('kop_2') }}</span>@endif
        @if ($tpl->get('kop_3') !== '')<span class="text-[10px] font-bold sm:text-[13.16px] sm:leading-[15.2px]">{{ $tpl->get('kop_3') }}</span>@endif
        @foreach ($tpl->kopDetailLines() as $line)
            {{-- one line on purpose: whitespace between the pieces would show as a space --}}
            <span class="text-[6.5px] sm:text-[8.46px] sm:leading-[9.8px] sm:whitespace-nowrap">@foreach (\App\Actions\Templates\LetterTemplate::kopLineParts($line) as $part)<span @class(['text-[#00f] underline' => $part['link']])>{{ $part['text'] }}</span>@endforeach</span>
        @endforeach
    </div>
    <img src="{{ route('tu.surat.kop', ['logo' => 'sekolah']) }}" alt="" width="60" height="80" class="block h-[52px] w-auto shrink-0 sm:absolute sm:top-[2.4px] sm:left-[430.9px] sm:h-[80px] sm:w-[60px]">
</div>
<div class="relative mt-1.5 flex flex-col gap-px sm:mt-0 sm:ml-0 sm:w-[490.9px] sm:gap-[0.85px]" aria-hidden="true">
    <span class="h-[2.5px] bg-ink"></span>
    <span class="h-px bg-ink sm:h-[0.85px]"></span>
</div>
