{{--
    Cover of a lomba (DESIGN-SPEC-v3 §5). Always filled: the lomba's own poster when there is one, else a
    template drawing of its bidang (3 per bidang, picked from the lomba id by App\Support\Sampul).
    Decorative: the bidang and the title are always written next to it, so the drawing has no alt text.

    <x-sth.sampul :bidang="$c->bidang" :seed="$c->id" :src="\App\Support\Sampul::poster($c)" bentuk="tegak" class="w-[132px] rounded-tile" />
                                                                            the poster box of a lomba card (3:4)
    <x-sth.sampul bidang="riset" :seed="$id" :src="$poster" bentuk="utuh" tone="pekat" class="rounded-tile" />
                                                                            the one poster of a detail page, whole
    <x-sth.sampul bidang="seni" :seed="$id" class="h-14 p-3">              a band on a small card (prestasi, publikasi)
        <x-sth.chip tone="glass">Nasional</x-sth.chip>                      tags over a band go in the slot
    </x-sth.sampul>

    Props
    - bidang  sains | riset | seni_budaya (or seni) | olahraga | a Bidang enum
    - seed    the lomba id (ULID); the same seed always gives the same drawing
    - src     address of the poster (App\Support\Sampul::poster(), route files.cover) or null
    - bentuk  lebar (default): a wide band, height from the caller; a poster shows whole in the middle over a
              blurred copy of itself (two <img>)
              tegak: a portrait box, always 3:4, width from the caller; the poster fills it (one <img>, a little
              of its sides or bottom may be cut)
              utuh: a portrait box, width from the caller; the poster shows whole at its own proportions
              (3:4 until it has loaded, and for a template drawing)
    - tone    terang (default, cards) | pekat (deep colours; the drawing of a detail page)
    - alt     alt text of the poster, e.g. "Poster NSIF 2026" (default: empty, the title is next to it)
    Size and padding come from the caller's classes. No inline style; app.js removes the <img> when the file
    cannot be loaded, and the drawing shows instead.
--}}
@props(['bidang' => null, 'seed' => null, 'src' => null, 'tone' => 'terang', 'alt' => '', 'bentuk' => 'lebar'])
@php
    $src = is_string($src) && $src !== '' ? $src : null;
    $bentuk = in_array($bentuk, ['tegak', 'utuh'], true) ? $bentuk : 'lebar';
@endphp
<div {{ $attributes->class([
    'sampul',
    \App\Support\Sampul::templateClass($bidang, $seed),
    'sampul-pekat' => $tone === 'pekat',
    'sampul-tegak' => $bentuk === 'tegak',
    'sampul-utuh' => $bentuk === 'utuh',
    'sampul-poster' => $src !== null,
]) }}>
    @if ($src !== null && $bentuk === 'lebar')
        <img class="sampul-latar" src="{{ $src }}" alt="" aria-hidden="true" loading="lazy" decoding="async" data-sth-sampul>
        <img class="sampul-gambar" src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async" data-sth-sampul>
    @elseif ($src !== null)
        {{-- The big poster of a detail page is at the top of the screen: load it at once. --}}
        <img class="sampul-isi" src="{{ $src }}" alt="{{ $alt }}" loading="{{ $bentuk === 'utuh' ? 'eager' : 'lazy' }}" decoding="async" data-sth-sampul>
    @endif
    {{ $slot }}
</div>
