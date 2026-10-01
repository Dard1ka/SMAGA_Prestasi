{{--
    Overlapping avatars (.stack), e.g. "3 siswa SMAGA mendaftar" on a lomba card. Decorative: always put
    the count/names in text next to it.

    <x-sth.avatar-stack :names="['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama']" />
    <x-sth.avatar-stack :names="$names" :max="3" size="sm" />   → 3 avatars + "+9"

    Props: names (list of full names) · max (default 3) · size xs (default) | sm | md
--}}
@props(['names' => [], 'max' => 3, 'size' => 'xs'])
@php
    $names = array_values(is_array($names) ? $names : collect($names)->all());
    $shown = array_slice($names, 0, max(1, (int) $max));
    $rest = count($names) - count($shown);
@endphp
@if ($names !== [])
    <span {{ $attributes->class('stack') }} aria-hidden="true">
        @foreach ($shown as $person)
            <x-sth.avatar :name="$person" :size="$size" />
        @endforeach
        @if ($rest > 0)
            <span @class(['avatar num bg-gray-bg text-gray', 'size-[26px] text-[10px]' => $size === 'xs', 'size-8 text-xs' => $size === 'sm', 'size-[38px] text-sm' => $size === 'md'])>+{{ $rest }}</span>
        @endif
    </span>
@endif
