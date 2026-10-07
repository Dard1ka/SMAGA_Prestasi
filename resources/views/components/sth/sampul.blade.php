{{--
    Cover of a lomba (DESIGN-SPEC-v3 §5). Always filled: the lomba's own poster when there is one, else a
    template drawing of its bidang (3 per bidang, picked from the lomba id by App\Support\Sampul).
    Decorative: the bidang and the title are always written next to it, so the drawing has no alt text.

    <x-sth.sampul :bidang="$c->bidang" :seed="$c->id" :src="\App\Support\Sampul::poster($c)" class="h-32 p-3.5">
        <x-sth.chip tone="glass">Nasional</x-sth.chip>          tags over the cover go in the slot
    </x-sth.sampul>
    <x-sth.sampul bidang="riset" :seed="$id" tone="pekat" class="h-40" />   the one big cover of a detail page
    <x-sth.sampul bidang="seni" :seed="$id" class="h-14" />                 a band on a small card

    Props
    - bidang  sains | riset | seni_budaya (or seni) | olahraga | a Bidang enum
    - seed    the lomba id (ULID); the same seed always gives the same drawing
    - src     address of the poster (App\Support\Sampul::poster(), route files.cover) or null
    - tone    terang (default, cards) | pekat (deep colours; a detail page's single big cover)
    - alt     alt text of the poster, e.g. "Poster NSIF 2026" (default: empty, the title is next to it)
    Size and padding come from the caller's classes (h-24 sm:h-32 p-3.5 …). No inline style: the poster is
    two <img> (blurred backdrop + the whole poster); app.js removes them when the file cannot be loaded.
--}}
@props(['bidang' => null, 'seed' => null, 'src' => null, 'tone' => 'terang', 'alt' => ''])
@php
    $src = is_string($src) && $src !== '' ? $src : null;
@endphp
<div {{ $attributes->class([
    'sampul',
    \App\Support\Sampul::templateClass($bidang, $seed),
    'sampul-pekat' => $tone === 'pekat',
    'sampul-poster' => $src !== null,
]) }}>
    @if ($src !== null)
        <img class="sampul-latar" src="{{ $src }}" alt="" aria-hidden="true" loading="lazy" decoding="async" data-sth-sampul>
        <img class="sampul-gambar" src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async" data-sth-sampul>
    @endif
    {{ $slot }}
</div>
