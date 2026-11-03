{{-- A chosen (not yet sent) upload: icon, name, size, remove button. $name, $size (bytes), $remove (wire:click), $removeLabel, $icon.
     Only for a file the screen accepted: a file refused when it is picked, and a single file the send refused, is dropped
     by its component (LaporHasil, ProfilTalenta), so its drop zone comes back with the reason instead of this row.
     The size text is the one rule of every screen (App\Support\FileSizeLabel: "2,0 MB", "1.023 KB"). --}}
@php($sizeText = \App\Support\FileSizeLabel::format((int) ($size ?? 0)))
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
