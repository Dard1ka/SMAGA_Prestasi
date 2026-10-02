{{--
    Public header (DESIGN-SPEC-v2 §4): brand · Beranda / Prestasi / Peringkat (current = ink + 2px blue
    underline) · "Masuk" (or "Buka dasbor" when logged in). Sticky, 72px; on phones the links move to a
    row under the header.

    <x-sth.public-header current="prestasi" />

    Props: current beranda | prestasi | peringkat
--}}
@props(['current' => null])
@php
    $public = \App\Support\Navigation::publicLinks($current);
    $user = auth()->user();
    $dashboardUrl = $user ? \App\Support\Navigation::for($user)['homeUrl'] : null;
    $homeUrl = $public['links'][0]['disabled'] ? url('/') : $public['links'][0]['href'];
@endphp
<header {{ $attributes->class('pub-head') }}>
    <x-sth.brand size="sm" :href="$homeUrl" />
    <nav class="pub-nav" aria-label="Navigasi publik">
        @foreach ($public['links'] as $link)
            <a class="pub-link" @if ($link['disabled']) role="link" aria-disabled="true" @else href="{{ $link['href'] }}" @if ($navAttr = \App\Support\Links::navigateAttribute($link['href'])) {{ $navAttr }} @endif @endif @if ($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>
        @endforeach
    </nav>
    <div class="justify-self-end">
        @if ($user)
            <x-sth.button size="sm" :href="$dashboardUrl">Buka dasbor</x-sth.button>
        @else
            <x-sth.button size="sm" :href="$public['login']['href']" :disabled="$public['login']['disabled']">Masuk</x-sth.button>
        @endif
    </div>
</header>
<nav class="pub-nav-m md:hidden" aria-label="Navigasi publik">
    @foreach ($public['links'] as $link)
        <a class="pub-link" @if ($link['disabled']) role="link" aria-disabled="true" @else href="{{ $link['href'] }}" @if ($navAttr = \App\Support\Links::navigateAttribute($link['href'])) {{ $navAttr }} @endif @endif @if ($link['current']) aria-current="page" @endif>{{ $link['label'] }}</a>
    @endforeach
</nav>
