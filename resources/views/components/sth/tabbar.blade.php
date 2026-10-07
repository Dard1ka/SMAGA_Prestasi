{{--
    Siswa mobile tab bar (DESIGN-SPEC-v2 §4 Mobile): Beranda · Lomba · Lomba saya · Peringkat · Portofolio
    ("Lomba saya" and "Portofolio" are the sidebar's names; the mockup still shows "Lombaku" and "Profil").
    Fixed to the bottom below lg; the app layout adds bottom padding so content never hides behind it.

    <x-sth.tabbar />                          items from Navigation::tabbar() (current route)
    <x-sth.tabbar :items="$nav['tabbar']" />

    Props: items (key, label, icon, href, current, disabled)
--}}
@props(['items' => null])
@php
    $items ??= \App\Support\Navigation::tabbar();
@endphp
<nav aria-label="Menu utama" {{ $attributes->class('tabbar lg:hidden') }}>
    @foreach ($items as $item)
        <a
            @class(['tab', 'tab-on' => $item['current']])
            @if ($item['disabled']) role="link" aria-disabled="true" @else href="{{ $item['href'] }}" @if ($navAttr = \App\Support\Links::navigateAttribute($item['href'])) {{ $navAttr }} @endif @endif
            @if ($item['current']) aria-current="page" @endif
        >
            <span class="tab-ic"><x-sth.icon :name="$item['icon']" :size="22" /></span>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
