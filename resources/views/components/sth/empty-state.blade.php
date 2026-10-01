{{--
    Empty list / no results. Short copy: what happened + what to do.

    <x-sth.empty-state icon="search" title="Belum ada lomba yang cocok">
        Coba ubah filter, atau usulkan lomba baru.
        <x-slot:actions>
            <x-sth.button variant="quiet" size="sm" wire:click="resetFilters">Hapus filter</x-sth.button>
            <x-sth.button variant="soft" size="sm" data-sth-open="usul">Usulkan lomba</x-sth.button>
        </x-slot:actions>
    </x-sth.empty-state>

    Props: icon · title · level (heading level, default 2) · card (wrap in .card, default true)
    Slots: default (one-line description) · actions
--}}
@props(['icon' => 'search', 'title', 'level' => 2, 'card' => true])
@php
    $heading = 'h'.min(max((int) $level, 2), 4);
@endphp
<div {{ $attributes->class(['flex flex-col items-center gap-3 px-6 py-12 text-center', 'card' => $card]) }}>
    <span class="inline-flex size-14 items-center justify-center rounded-2xl bg-gray-bg text-gray">
        <x-sth.icon :name="$icon" :size="26" />
    </span>
    <{{ $heading }} class="title-section">{{ $title }}</{{ $heading }}>
    @if ($slot->isNotEmpty())
        <p class="m-0 max-w-md text-[14.5px] text-muted">{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="mt-1 flex flex-wrap justify-center gap-2.5">{{ $actions }}</div>
    @endisset
</div>
