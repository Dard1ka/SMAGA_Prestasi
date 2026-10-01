{{--
    Button, or a link styled as a button (DESIGN-SPEC-v2 §3 .btn). Buttons are verbs: "Simpan", "Daftar lomba".

    <x-sth.button>Simpan</x-sth.button>
    <x-sth.button type="submit" wire:loading.attr="disabled">Kirim</x-sth.button>
    <x-sth.button variant="quiet" size="sm" icon="plus" href="{{ route('…') }}">Tambah lomba</x-sth.button>
    <x-sth.button variant="soft" data-sth-open="usul" aria-haspopup="dialog">Usulkan lomba</x-sth.button>

    Props
    - variant     primary (default) | quiet | soft | danger | white (only on the blue hero/auth panel)
    - size        md (44px) | sm (36px, 44px on touch) | lg (50px, auth forms)
    - href        renders <a>; never put a <button> inside a link
    - icon        leading icon name (<x-sth.icon>), icon-right trailing icon name
    - type        button (default) | submit | reset — ignored for links
    - disabled    <button disabled> / <a aria-disabled="true"> without href
    - block       full width
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'iconRight' => null,
    'type' => 'button',
    'disabled' => false,
    'block' => false,
])
@php
    $variant = in_array($variant, ['primary', 'quiet', 'soft', 'danger', 'white'], true) ? $variant : 'primary';
    $classes = ['btn', 'btn-'.$variant, 'btn-sm' => $size === 'sm', 'btn-lg' => $size === 'lg', 'w-full' => $block];
    $iconSize = $size === 'sm' ? 18 : 20;
@endphp
@if ($href !== null)
    <a @unless ($disabled) href="{{ $href }}" @else role="link" aria-disabled="true" @endunless {{ $attributes->class($classes) }}>
        @if ($icon)<x-sth.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconRight)<x-sth.icon :name="$iconRight" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" {{ $attributes->class($classes) }} @disabled($disabled)>
        @if ($icon)<x-sth.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconRight)<x-sth.icon :name="$iconRight" :size="$iconSize" />@endif
    </button>
@endif
