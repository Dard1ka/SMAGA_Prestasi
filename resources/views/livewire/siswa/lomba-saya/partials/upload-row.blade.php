{{-- A chosen (not yet sent) upload: icon, name, size, remove button. $name, $size (bytes), $remove (wire:click), $removeLabel, $icon. --}}
@php
    $kb = max(1, (int) round(((int) ($size ?? 0)) / 1024));
    $sizeText = $kb >= 1024 ? str_replace('.', ',', (string) round($kb / 1024, 1)).' MB' : $kb.' KB';
@endphp
<div class="flex items-center gap-3 rounded-tile border border-line bg-subtle p-3">
    <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-primary-tint text-primary" aria-hidden="true">
        <x-sth.icon :name="$icon ?? 'file'" />
    </span>
    <span class="flex min-w-0 flex-1 flex-col">
        <span class="ellip text-sm font-bold">{{ $name }}</span>
        <span class="muted num text-xs">Siap dikirim · {{ $sizeText }}</span>
    </span>
    <x-sth.icon-button icon="trash" :label="$removeLabel" size="sm" wire:click="{{ $remove }}" />
</div>
