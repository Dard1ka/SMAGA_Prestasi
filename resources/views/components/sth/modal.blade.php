{{--
    Modal dialog, bottom sheet or side drawer on the native <dialog> (focus trap, Esc, inert page and
    top layer come from the browser). No inline JS; open/close through data attributes or events:

    <x-sth.button variant="soft" data-sth-open="usul" aria-haspopup="dialog">Usulkan lomba</x-sth.button>
    <x-sth.modal id="usul" title="Usulkan lomba" description="Kesiswaan meninjau usulanmu." variant="drawer">
        <form id="usul-form" wire:submit="kirim">…</form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="usul-form">Kirim usulan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    Livewire: $this->dispatch('sth-modal-open', id: 'usul') · $this->dispatch('sth-modal-close', id: 'usul')
    The <dialog> carries wire:ignore.self so a Livewire re-render keeps it open while its content updates.

    Props: id · title · description · variant dialog (centred) | sheet (bottom on phones) | drawer (right)
           size sm | md | lg (dialog/sheet widths 440/560/760) · open (open on page load, e.g. after a
           failed non-Livewire submit) · on-close (a method of the Livewire component around it, called
           whenever the dialog closes: a close button, Esc or a click outside; for a dialog whose state lives on
           the server, e.g. in the address, QA5 #63)
    Slots: default (body, scrolls) · footer (sticky actions, right-aligned)
--}}
@props(['id', 'title', 'description' => null, 'variant' => 'dialog', 'size' => 'md', 'open' => false, 'onClose' => null])
@php
    $variantClass = ['dialog' => null, 'sheet' => 'dlg-sheet', 'drawer' => 'dlg-drawer'][$variant] ?? null;
    $sizeClass = ['sm' => 'dlg-sm', 'md' => null, 'lg' => 'dlg-lg'][$size] ?? null;
@endphp
<dialog
    id="{{ $id }}"
    aria-labelledby="{{ $id }}-title"
    @if ($description) aria-describedby="{{ $id }}-desc" @endif
    wire:ignore.self
    @if ($open) data-sth-autoopen @endif
    @if ($onClose) data-sth-on-close="{{ $onClose }}" @endif
    {{ $attributes->class(['dlg', $variantClass, $sizeClass]) }}
>
    <div class="dlg-panel">
        <div class="dlg-head">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 id="{{ $id }}-title" class="title-section">{{ $title }}</h2>
                @if ($description)
                    <p id="{{ $id }}-desc" class="m-0 text-sm text-muted">{{ $description }}</p>
                @endif
            </div>
            <x-sth.icon-button icon="x" label="Tutup" size="sm" ghost data-sth-close />
        </div>
        <div class="dlg-body">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="dlg-foot">{{ $footer }}</div>
        @endisset
    </div>
</dialog>
