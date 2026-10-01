{{--
    Initials avatar (first + last name word, honorifics/degrees skipped: "Ibu Dwi Rahayu" → DR).
    A photo is shown only when the caller passes `src` — callers must check photo_consent first
    (DESIGN-SPEC-v2 §1.12); otherwise initials.

    <x-sth.avatar name="Nadia Putri Anindya" />                 decorative (name is next to it)
    <x-sth.avatar name="Nadia Putri Anindya" size="lg" labelled /> role="img" aria-label (stand-alone)

    Props: name · size xs (26) | sm (32) | md (38, default) | lg (40) | xl (48) · src (consented photo URL)
           labelled (announce the name; default decorative)
--}}
@props(['name' => '', 'size' => 'md', 'src' => null, 'labelled' => false])
@php
    $sizes = [
        'xs' => 'size-[26px] text-[10px]',
        'sm' => 'size-8 text-xs',
        'md' => 'size-[38px] text-sm',
        'lg' => 'size-10 text-sm',
        'xl' => 'size-12 text-base',
    ];
    $initials = \App\Support\Navigation::initials($name);
@endphp
<span {{ $attributes->class(['avatar', $sizes[$size] ?? $sizes['md']]) }} @if ($labelled) role="img" aria-label="{{ $name }}" @else aria-hidden="true" @endif>
    @if ($src)
        <img src="{{ $src }}" alt="" loading="lazy" decoding="async">
    @else
        {{ $initials }}
    @endif
</span>
