{{-- Letter number in the preview: the issued or typed number, or an orange blank while nothing is typed yet. --}}
@if ($number)<span class="num">{{ $number }}</span>@else<span class="rounded bg-orange-bg px-1 font-sans text-[10px] font-semibold text-orange">nomor surat</span>@endif
