{{--
    Pill label: status, bidang, gold (points/medals only) or glass (on covers). Always carries text —
    colour is never the only signal.

    <x-sth.chip tone="green" icon="check">Disetujui</x-sth.chip>
    <x-sth.chip tone="orange" icon="clock">Menunggu laporan · batas 24 Okt</x-sth.chip>
    <x-sth.chip bidang="riset" />                      → "Riset" (label from the bidang)
    <x-sth.chip :bidang="$competition->bidang" icon />  → Bidang enum + its bidang icon
    <x-sth.chip tone="gold" class="num">#14</x-sth.chip>

    Props
    - tone    green (done/approved) · orange (needs action/deadline) · red (rejected/error)
              gray (waiting/neutral, default) · blue (in progress/info) · gold · glass
    - bidang  sains | riset | seni_budaya (or seni) | olahraga | a Bidang enum → .b-* colours
    - icon    icon name; `icon` without a value on a bidang chip = the bidang icon
    - size    md (26px) | sm (24px)
--}}
@props(['tone' => 'gray', 'bidang' => null, 'icon' => null, 'size' => 'md'])
@php
    // App\Enums\Bidang is the source of labels and CSS keys ('seni' is accepted as short form).
    $bidangEnum = $bidang instanceof \App\Enums\Bidang
        ? $bidang
        : (is_string($bidang) ? \App\Enums\Bidang::tryFrom($bidang === 'seni' ? 'seni_budaya' : $bidang) : null);
    $bidangKey = $bidangEnum?->cssKey();
    $toneClass = $bidangKey !== null
        ? 'b-'.$bidangKey
        : 'c-'.(in_array($tone, ['green', 'orange', 'red', 'gray', 'blue', 'gold', 'glass'], true) ? $tone : 'gray');
    $iconName = $icon === true ? $bidangKey : $icon;
@endphp
<span {{ $attributes->class(['chip', $toneClass, 'chip-sm' => $size === 'sm']) }}>
    @if ($iconName)<x-sth.icon :name="$iconName" :size="14" />@endif
    @if ($slot->isEmpty() && $bidangEnum !== null){{ $bidangEnum->label() }}@else{{ $slot }}@endif
</span>
