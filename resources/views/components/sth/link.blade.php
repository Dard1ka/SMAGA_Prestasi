{{--
    Inline text link (.lnk, 14px/700, min-height 36px), e.g. "Lihat semua ›" next to a section title.

    <x-sth.link href="{{ route('siswa.lomba-saya.index') }}" icon-right="chevron-right">Lihat semua</x-sth.link>
    <x-sth.link :href="$competition->website_url" external>Website resmi</x-sth.link>

    Props: href · icon (leading) · icon-right (trailing) · external (user/third-party URL: new tab +
           rel="nofollow noopener noreferrer ugc", SECURITY §3f; show the host in the text next to it)
           navigate (default: auto — SPA page swap for in-app pages; :navigate="false" opts out)
--}}
@props(['href' => '#', 'icon' => null, 'iconRight' => null, 'external' => false, 'navigate' => null])
@php
    $navAttr = $navigate === false || $external || $attributes->whereStartsWith('wire:navigate')->isNotEmpty()
        ? null
        : \App\Support\Links::navigateAttribute($href);
@endphp
<a href="{{ $href }}" @if ($navAttr) {{ $navAttr }} @endif {{ $attributes->class('lnk') }} @if ($external) target="_blank" rel="nofollow noopener noreferrer ugc" @endif>
    @if ($icon)<x-sth.icon :name="$icon" :size="18" />@endif
    {{ $slot }}
    @if ($external)
        <x-sth.icon name="external" :size="16" /><span class="sr-only">(membuka tab baru)</span>
    @elseif ($iconRight)
        <x-sth.icon :name="$iconRight" :size="18" />
    @endif
</a>
