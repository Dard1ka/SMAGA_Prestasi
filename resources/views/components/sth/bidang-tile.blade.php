{{--
    Small bidang cover square with the bidang icon (lists, tables, "Lomba saya" cards). Decorative:
    the bidang name is always written next to it (chip or text).

    <x-sth.bidang-tile bidang="riset" />            48px (cards)
    <x-sth.bidang-tile :bidang="$c->bidang" size="sm" />  40px (table rows, .cv)

    Props: bidang (sains|riset|seni_budaya|seni|olahraga or Bidang enum) · size sm (40) | md (48) | lg (56)
--}}
@props(['bidang', 'size' => 'md'])
@php
    $enum = $bidang instanceof \App\Enums\Bidang
        ? $bidang
        : (is_string($bidang) ? \App\Enums\Bidang::tryFrom($bidang === 'seni' ? 'seni_budaya' : $bidang) : null);
    $key = $enum?->cssKey() ?? 'sains';
    [$box, $iconSize] = ['sm' => ['size-10 rounded-control', 20], 'md' => ['size-12 rounded-tile', 24], 'lg' => ['size-14 rounded-2xl', 28]][$size] ?? ['size-12 rounded-tile', 24];
@endphp
<span {{ $attributes->class(['cover-'.$key, 'inline-flex shrink-0 items-center justify-center', $box]) }} aria-hidden="true">
    <x-sth.icon :name="$key" :size="$iconSize" />
</span>
