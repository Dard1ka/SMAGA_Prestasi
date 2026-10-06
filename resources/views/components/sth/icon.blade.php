{{--
    Inline SVG icon from DESIGN-SPEC-v2 §6 (24px grid, stroke 1.8, currentColor).

    <x-sth.icon name="home" />                          decorative → aria-hidden="true"
    <x-sth.icon name="bell" label="Notifikasi" />       meaningful → role="img" aria-label
    <x-sth.icon name="riset" :size="56" class="opacity-70" />

    Props
    - name   spec set: home search clipboard award bars user users help trophy inbox shield check-circle
             chart-pie database mail folder file megaphone calendar pin upload download archive sliders recycle
             bell plus check x chevron-right chevron-left chevron-down clock info link image eye edit trash
             bidang: sains (flask) · riset (bulb) · seni / seni_budaya (palette) · olahraga (ball)
             extra: eye-off menu logout arrow-right arrow-left alert external send lock filter dots
    - size   px, default 20 (desktop) — use 22 on mobile tab bar
    - label  accessible name; omit for decorative icons (the usual case, text sits next to it)
--}}
@props(['name', 'size' => 20, 'label' => null])
@php
    $aliases = [
        'flask' => 'sains',
        'bulb' => 'riset',
        'palette' => 'seni',
        'seni_budaya' => 'seni',
        'ball' => 'olahraga',
        'shield-check' => 'shield',
        'alert-circle' => 'alert',
    ];
    $icon = $aliases[$name] ?? $name;
@endphp
<svg {{ $attributes->merge(['class' => 'shrink-0']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    @switch($icon)
        @case('home')
            <path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
            @break
        @case('search')
            <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
            @break
        @case('clipboard')
            <rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"></path>
            @break
        @case('award')
            <circle cx="12" cy="9" r="6"></circle><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7"></path>
            @break
        @case('bars')
            <path d="M6 20V11M12 20V5M18 20v-6M3 20h18"></path>
            @break
        @case('user')
            <circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>
            @break
        @case('users')
            <circle cx="9" cy="8" r="3.5"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"></path>
            @break
        @case('help')
            <circle cx="12" cy="12" r="9"></circle><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.7M12 17h.01"></path>
            @break
        @case('trophy')
            <path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"></path><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"></path>
            @break
        @case('inbox')
            <path d="M3 13h5l1.5 3h5L16 13h5"></path><path d="M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z"></path>
            @break
        @case('shield')
            <path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z"></path><path d="m8.5 12 2.5 2.5 4.5-5"></path>
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9"></circle><path d="m8 12 3 3 5-6"></path>
            @break
        @case('chart-pie')
            <path d="M12 3v9h9"></path><path d="M20.5 15A9 9 0 1 1 9 3.5"></path>
            @break
        @case('database')
            <ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"></path>
            @break
        @case('mail')
            <rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m3 7 9 6 9-6"></path>
            @break
        @case('folder')
            <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            @break
        @case('file')
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path>
            @break
        @case('megaphone')
            <path d="M3 10v4a1 1 0 0 0 1 1h3l6 4V5L7 9H4a1 1 0 0 0-1 1z"></path><path d="M17 8a5 5 0 0 1 0 8"></path>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path>
            @break
        @case('pin')
            <path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"></path><circle cx="12" cy="10" r="2.5"></circle>
            @break
        @case('upload')
            <path d="M12 16V4M7 9l5-5 5 5M4 20h16"></path>
            @break
        @case('download')
            <path d="M12 4v12M7 11l5 5 5-5M4 20h16"></path>
            @break
        @case('archive')
            <rect x="3" y="4" width="18" height="5" rx="1"></rect><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"></path>
            @break
        @case('sliders')
            <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"></path><circle cx="16" cy="6" r="2"></circle><circle cx="10" cy="12" r="2"></circle><circle cx="18" cy="18" r="2"></circle>
            @break
        @case('bell')
            <path d="M6 16v-5a6 6 0 1 1 12 0v5l1.5 2h-15z"></path><path d="M10 20a2 2 0 0 0 4 0"></path>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"></path>
            @break
        @case('check')
            <path d="m5 12 5 5 9-10"></path>
            @break
        @case('x')
            <path d="M6 6l12 12M18 6 6 18"></path>
            @break
        @case('chevron-right')
            <path d="m9 6 6 6-6 6"></path>
            @break
        @case('chevron-left')
            <path d="m15 6-6 6 6 6"></path>
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6"></path>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>
            @break
        @case('info')
            <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 8h.01"></path>
            @break
        @case('link')
            <path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"></path><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path>
            @break
        @case('image')
            <rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="m21 17-5-5-9 8"></path>
            @break
        @case('eye')
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"></circle>
            @break
        @case('edit')
            <path d="M4 20h4L19 9l-4-4L4 16z"></path><path d="m13.5 6.5 4 4"></path>
            @break
        @case('trash')
            <path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"></path>
            @break
        @case('sains')
            <path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"></path><path d="M7.5 15h9"></path>
            @break
        @case('riset')
            <path d="M9 18h6M10 21h4"></path><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.4 1 1.1 1 1.8V16h5v-.3c0-.7.4-1.4 1-1.8A6 6 0 0 0 12 3z"></path>
            @break
        @case('seni')
            <path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.5-.8 1.5-1.6 0-1.3-1.2-1.6-1.2-2.9 0-.9.7-1.5 1.7-1.5H17a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"></path><circle cx="7.5" cy="11" r="1"></circle><circle cx="10" cy="7" r="1"></circle><circle cx="15" cy="7.5" r="1"></circle>
            @break
        @case('olahraga')
            <circle cx="12" cy="12" r="9"></circle><path d="M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9M12 3C9.5 5.5 8.5 8.5 8.5 12s1 6.5 3.5 9M3.5 9h17M3.5 15h17"></path>
            @break
        @case('eye-off')
            <path d="M3 3l18 18"></path><path d="M10.6 5.1A9.9 9.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6A17.4 17.4 0 0 0 2 12s3.5 7 10 7c1.9 0 3.6-.6 5.1-1.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"></path>
            @break
        @case('logout')
            <path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"></path><path d="m15 16 4-4-4-4M19 12H9"></path>
            @break
        @case('arrow-right')
            <path d="M5 12h14M13 6l6 6-6 6"></path>
            @break
        @case('arrow-left')
            <path d="M19 12H5M11 6l-6 6 6 6"></path>
            @break
        @case('alert')
            <circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5.5M12 16.5h.01"></path>
            @break
        @case('external')
            <path d="M14 4h6v6M20 4l-9 9"></path><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"></path>
            @break
        @case('send')
            <path d="M21 3 10 14"></path><path d="m21 3-6.5 18-4.5-7-7-4.5z"></path>
            @break
        @case('lock')
            <rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
            @break
        @case('filter')
            <path d="M4 5h16l-6 7.5V19l-4 2v-8.5z"></path>
            @break
        @case('dots')
            <path d="M5 12h.01M12 12h.01M19 12h.01"></path>
            @break
        @case('recycle')
            <path d="M7 19H4.8a1.8 1.8 0 0 1-1.6-2.7l4-6.8"></path><path d="M11 19h8.2a1.8 1.8 0 0 0 1.6-2.7l-1.3-2.1"></path><path d="m14 16-3 3 3 3"></path><path d="M8.3 13.6 7.2 9.5l-4.1 1.1"></path><path d="m9.3 5.8 1.1-1.9a1.8 1.8 0 0 1 3.1 0l4 6.8"></path><path d="m13.4 9.6 4.1 1.1 1.1-4.1"></path>
            @break
        @default
            @if (config('app.debug'))
                @php(throw new InvalidArgumentException("Unknown <x-sth.icon> name [{$name}]."))
            @endif
    @endswitch
</svg>
