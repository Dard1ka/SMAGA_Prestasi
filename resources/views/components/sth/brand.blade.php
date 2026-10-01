{{--
    Brand mark (logo placeholder — never the real school crest) + wordmark.

    <x-sth.brand />                          sidebar, 40px mark
    <x-sth.brand size="sm" href="/" />        public header, 36px mark, links home
    <x-sth.brand inverse />                   on the blue auth panel (white mark, blue icon)
    <x-sth.brand :wordmark="false" />         mark only

    Props: size md|sm · inverse · href (whole brand becomes one link named "SMAGA Talent Hub, beranda")
           wordmark (default true)
--}}
@props(['size' => 'md', 'inverse' => false, 'href' => null, 'wordmark' => true])
@php
    $mark = $size === 'sm' ? 'size-9' : 'size-10';
    $tag = $href !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href !== null) href="{{ $href }}" aria-label="SMAGA Talent Hub, beranda" @endif {{ $attributes->class(['flex min-w-0 items-center gap-3 no-underline', 'rounded-control' => $href !== null]) }}>
    <span @if ($href === null) role="img" aria-label="Logo SMAGA Talent Hub" @else aria-hidden="true" @endif @class([
        'inline-flex shrink-0 items-center justify-center rounded-control',
        $mark,
        'bg-primary text-white' => ! $inverse,
        'bg-white text-primary' => $inverse,
    ])>
        <x-sth.icon name="shield" :size="22" />
    </span>
    @if ($wordmark)
        <span class="flex min-w-0 flex-col gap-0.5" @if ($href !== null) aria-hidden="true" @endif>
            <span @class(['display text-[17px] leading-[1.15] font-extrabold', 'text-ink' => ! $inverse, 'text-white' => $inverse])>SMAGA Talent Hub</span>
            <span @class(['text-xs leading-[1.3]', 'muted' => ! $inverse, 'text-primary-tint' => $inverse])>SMA Negeri 3 Semarang</span>
        </span>
    @endif
</{{ $tag }}>
