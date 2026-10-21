{{--
    Initials avatar (first + last name word, honorifics/degrees skipped: "Ibu Dwi Rahayu" → DR).
    A photo is shown only when the caller passes `src`; otherwise initials. Public pages never pass one
    (no student photo there; the consent flag is gone since D-162).

    <x-sth.avatar name="Nadia Putri Anindya" />                 decorative (name is next to it)
    <x-sth.avatar name="Nadia Putri Anindya" size="lg" labelled /> role="img" aria-label (stand-alone)

    Props: name · size xs (28) | sm (32) | md (38, default) | lg (40) | xl (48) · src (photo URL)
           (initials are 13px even in the smallest avatar: nothing on screen is smaller, DESIGN-SPEC-v3 §2)
           labelled (announce the name; default decorative)
--}}
@props(['name' => '', 'size' => 'md', 'src' => null, 'labelled' => false])
@php
    $sizes = [
        'xs' => 'size-7 text-xs tracking-tight',
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
