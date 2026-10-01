{{--
    Icon-only button or link (.icon-btn). `label` is required: it becomes the aria-label.

    <x-sth.icon-button icon="edit" label="Ubah FLS3N Kota Semarang 2026" size="sm" wire:click="edit('…')" />
    <x-sth.icon-button icon="bell" label="Notifikasi, 2 baru" dot href="{{ route('notifikasi.index') }}" />
    <x-sth.icon-button icon="x" label="Tutup" ghost data-sth-close />

    Props: icon · label (required) · href (renders <a>) · size md (40px) | sm (36px) · dot (unread dot)
           ghost (no border) · type (button) · disabled
--}}
@props([
    'icon',
    'label',
    'href' => null,
    'size' => 'md',
    'dot' => false,
    'ghost' => false,
    'type' => 'button',
    'disabled' => false,
])
@php
    $classes = ['icon-btn', 'icon-btn-sm' => $size === 'sm', 'icon-btn-ghost' => $ghost];
    $iconSize = $size === 'sm' ? 18 : 20;
@endphp
@if ($href !== null)
    <a @unless ($disabled) href="{{ $href }}" @else role="link" aria-disabled="true" @endunless aria-label="{{ $label }}" {{ $attributes->class($classes) }}>
        <x-sth.icon :name="$icon" :size="$iconSize" />
        @if ($dot)<span class="dot" aria-hidden="true"></span>@endif
    </a>
@else
    <button type="{{ in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button' }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }} @disabled($disabled)>
        <x-sth.icon :name="$icon" :size="$iconSize" />
        @if ($dot)<span class="dot" aria-hidden="true"></span>@endif
    </button>
@endif
