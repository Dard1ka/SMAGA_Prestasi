{{-- Letter number in the preview: the issued number, or prefix + typed sequence (orange blank when empty) + suffix. --}}
@if ($number)
    <span class="num">{{ $number }}</span>
@else
    <span class="num">{{ $parts[0] }}@if ($seq !== ''){{ $seq }}@else<span class="rounded bg-orange-bg px-1 font-sans text-[11px] font-semibold text-orange">nomor</span>@endif{{ $parts[1] }}</span>
@endif
