{{-- Letterhead of the HTML preview (mirrors resources/pdf/partials/kop.blade.php). --}}
<div class="relative flex items-center gap-3 border-b-4 border-double border-ink pb-2">
    <span class="flex size-12 shrink-0 items-center justify-center border border-dashed border-line-dashed text-center text-[8px] leading-tight text-muted">Logo Pemprov</span>
    <div class="flex flex-1 flex-col items-center text-center">
        {{-- B5: lines from the TU letter template (plain text, escaped), same as resources/pdf/partials/kop. --}}
        @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
        @if ($tpl->get('kop_1') !== '')<span class="text-[11.5px] font-bold">{{ $tpl->get('kop_1') }}</span>@endif
        @if ($tpl->get('kop_2') !== '')<span class="text-[11.5px] font-bold">{{ $tpl->get('kop_2') }}</span>@endif
        @if ($tpl->get('kop_3') !== '')<span class="text-[13px] font-bold">{{ $tpl->get('kop_3') }}</span>@endif
        @foreach ($tpl->kopDetailLines() as $line)<span class="text-[10px]">{{ $line }}</span>@endforeach
    </div>
    <span class="flex size-12 shrink-0 items-center justify-center border border-dashed border-line-dashed text-center text-[8px] leading-tight text-muted">Logo sekolah</span>
</div>
