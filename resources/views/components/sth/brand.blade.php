{{--
    Brand mark + wordmark. The mark is the crest of SMA Negeri 3 Semarang (D-120, the lead's choice of
    7 Oct 2026; until then a shield icon stood in for it). It is the one colourful thing in the frame.

    <x-sth.brand />                          sidebar, 40px high
    <x-sth.brand size="sm" href="/" />        public header, 36px high, links home
    <x-sth.brand inverse />                   on the ink auth panel: the crest on a white tile, white wordmark
    <x-sth.brand :wordmark="false" />         mark only

    Props: size md|sm · inverse · href (whole brand becomes one link named "SMAGA Talent Hub, beranda")
           wordmark (default true)
    The picture is the CSS class .brand-mark (resources/images/brand/lambang-sma3.png, hashed by Vite): no
    <img>, so no asset address is built in Blade.
--}}
@props(['size' => 'md', 'inverse' => false, 'href' => null, 'wordmark' => true, 'navigate' => null])
@php
    $navAttr = $navigate === false || $attributes->whereStartsWith('wire:navigate')->isNotEmpty()
        ? null
        : \App\Support\Links::navigateAttribute($href);
    $small = $size === 'sm';
    $tag = $href !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href !== null) href="{{ $href }}" @if ($navAttr) {{ $navAttr }} @endif aria-label="SMAGA Talent Hub, beranda" @endif {{ $attributes->class(['flex min-w-0 items-center gap-3 no-underline', 'rounded-control' => $href !== null]) }}>
    @if ($inverse)
        {{-- On ink the blue of the crest would sink into the navy: it stands on a white tile. --}}
        <span @if ($href === null) role="img" aria-label="Lambang SMA Negeri 3 Semarang" @else aria-hidden="true" @endif
              @class(['inline-flex shrink-0 items-center justify-center rounded-control bg-white', 'size-9' => $small, 'size-10' => ! $small])>
            <span @class(['brand-mark', 'h-7' => $small, 'h-8' => ! $small])></span>
        </span>
    @else
        <span @if ($href === null) role="img" aria-label="Lambang SMA Negeri 3 Semarang" @else aria-hidden="true" @endif
              @class(['brand-mark', 'h-9' => $small, 'h-10' => ! $small])></span>
    @endif
    @if ($wordmark)
        <span class="flex min-w-0 flex-col gap-0.5" @if ($href !== null) aria-hidden="true" @endif>
            <span @class(['display text-lg leading-[1.15] font-bold', 'text-ink' => ! $inverse, 'text-white' => $inverse])>SMAGA Talent Hub</span>
            <span @class(['text-xs leading-[1.3]', 'muted' => ! $inverse, 'text-primary-tint' => $inverse])>SMA Negeri 3 Semarang</span>
        </span>
    @endif
</{{ $tag }}>
